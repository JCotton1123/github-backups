# github-backups

Back up your repos and any repos you have access to

## Configuration

Place the provided `.gh-backup` configuration file in your home directory or some alternate location and update as follows:

* `username` - your github username
* `token` - a personal access token
* `directory` - a location where the backups will be stored
* `filter` - if supplied, only repos matching this PCRE will be backed up

