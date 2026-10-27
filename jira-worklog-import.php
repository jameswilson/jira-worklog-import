<?php

/**
 * @file
 * Script to import a csv file with time logs to Jira.
 *
 * Jira credentials must be on an .env file in this directory, for example:
 * JIRA_HOST="https://<SUBDOMAIN>.atlassian.net"
 * JIRA_USER="you@company.com"
 * JIRA_PASS="<API token from https://id.atlassian.com/manage-profile/security/api-tokens>"
 * TOKEN_BASED_AUTH=false
 *
 * Jira Cloud: use Basic auth (TOKEN_BASED_AUTH false) with email + API token.
 * If TOKEN_BASED_AUTH=true, the client sends Bearer PERSONAL_ACCESS_TOKEN
 * (Data Center style), not JIRA_PASS — wrong mode yields Seraph
 * AUTHENTICATED_FAILED and a generic "issue does not exist" body.
 *
 * @todo Automatically fetch time from Timing.app.
 * @todo Use console library to have some console help and parameters.
 *       (ie: debug, source file)
 */

use Dotenv\Dotenv;
use JiraWorklogImport\Import\ImportRowParser;
use JiraRestApi\Configuration\DotEnvConfiguration;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\Issue\Worklog;
use JiraRestApi\JiraException;

$arguments = array_slice($argv, 1);
$unsupported_arguments = array_diff($arguments, ['--dry-run']);
if ($unsupported_arguments) {
  fwrite(STDERR, 'Unsupported argument(s): ' . implode(', ', $unsupported_arguments) . PHP_EOL);
  fwrite(STDERR, 'Usage: php jira-worklog-import.php [--dry-run]' . PHP_EOL);
  exit(2);
}
$dry_run = in_array('--dry-run', $arguments, TRUE);

// Learn from a qualifying reviewed Timing export before either dry-run parsing
// or remote filing. This local preflight deliberately has no Jira/Tempo client
// dependency, and a learning deferral does not change the legacy import flow.
require __DIR__ . '/src/ReviewLearning/bootstrap.php';
try {
  $learning_result = \JiraWorklogImport\ReviewLearning\ReviewLearningPreflight::run(__DIR__);
  foreach (\JiraWorklogImport\ReviewLearning\ReviewLearningPreflight::report($learning_result) as $learning_line) {
    print($learning_line . PHP_EOL);
  }
}
catch (Throwable $exception) {
  fwrite(STDERR, 'Review learning preflight deferred safely: ' . $exception->getMessage() . PHP_EOL);
}
fwrite(STDERR, 'WARNING: Jira worklog filing remains non-idempotent; local learning receipts do not prevent remote duplicates.' . PHP_EOL);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/Import/ImportRowParser.php';

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Read the same .env as this script regardless of process CWD (lesstif defaults to ".").
$issueService = new IssueService(new DotEnvConfiguration(__DIR__));

const DATE_FORMAT = DateTime::ATOM;
const DATE_TIMEZONE = 'America/Bogota';

const DEBUGGING = FALSE;

const INPUT_FILE = 'files/All Activities.json';

/** @see jira_preflight_auth() */
const JIRA_API_TOKEN_HELP_URL = 'https://id.atlassian.com/manage-profile/security/api-tokens';

$input_file = new SplFileInfo(INPUT_FILE);

write('');
write(str_repeat('=', 80));
write(' Jira Worklog Import');
write(' Input: ' . $input_file->getRealPath());
write(' Endpoint: ' . $_ENV['JIRA_HOST']);
write(' Date: ' . date('c'));
write(str_repeat('=', 80));

jira_preflight_auth($dry_run);

$file = file_get_contents(INPUT_FILE);

// Normalize line endings to Unix style
$file = str_replace(["\r\n", "\r"], "\n", $file);

$json = json_decode($file, FALSE, 512, JSON_THROW_ON_ERROR);

if (!is_array($json)) {
  throw new RuntimeException('Input must be a JSON array of worklog rows.');
}

$row_parser = new ImportRowParser(DATE_FORMAT, new DateTimeZone(DATE_TIMEZONE));

foreach ($json as $linenumber => $line) {

  debug($line);

  $row = $row_parser->parse($line, $linenumber + 1);
  if ($row->status === '🔴') {
    debug($row);
    log_row($row);
    continue;
  }
  $api_response = NULL;
  try {
    $workLog = new Worklog();

    $comment = str_replace('\n', "\n", $row->comment);

    $workLog->setComment($comment)
      ->setStarted($row->datetime)
      ->setTimeSpent($row->hours);

    // Do not submit work logs to Jira.
    if ($dry_run) {
      $row->status = "🕓";
      $row->status_message = "dry-run";
    }
    // Submit work log to Jira.
    else {
      $api_response = $issueService->addWorklog($row->issueKey, $workLog);
      $workLogId = $api_response->{'id'};
      $row->status = "🟢";
      $row->status_message = "logged ($workLogId)";
    }
  }
  catch (JiraException $e) {
    $row->status = "🔴";
    $row->status_message = "api error: " . jira_format_api_exception($e);
    debug($e);
  }
  debug($api_response);
  debug($row);
  log_row($row);
}

/**
 * Calls GET /rest/api/2/myself so failures surface as auth errors, not issue 404s.
 *
 * Jira often returns HTTP 404 and "Issue does not exist..." when login actually
 * failed (e.g. revoked API token). Seraph sets x-seraph-loginreason on failure.
 */
function jira_preflight_auth(bool $dry_run): void {
  if ($dry_run) {
    return;
  }
  $cookieAuth = filter_var($_ENV['COOKIE_AUTH_ENABLED'] ?? FALSE, FILTER_VALIDATE_BOOLEAN);
  if ($cookieAuth) {
    return;
  }

  $host = rtrim((string) ($_ENV['JIRA_HOST'] ?? ''), '/');
  if ($host === '') {
    return;
  }

  $url = $host . '/rest/api/2/myself';
  $seraph_reason = '';

  $headers = ['Accept: application/json'];
  $opts = [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_HEADERFUNCTION => static function ($ch, $header) use (&$seraph_reason): int {
      if (preg_match('/^x-seraph-loginreason:\\s*(.+)\\s*$/i', $header, $m)) {
        $seraph_reason = trim($m[1]);
      }
      return strlen($header);
    },
  ];

  $token_mode = filter_var($_ENV['TOKEN_BASED_AUTH'] ?? FALSE, FILTER_VALIDATE_BOOLEAN);
  if ($token_mode) {
    $headers[] = 'Authorization: Bearer ' . (string) ($_ENV['PERSONAL_ACCESS_TOKEN'] ?? '');
  }
  else {
    $user = (string) ($_ENV['JIRA_USER'] ?? '');
    $pass = (string) ($_ENV['JIRA_PASS'] ?? '');
    if ($user === '' || $pass === '') {
      return;
    }
    $opts[CURLOPT_USERPWD] = $user . ':' . $pass;
  }

  $opts[CURLOPT_HTTPHEADER] = $headers;

  $ch = curl_init();
  curl_setopt_array($ch, $opts);
  $body = curl_exec($ch);
  $errno = curl_errno($ch);
  $curl_error = curl_error($ch);
  $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($errno !== 0) {
    write('');
    write('Jira connection check failed before import (curl): ' . $curl_error);
    exit(1);
  }

  $seraph_failed = ($seraph_reason !== '' && stripos($seraph_reason, 'AUTHENTICATED_FAILED') !== FALSE);
  if ($http === 200 && !$seraph_failed) {
    return;
  }

  write('');
  write(str_repeat('=', 80));
  write(' Jira authentication failed (preflight: GET /rest/api/2/myself)');
  write(' URL: ' . $url);
  write(' HTTP status: ' . $http);
  if ($seraph_reason !== '') {
    write(' x-seraph-loginreason: ' . $seraph_reason);
  }
  $snippet = is_string($body) ? trim(substr($body, 0, 500)) : '';
  if ($snippet !== '') {
    write(' Response snippet: ' . $snippet);
  }
  write(str_repeat('-', 80));
  write(' Atlassian rejected these credentials (wrong password, revoked token,');
  write(' wrong email for the token, or TOKEN_BASED_AUTH / cookie settings).');
  write(' Jira often hides that behind HTTP 404 on other endpoints.');
  write('');
  write(' Fix for Jira Cloud (email + API token, Basic auth):');
  $env_path = __DIR__ . '/.env';
  write(' 1. Open: ' . JIRA_API_TOKEN_HELP_URL);
  write(' 2. Create a new API token (or replace one that was revoked).');
  write(' 3. In ' . $env_path . ': JIRA_USER = Atlassian account email; JIRA_PASS = that token;');
  write('    TOKEN_BASED_AUTH=false (unless you intentionally use Bearer PAT).');
  write(' 4. Save ' . $env_path . ' and run this script again.');
  write(str_repeat('=', 80));
  exit(1);
}

/**
 * Adds short hints when Jira's message is misleading (auth vs issue access).
 *
 * @param \JiraRestApi\JiraException $e
 *   Exception from the REST client.
 *
 * @return string
 *   Message safe for one-line log output.
 */
function jira_format_api_exception(JiraException $e): string {
  $base = $e->getMessage();
  $code = $e->getCode();
  $body = (string) ($e->getResponse() ?? '');

  $hints = [];
  if ($code === 401) {
    $hints[] = 'Renew API token: ' . JIRA_API_TOKEN_HELP_URL;
  }
  elseif ($code === 404 && str_contains($body, 'Issue does not exist or you do not have permission')) {
    $hints[] = 'This 404 often means invalid or revoked API credentials, not the issue key; preflight GET /myself would have caught it. Token help: ' . JIRA_API_TOKEN_HELP_URL;
  }

  return $base . (empty($hints) ? '' : ' | ' . implode(' ', $hints));
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
      ob_start();
      print(PHP_EOL);
      print("Debug 👷 = ");
      var_export($var);
      $result = ob_get_clean();
      write($result);
    }
  }
}

/**
 * Log row processing.
 *
 * @param Object $row
 *   The row object.
 */
function log_row(Object $row) {
  if (!empty($row)) {
    ob_start();
    print(implode(' | ', (array) $row));
    $result = ob_get_clean();
    write($result);
  }
}

/**
 * Write to stdout and a log file.
 *
 * @param string $string
 *   The string to print.
 */
function write($string) {
  print($string . PHP_EOL);
  $fp = fopen('files/jira-worklog-import.log', 'a');
  fwrite($fp, $string . PHP_EOL);
  fclose($fp);
}
