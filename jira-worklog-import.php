<?php

/**
 * Script to import a csv file with timelogs to Jira.
 *
 * Jira credentials must be on an .env file with this format:
 * JIRA_HOST="https://<SUBDOMAIN>.atlassian.net"
 * JIRA_USER=""
 * JIRA_PASS=""
 *
 * TODO: Automatically convert H:M:S worklog spent time into decimal.
 * TODO: Automatically fetch time from Toggl.
 * TODO: Use console library to have some console help and parameters. (ie: debug, testing, source file)
 */

require __DIR__ . '/vendor/autoload.php';

if (!ini_get("auto_detect_line_endings")) {
    ini_set("auto_detect_line_endings", '1');
}

use JiraRestApi\Issue\IssueService;
use JiraRestApi\Issue\Worklog;
use JiraRestApi\JiraException;

use League\Csv\Reader;

const DATE_FORMAT = 'n/j/y g:i A';
const DATE_TIMEZONE = 'America/Port-au-Prince';

// Change this to false to do a real import. Make sure column numbers are ok first.
const TESTING = FALSE;

$csv = Reader::createFromPath("OfficeTime Report.txt");
// OfficeTime creates tab separated value lists.
$delimiter = $csv->setDelimiter("\t");

/**
 * Use offset to skip the header row.
 * Use limit = 1 to test with just 1 row.
 */
$res = $csv->setOffset(1)->setLimit(100)->fetchAll();

foreach ($res as $line) {
    // Just debug print
    print_r($line);

    try {
        $datetime = $line[4];

        // Time spent, in hours (decimal format), eg 1.5 (hours)
        $hours = $line[5];

        // The description of the task.
        $comment = $line[8];

        // Parse the JIRA Issue Key, eg BSP-9, out of comment, supported formats:
        // BSP-9 - Timesheets
        // BSP-9 -   Timesheets
        // BSP-9: Timesheets
        // BSP-9 : Timesheets
        // BSP-9  :  Timesheets
        // BSP-9  Timesheets
        // https://regex101.com/r/WslRfW/2
        $result = preg_match_all('/^(\w+-\d+)(\s+)?(-|:)?(\s+)?(.+)/', $comment, $matches);
        if (!$result) {
            throw new RuntimeException("Could not find Issue Key in comment: '$comment'");
        }
        print_r($matches);

        $issueKey = $matches[1][0];

        $comment = $matches[5][0];

        if (!$comment) {
            throw new RuntimeException("Worklog comment missing.");
        }

        // Make sure timezone is correct, it can have an impact on the day the timelog is saved into.
        $date = DateTime::createFromFormat(DATE_FORMAT, $datetime, new DateTimeZone(DATE_TIMEZONE));

        if (!$date) {
            throw new RuntimeException("Could not parse date: '$datetime' for format '" . DATE_FORMAT . "'");
            var_dump(DateTime::getLastErrors());
        }

        $datetime = $date->format('Y-m-d H:i:s');

        // Just debug
        echo "DATETIME: $datetime ISSUE: $issueKey COMMENT: $comment SPENT: $hours\n";

        echo implode(', ', array($datetime, $issueKey, $comment, $hours)) . "h\n";
    } catch (RuntimeException $e) {
       echo 'ERROR: ' .$e->getMessage() . "\n";
       next;
    }
    try {
        $workLog = new Worklog();
        $workLog->setComment($comment)
            ->setStarted($date)
            ->setTimeSpent($hours . 'h');

        $issueService = new IssueService();

        // Use $testing to test the csv reading without sending to jira
        if (!TESTING) {
            $ret = $issueService->addWorklog($issueKey, $workLog);
            $workLogid = $ret->{'id'};

            // Show output from the api call
            var_dump($ret);
        }
        else {
            print_r($issueKey);
            print_r($workLog);
        }
    } catch (JiraException $e) {
        echo 'ERROR: ' .$e->getMessage() . "\n";
    }
}
