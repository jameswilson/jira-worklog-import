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

The version-controlled `bin/logtime` launcher runs the commands from the
repository root, regardless of the caller's working directory. Install it on
your `PATH` with a symlink or a minimal delegating shim, then use:

```
logtime --dry-run
logtime --learn-only
```

`--learn-only` runs `php review-learning.php` without loading or invoking the
Jira importer. Any arguments after `--learn-only` are forwarded to that
learning command. All other arguments are forwarded unchanged to
`php jira-worklog-import.php`.

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

The importer first runs a local review-learning preflight against every covered
manifest in `worklogs/learning/proposals/`. It reads current Timing entries,
records only unambiguous reviewed outcomes, and never contacts Jira or Tempo.
Completed outcomes are stored under `worklogs/learning/reviews/`; proposal
manifests remain unchanged as the before-state evidence for each run.
Run that component by itself with:

```
php review-learning.php
```

Learning receipts are separate from remote filing receipts. Remote Jira filing
is still non-idempotent and can create duplicates if the importer is rerun.

* Look at output to see if columns were parsed correctly.
* Only when submission is explicitly authorized, omit `--dry-run` to perform
  the final Jira import:
```
php jira-worklog-import.php
```
