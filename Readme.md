# JSON to Jira

This script allows to import a JSON file containing worklogs into Jira.

### Install:

```
git clone https://github.com/peterlozano/jira-worklog-import.git
cd jira-worklog-import
composer install
```

Create a .env file at least with the following info:

```
JIRA_HOST="https://<SUBDOMAIN>.atlassian.net"
JIRA_USER=""
JIRA_PASS=""
```

### Usage:

* Make sure the json file contains the following fields:
  * Date
  * Jira issue key
  * Description of the worklog
  * Time spent (in `HH:MM:SS` format)

* Adjust column numbers manually in script.

* Do a dry run and review how every row is parsed. This does not contact Jira:
```
php jira-worklog-import.php --dry-run
```

* Look at output to see if columns were parsed correctly.
* Only when submission is explicitly authorized, omit `--dry-run` to perform
  the final Jira import:
```
php jira-worklog-import.php
```
