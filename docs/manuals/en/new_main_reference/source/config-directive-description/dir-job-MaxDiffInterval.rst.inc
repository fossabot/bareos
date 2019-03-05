The time specifies the maximum allowed age (counting from start time) of the most recent successful Differential backup that is required in order to run Incremental backup jobs. If the most recent Differential backup is older than this interval, Incremental backups will be upgraded to Differential backups automatically. If this directive is not present, or specified as 0, then the age of the previous Differential backup is not considered.
