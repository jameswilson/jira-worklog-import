<?php

/**
 * @file
 * Script to import a csv file with time logs to Jira.
 *
 * Jira credentials must be on an .env file with this format:
 * JIRA_HOST="https://<SUBDOMAIN>.atlassian.net"
 * JIRA_USER=""
 * JIRA_PASS=""
 *
 * TODO: Automatically convert H:M:S work log spent time into decimal.
 * TODO: Automatically fetch time from Toggl.
 * TODO: Use console library to have some console help and parameters.
 *       (ie: debug, testing, source file)
 */

require __DIR__ . '/vendor/autoload.php';

if (!ini_get("auto_detect_line_endings")) {
  ini_set("auto_detect_line_endings", '1');
}

use JiraRestApi\Issue\ContentField;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\Issue\Worklog;
use JiraRestApi\JiraException;

use League\Csv\Reader;

const DATE_FORMAT = 'n/j/y g:i A';
const DATE_TIMEZONE = 'America/Port-au-Prince';

const DRY_RUN = FALSE; // Switch to false to do a real import.
const DEBUGGING = FALSE; // Switch to true to see debug output in the console.

$csv = Reader::createFromPath("OfficeTime Report.txt");

$input_bom = $csv->getInputBOM();
if (
  $input_bom === Reader::BOM_UTF16_LE ||
  $input_bom === Reader::BOM_UTF16_BE
) {
  print "Converting CSV from UTF-16 to UTF-8\n";
  $csv->appendStreamFilter('convert.iconv.UTF-16/UTF-8');
}

// OfficeTime creates tab separated value lists.
$delimiter = $csv->setDelimiter("\t");

// Use offset to skip the header row.
// Use limit = 1 to test with just 1 row.
$res = $csv->setOffset(1)->setLimit(200)->fetchAll();

foreach ($res as $linenumber => $line) {

  debug($line);

  print "| " . ($linenumber + 1) . " | ";

  // Detect and skip invalid lines.
  if (empty($line[0])) {
    print "⚠️  skipped: " . implode(', ', $line) . " |\n";
    continue;
  }

  try {
    $datetime = $line[4];

    // Time spent, in hours (decimal format), eg 1.5 (hours)
    $hours = $line[6];

    // The description of the task.
    $comment = $line[9];

    // Parse the JIRA Issue Key, eg BSP-9, out of comment, supported formats:
    // BSP-9 - Timesheets
    // BSP-9 -   Timesheets
    // BSP-9: Timesheets
    // BSP-9 : Timesheets
    // BSP-9  :  Timesheets
    // BSP-9  Timesheets
    // BSP-9. Timesheets
    // https://regex101.com/r/WslRfW/3
    $result = preg_match_all('/^(\w+-\d+)(\s+)?(-|:|\.)?(\s+)?(.+)/', $comment, $matches);

    if (!$result) {
      debug($matches);
      throw new RuntimeException("Could not find Issue Key in comment: '$comment'");
    }

    $issueKey = $matches[1][0];

    $comment = $matches[5][0];

    if (!$comment) {
      throw new RuntimeException("Worklog comment is required.");
    }

    // Make sure timezone is correct, it can have an impact
    // on the day the time log is saved into.
    $date = DateTime::createFromFormat(DATE_FORMAT, $datetime, new DateTimeZone(DATE_TIMEZONE));

    if (!$date) {
      debug(DateTime::getLastErrors());
      throw new RuntimeException("Could not parse date: '$datetime' for format '" . DATE_FORMAT . "'");
    }

    $datetime = $date->format('Y-m-d H:i:s');

  } catch (Exception $e) {
     log_error($e->getMessage());
     continue;
  }
  try {
    $workLog = new Worklog();

    $paragraph = new ContentField();
    $paragraph->type = "paragraph";
    $paragraph->content[] = [
      "text" => $comment,
      "type" => "text",
    ];
    $document = new ContentField();
    $document->type = "doc";
    $document->version = 1;
    $document->content[] = $paragraph;

    $workLog->setComment($document)
      ->setStarted($date)
      ->setTimeSpent($hours . 'h');

    $issueService = new IssueService();

    // Do not submit worklogs to Jira.
    if (DRY_RUN) {
      print "🕑  dry-run | ${issueKey} | ${datetime} | ${hours}h | ${comment} |\n";
    }
    // Submit worklog to Jira.
    else {
      $ret = $issueService->addWorklog($issueKey, $workLog);
      $workLogid = $ret->{'id'};
      print "✅  logged ($workLogid) | ${issueKey} | ${datetime} | ${hours}h | ${comment} |\n";
      // Show output from the api call.
      debug($ret);
    }
  }
  catch (JiraException $e) {
    log_error($e->getMessage());
  }
}

/**
 * Print debug output to console.
 *
 * @param string $var
 *   The debug message.
 */
function debug($var = '') {
  if (DEBUGGING) {
    if (!empty($var)) {
      print("\n");
      print("👷  = ");
      var_export($var);
      print("\n");
      print("\n");
    }
  }
}

/**
 * Log errors to console.
 *
 * @param string $var
 *   The error message string.
 */
function log_error($var = '') {
  if (!empty($var)) {
    print("\n");
    print("🛑  ️");
    print_r($var);
    print("\n");
  }
}
