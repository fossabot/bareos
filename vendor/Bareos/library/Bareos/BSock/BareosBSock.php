<?php

/**
 *
 * bareos-webui - Bareos Web-Frontend
 *
 * @link	  https://github.com/bareos/bareos-webui for the canonical source repository
 * @copyright Copyright (c) 2014 Bareos GmbH & Co. KG
 * @license   GNU Affero General Public License (http://www.gnu.org/licenses/)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace Bareos\BSock;

class BareosBSock
{
	const BNET_TLS_NONE = 0;	/* cannot do TLS */
	const BNET_TLS_OK = 1;		/* can do, but not required on my end */
	const BNET_TLS_REQUIRED = 2;	/* TLS is required */

	const BNET_EOD = -1;		/* End of data stream, new data may follow */
	const BNET_EOD_POLL = -2;	/* End of data and poll all in one */
	const BNET_STATUS = -3;		/* Send full status */
	const BNET_TERMINATE = -4;	/* Conversation terminated, doing close() */
	const BNET_POLL = -5;		/* Poll request, I'm hanging on a read */
	const BNET_HEARTBEAT = -6;	/* Heartbeat Response requested */
	const BNET_HB_RESPONSE = -7;	/* Only response permited to HB */
	const BNET_xxxxxxPROMPT = -8;	/* No longer used -- Prompt for subcommand */
	const BNET_BTIME = -9;		/* Send UTC btime */
	const BNET_BREAK = -10;		/* Stop current command -- ctl-c */
	const BNET_START_SELECT = -11;	/* Start of a selection list */
	const BNET_END_SELECT = -12;	/* End of a select list */
	const BNET_INVALID_CMD = -13;	/* Invalid command sent */
	const BNET_CMD_FAILED = -14;	/* Command failed */
	const BNET_CMD_OK = -15;	/* Command succeeded */
	const BNET_CMD_BEGIN = -16;	/* Start command execution */
	const BNET_MSGS_PENDING = -17;	/* Messages pending */
	const BNET_MAIN_PROMPT = -18;	/* Server ready and waiting */
	const BNET_SELECT_INPUT = -19;	/* Return selection input */
	const BNET_WARNING_MSG = -20;	/* Warning message */
	const BNET_ERROR_MSG = -21;	/* Error message -- command failed */
	const BNET_INFO_MSG = -22;	/* Info message -- status line */
	const BNET_RUN_CMD = -23;	/* Run command follows */
	const BNET_YESNO = -24;		/* Request yes no response */
	const BNET_START_RTREE = -25;	/* Start restore tree mode */
	const BNET_END_RTREE = -26;	/* End restore tree mode */
	const BNET_SUB_PROMPT = -27;	/* Indicate we are at a subprompt */
	const BNET_TEXT_INPUT = -28;	/* Get text input from user */

	const DIR_OK_AUTH = "1000 OK auth\n";
	const DIR_AUTH_FAILED = "1999 Authorization failed.\n";

	protected $config = array(
		'debug' => false,
		'host' => null,
		'port' => null,
		'password' => null,
		'console_name' => null,
		'tls_verify_peer' => null,
		'server_can_do_tls' => null,
		'server_requires_tls' => null,
		'client_can_do_tls' => null,
		'client_requires_tls' => null,
		'ca_file' => null,
		'cert_file' => null,
		'cert_file_passphrase' => null,
		'allowed_cns' => null,
	);

	private $socket = null;

	/**
	 * Initialize the connection
	 */
	public function init()
	{
		if(self::connect()) {
			return true;
		} else {
			return false;
		}
	}

	private function set_config_keyword($setting, $key)
	{
		if (array_key_exists($key, $this->config)) {
			$this->config[$key] = $setting;
		} else {
			throw new \Exception("Illegal parameter $key in /config/autoload/local.php");
		}
	}

	/**
	 * Set the connection configuration
	 *
	 * @param $config
	 */
	public function set_config($config)
	{
		array_walk($config, array('self', 'set_config_keyword'));

		if(empty($config['host'])) {
			throw new \Exception("Missing parameter 'host' in /config/autoload/local.php");
		}

		if(empty($config['port'])) {
			throw new \Exception("Missing parameter 'port' in /config/autoload/local.php");
		}

		if(empty($config['password'])) {
			throw new \Exception("Missing parameter 'password' in /config/autoload/local.php");
		}

		if($this->config['debug']) {
			var_dump($this->config);
		}
	}

	/**
	 * Network to host length
	 *
	 * @param $buffer
	 * @return int
	 */
	private function ntohl($buffer)
	{
		$len = array();

		$len = unpack('N', $buffer);
		$actual_length = (float) $len[1];

		if($actual_length > (float)2147483647) {
			$actual_length -= (float)"4294967296";
		}

		return (int) $actual_length;
	}

	/**
	 * Replace spaces in a string with the special escape character ^A which is used
	 * to send strings with spaces to specific director commands.
	 *
	 * @param $str
	 * @return string
	 */
	private function bash_spaces($str)
	{
		$length = strlen($str);
		$bashed_str = "";

		for($i = 0; $i < $length; $i++) {
			if($str[$i] == ' ') {
				$bashed_str .= '^A';
			} else {
				$bashed_str .= $str[$i];
			}
		}

		return $bashed_str;
	}

	/**
	 * Send a string over the console socket.
	 * Encode the length as the first 4 bytes of the message and append the string.
	 *
	 * @param $msg
	 * @return boolean
	 */
	private function send($msg)
	{
		$str_length = 0;
		$str_length = strlen($msg);
		$msg = pack('N', $str_length) . $msg;
		$str_length += 4;

		while($str_length > 0) {
			$send = fwrite($this->socket, $msg, $str_length);
			if($send === false) {
				return false;
			} elseif($send < $str_length) {
				$msg = substr($msg, $send);
				$str_length -= $send;
			} else {
				return true;
			}
		}
	}

	/**
	 * Receive a string over the console socket.
	 * First read first 4 bytes which encoded the length of the string and
	 * the read the actual string.
	 *
	 * @return string
	 */
	private function receive($len=0)
	{
		$buffer = "";
		$msg_len = array();

		if ($len == 0) {
			$buffer = fread($this->socket, 4);
			if($buffer === false){
				return false;
			}
			$msg_len = unpack('N', $buffer);
		} else {
			$msg_len[1] = $len;
		}

		if ($msg_len[1] > 0) {
			$buffer = fread($this->socket, $msg_len[1]);
		}

		return $buffer;
	}

	/**
	 * Special receive function that also knows the different so called BNET signals the
	 * Bareos director can send as part of the data stream.
	 *
	 * @return string
	 */
	private function receive_message()
	{
		$msg = "";
		$buffer = "";

		while (true) {
			$buffer = fread($this->socket, 4);

			if ($buffer === false) {
				throw new \Exception("Error reading socket. " . socket_strerror(socket_last_error()) . "\n");
			}

			$len = self::ntohl($buffer);

			if ($len == 0) {
				break;
			}

			if ($len > 0 && $len < 1000000) {
				$buffer = fread($this->socket, $len);
				$msg .= $buffer;
			} elseif ($len < 0) {
				// signal received
				switch ($len) {
					case self::BNET_EOD:
						if($this->config['debug']) {
							echo "Got BNET_EOD\n";
						}
						return $msg;
					case self::BNET_EOD_POLL:
						break;
					case self::BNET_STATUS:
						break;
					case self::BNET_TERMINATE:
						break;
					case self::BNET_POLL:
						break;
					case self::BNET_HEARTBEAT:
						break;
					case self::BNET_HB_RESPONSE:
						break;
					case self::BNET_xxxxxxPROMPT:
						break;
					case self::BNET_BTIME:
						break;
					case self::BNET_BREAK:
						break;
					case self::BNET_START_SELECT:
						break;
					case self::BNET_END_SELECT:
						break;
					case self::BNET_INVALID_CMD:
						break;
					case self::BNET_CMD_FAILED:
						break;
					case self::BNET_CMD_OK:
						break;
					case self::BNET_CMD_BEGIN:
						break;
					case self::BNET_MSGS_PENDING:
						break;
					case self::BNET_MAIN_PROMPT:
						if($this->config['debug']) {
							echo "Got BNET_MAIN_PROMPT\n";
						}
						return $msg;
					case self::BNET_SELECT_INPUT:
						break;
					case self::BNET_WARNING_MSG:
						break;
					case self::BNET_ERROR_MSG:
						break;
					case self::BNET_INFO_MSG:
						break;
					case self::BNET_RUN_CMD:
						break;
					case self::BNET_YESNO:
						break;
					case self::BNET_START_RTREE:
						break;
					case self::BNET_END_RTREE:
						break;
					case self::BNET_SUB_PROMPT:
						if($this->config['debug']) {
							echo "Got BNET_SUB_PROMPT\n";
						}
						return $msg;
					case self::BNET_TEXT_INPUT:
						break;
					default:
						throw new \Exception("Received unknown signal " . $len . "\n");
						break;
				}
			} else {
				throw new \Exception("Received illegal packet of size " . $len . "\n");
			}
		}

		return $msg;
	}


	/**
	 * Connect to a Bareos Director, authenticate the session and establish TLS if needed.
	 *
	 * @return boolean
	 */
	private function connect()
	{
		if (!isset($this->config['host']) or !isset($this->config['port'])) {
			return false;
		}

		$port = $this->config['port'];
		$remote = "tcp://" . $this->config['host'] . ":" . $port;

		$context = stream_context_create();

		/*
		 * It only makes sense to setup the whole TLS context when we as client support or
		 * demand a TLS connection.
		 */
		if ($this->config['client_can_do_tls'] || $this->config['client_requires_tls']) {
			/*
			 * We verify the peer ourself so the normal stream layer doesn't need to.
			 * But that does mean we need to capture the certficate.
			 */
			$result = stream_context_set_option($context, 'ssl', 'verify_peer', false);
			$result = stream_context_set_option($context, 'ssl', 'capture_peer_cert', true);

			/*
			 * Setup a CA file
			 */
			if (!empty($this->config['ca_file'])) {
				$result = stream_context_set_option($context, 'ssl', 'cafile', $this->config['ca_file']);
				if ($this->config['tls_verify_peer']) {
					$result = stream_context_set_option($context, 'ssl', 'verify_peer', true);
				}
			} else {
				$result = stream_context_set_option($context, 'ssl', 'allow_self_signed', true);
			}

			/*
			 * Cert file which needs to contain the client certificate and the key in PEM encoding.
			 */
			if (!empty($this->config['cert_file'])) {
				$result = stream_context_set_option($context, 'ssl', 'local_cert', $this->config['cert_file']);

				/*
				 * Passphrase needed to unlock the above cert file.
				 */
				if (!empty($this->config['cert_file_passphrase'])) {
					$result = stream_context_set_option($context, 'ssl', 'passphrase', $this->config['cert_file_passphrase']);
				}
			}
		}

		$this->socket = stream_socket_client($remote, $error, $errstr, 60,
						     STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT, $context);
		if (!$this->socket) {
			throw new \Exception("Error Connecting Socket: " . $errstr . "\n");
		}

		if($this->config['debug']) {
			echo "Connected to " . $this->config['host'] . " on port " . $this->config['port'] . "\n";
		}

		if (!self::login()) {
			return false;
		}

		if (($this->config['server_can_do_tls'] || $this->config['server_requires_tls']) &&
			($this->config['client_can_do_tls'] || $this->config['client_requires_tls'])) {
			$result = stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
			if (!$result) {
				throw new \Exception("Error in TLS handshake\n");
			}
		}

		if ($this->config['tls_verify_peer']) {
			if (!empty($this->config['allowed_cns'])) {
				if (!self::tls_postconnect_verify_cn()) {
					throw new \Exception("Error in TLS postconnect verify CN\n");
				}
			} else {
				if (!self::tls_postconnect_verify_host()) {
					throw new \Exception("Error in TLS postconnect verify host\n");
				}
			}
		}

		/*
		 * Get the 1000 OK: xx-dir Version: ...
		 */
		$recv = self::receive();

		if($this->config['debug']) {
			echo($recv);
		}

		return true;
	}

	/**
	 * Disconnect a connected console session
	 *
	 * @return boolean
	 */
	private function disconnect()
	{
		fclose($this->socket);
		if($this->config['debug']) {
			echo "Connection to " . $this->config['host'] . " on port " . $this->config['port'] . " closed\n";
		}
		return true;
	}

	/**
	 * Login into a Bareos Director e.g. authenticate the console session
	 *
	 * @return boolean
	 */
	private function login()
	{
		if(isset($this->config['console_name'])) {
			$bashed_console_name = self::bash_spaces($this->config['console_name']);
			$DIR_HELLO = "Hello " . $bashed_console_name . " calling\n";
		} else {
			$DIR_HELLO = "Hello *UserAgent* calling\n";
		}

		self::send($DIR_HELLO);
		$recv = self::receive();

		self::cram_md5_response($recv, $this->config['password']);
		$recv = self::receive();

		if(strncasecmp($recv, self::DIR_AUTH_FAILED, strlen(self::DIR_AUTH_FAILED)) == 0) {
			throw new \Exception("Failed to authenticate with Director\n");
		} elseif(strncasecmp($recv, self::DIR_OK_AUTH, strlen(self::DIR_OK_AUTH)) == 0) {
			return self::cram_md5_challenge($this->config['password']);
		} else {
			throw new \Exception("Unknown response to authentication by Director $recv\n");
		}

	}

	/**
	 * Verify the CN of the certificate against a list of allowed CN names.
	 *
	 * @return boolean
	 */
	private function tls_postconnect_verify_cn()
	{
		$options = stream_context_get_options($this->socket);

		if (isset($options['ssl']) && isset($options['ssl']['peer_certificate'])) {
			$cert_data = openssl_x509_parse($options["ssl"]["peer_certificate"]);

			if ($this->config['debug']) {
				print_r($cert_data);
			}

			if (isset($cert_data['subject']['CN'])) {
				$common_names = $cert_data['subject']['CN'];
				if ($this->config['debug']) {
					echo("CommonNames: " . $common_names . "\n");
				}
			}

			if (isset($common_names)) {
				$checks = explode(',', $common_names);

				foreach($checks as $check) {
					$allowed_cns = explode(',', $this->config['allowed_cns']);
					foreach($allowed_cns as $allowed_cn) {
						if (strcasecmp($check, $allowed_cn) == 0) {
							return true;
						}
					}
				}
			}
		}

		return false;
	}

	/**
	 * Verify TLS names
	 *
	 * @param $names
	 * @return boolean
	 */
	private function verify_tls_name($names)
	{
		$hostname = $this->config['host'];
		$checks = explode(',', $names);

		$tmp = explode('.', $hostname);
		$rev_hostname = array_reverse($tmp);
		$ok = false;

		foreach($checks as $check) {
			$tmp = explode(':', $check);

			/*
			 * Candidates must start with DNS:
			 */
			if ($tmp[0] != 'DNS') {
				continue;
			}

			/*
			 * and have something afterwards
			 */
			if (!isset($tmp[1])) {
				continue;
			}

			$tmp = explode('.', $tmp[1]);

			/*
			 * "*.com" is not a valid match
			 */
			if (count($tmp) < 3) {
				continue;
			}

			$cand = array_reverse($tmp);
			$ok = true;

			foreach($cand as $i => $item) {
				if (!isset($rev_hostname[$i])) {
					$ok = false;
					break;
				}

				if ($rev_hostname[$i] == $item) {
					continue;
				}

				if ($item == '*') {
					break;
				}
			}

			if ($ok) {
				break;
			}
		}

		return $ok;
	}

	/**
	 * Verify the subjectAltName or CN of the certificate against the hostname we are connecting to.
	 *
	 * @return boolean
	 */
	private function tls_postconnect_verify_host()
	{
		$options = stream_context_get_options($this->socket);

		if (isset($options['ssl']) && isset($options['ssl']['peer_certificate'])) {
			$cert_data = openssl_x509_parse($options["ssl"]["peer_certificate"]);

			if ($this->config['debug']) {
				print_r($cert_data);
			}

			/*
			 * Check subjectAltName extensions first.
			 */
			if (isset($cert_data['extensions'])) {
				if (isset($cert_data['extensions']['subjectAltName'])) {
					$alt_names = $cert_data['extensions']['subjectAltName'];
					if ($this->config['debug']) {
						echo("AltNames: " . $alt_names . "\n");
					}

					if (self::verify_tls_name($alt_names)) {
						return true;
					}
				}
			}

			/*
			 * Try verifying against the subject name.
			 */
			if (isset($cert_data['subject']['CN'])) {
				$common_names = "DNS:" . $cert_data['subject']['CN'];
				if ($this->config['debug']) {
					echo("CommonNames: " . $common_names . "\n");
				}

				if (self::verify_tls_name($common_names)) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Perform a CRAM MD5 response
	 *
	 * @param $recv
	 * @param $password
	 * @return boolean
	 */
	private function cram_md5_response($recv, $password)
	{
		list($chal, $ssl) = sscanf($recv, "auth cram-md5 %s ssl=%d");

		switch($ssl) {
			case self::BNET_TLS_OK:
				$this->config['server_can_do_tls'] = true;
				break;
			case self::BNET_TLS_REQUIRED:
				$this->config['server_requires_tls'] = true;
				break;
			default:
				$this->config['server_can_do_tls'] = false;
				$this->config['server_requires_tls'] = false;
				break;
		}

		$m = hash_hmac('md5', $chal, md5($password), true);
		$msg = rtrim(base64_encode($m), "=");

		self::send($msg);

		return true;
	}

	/**
	 * Perform a CRAM MD5 challenge
	 *
	 * @param $password
	 * @return boolean
	 */
	private function cram_md5_challenge($password)
	{
		$rand = rand(1000000000, 9999999999);
		$time = time();
		$clientname = "php-bsock";
		$client = "<" . $rand . "." . $time . "@" . $clientname . ">";

		if($this->config['client_requires_tls']) {
			$DIR_AUTH = sprintf("auth cram-md5 %s ssl=%d\n", $client, self::BNET_TLS_REQUIRED);
		} elseif($this->config['client_can_do_tls']) {
			$DIR_AUTH = sprintf("auth cram-md5 %s ssl=%d\n", $client, self::BNET_TLS_OK);
		} else {
			$DIR_AUTH = sprintf("auth cram-md5 %s ssl=%d\n", $client, self::BNET_TLS_NONE);
		}

		if(self::send($DIR_AUTH) == true) {
			$recv = self::receive();
			$m = hash_hmac('md5', $client, md5($password), true);

			$b64 = new BareosBase64();
			$msg = rtrim( $b64->encode($m, false), "=" );

			if (self::send(self::DIR_OK_AUTH) == true && strcmp(trim($recv), trim($msg)) == 0) {
				return true;
			} else {
				return false;
			}
		} else {
			return false;
		}

	}

	/**
	 * Send a single command
	 *
	 * @param $cmd
	 * @return string
	 */
	public function send_command($cmd)
	{
		$result = "";
		if(self::send($cmd)) {
			$result = self::receive_message();
			self::disconnect();
		}
		return $result;
	}

}
?>
