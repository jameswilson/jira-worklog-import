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
 * @todo Automatically fetch time from Timing.app.
 *       (ie: debug, testing, source file)
 */

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Application;

$application = new Application();

$application->add(new \App\Command\ImportCommand());

$application->run();
