<?php

namespace App\Command;

use App\Config\ImportConfig;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\Issue\Worklog;
use JiraRestApi\JiraException;
use League\Csv\Reader;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The Symfony console default import command.
 */
class ImportCommand extends Command {
    /**
     * @var \App\Config\ImportConfig
     */
    private $config;

    /**
     * Configures the current command.
     *
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure() {
        // Loads import config from .env file.
        $this->config = new ImportConfig();

        $this
            ->setName('app:import')
            ->setDescription('Import data.')
            ->addArgument('filename', InputArgument::REQUIRED, 'The filename to import from.')
            ->addOption('dry-run', 'dr', InputOption::VALUE_NONE, 'Do not import the time logs; Test run only.')
            ->addOption('date-format', 'df', InputOption::VALUE_REQUIRED, 'Specify a custom date format in the csv.', $this->config->getDateFormat())
            ->addOption('date-timezone', 'tz', InputOption::VALUE_REQUIRED, 'Specify a custom timezone in the csv.', $this->config->getDateTimezone())
            ->addOption('csv-delimiter', 'dl', InputOption::VALUE_REQUIRED, 'Specify the csv delimiter.', $this->config->getCsvDelimiter())
            ->addOption('offset', 'of', InputOption::VALUE_REQUIRED, 'Number of rows in the csv to skip', $this->config->getOffset())
            ->addOption('limit', 'li', InputOption::VALUE_REQUIRED, 'Number of rows in the csv to import', $this->config->getLimit())
            ->addOption('debug', 'd', InputOption::VALUE_NONE, 'Debug mode');
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface   $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int|null|void
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     * @throws \InvalidArgumentException
     * @throws \JsonMapper_Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output) {

        $this->config->loadOptions($input);
        $input_file = $input->getArgument('filename') ?? 'files/All Activities.json';

        // @TODO: support JSON and CSV based on filename extension
        ///////////////////////////////////////////////////////////
        //   JSON
        ///////////////////////////////////////////////////////////
        $this->write('');
        $this->write(str_repeat('=', 80));
        $this->write(' Jira Worklog Import');
        $this->write(' Input: ' . $input_file->getRealPath());
        $this->write(' Endpoint: ' . $_ENV['JIRA_HOST']);
        $this->write(' Date: ' . date('c'));
        $this->write(str_repeat('=', 80));

        $file = file_get_contents($input_file);

        // Normalize line endings to Unix style
        $file = str_replace(["\r\n", "\r"], "\n", $file);

        $json = json_decode($file);
        foreach ($json as $linenumber => $line) {

            $this->debug($line);

            try {

                if (empty($line->notes) && !empty($line->title)) {
                    $line->notes = $line->title;
                } elseif (empty($line->notes) && empty($line->title) && !empty($line->project)) {
                    $line->notes = $line->project;
                }

                $row = new \stdClass();
                $row->line = $linenumber + 1;
                $row->status = '🟠';
                $row->status_message = 'parsing';
                $row->project = $line->project;
                $row->issueKey = $this->parse_key($line->title) ?? $this->parse_key($line->project) ?? $this->parse_key($line->notes) ?? '';
                $row->hours = $this->jira_hours_format($line->duration);
                $row->datetime = $line->startDate;
                $row->comment = $this->parse_comment($line->notes) ?? $this->parse_comment($line->title) ?? $this->parse_comment($line->project) ?? '';

                if (empty($row->issueKey)) {
                    $row->status = '🔴';
                    $row->issueKey = '⭕ ' . $line->title;
                }

                if (empty($row->comment)) {
                    $row->status = '🔴';
                    $row->comment = '⭕ A worklog comment is required.';
                }
                $row->comment = str_replace("\n", '\n', $row->comment);

                // Make sure timezone is correct, it can have an impact
                // on the day the time log is saved into.
                $date_format = $this->config->get('date-format');
                $timezone = $this->config->get('date-timezone');
                $date = \DateTime::createFromFormat($date_format, $row->datetime, new \DateTimeZone($timezone));
                if (!$date) {
                    $this->debug(\DateTime::getLastErrors());
                    $row->status = '🔴';
                    $row->datetime = '⭕ ' . $row->datetime . " fmt: '" . $date_format . "'";
                }
                $row->datetime = $date->format('Y-m-d H:i:s');

                if ($row->status == '🔴') {
                    throw new \RuntimeException('skipped');
                }
            } catch (\Exception $e) {
                $row->status = "🔴";
                $row->status_message = $e->getMessage();
                $this->debug($row);
                $this->log_row($row);
                continue;
            }
            try {
                $workLog = new Worklog();

                $comment = str_replace('\n', "\n", $row->comment);

                $workLog->setComment($comment)
                ->setStarted($row->datetime)
                ->setTimeSpent($row->hours);

                $issueService = new IssueService();

                // Do not submit work logs to Jira.
                if ($input->getOption('dry-run')) {
                    $row->status = "🕓";
                    $row->status_message = "dry-run";
                    $api_response = NULL;
                }
                // Submit work log to Jira.
                else {
                    $api_response = $issueService->addWorklog($row->issueKey, $workLog);
                    $workLogId = $api_response->{'id'};
                    $row->status = "🟢";
                    $row->status_message = "logged ($workLogId)";
                }
            } catch (JiraException $e) {
                $row->status = "🔴";
                $row->status_message = "api error: " . $e->getMessage();
                $this->debug($e);
            }
            $this->debug($api_response);
            $this->debug($row);
            $this->log_row($row);
        }


        ///////////////////////////////////////////////////////////
        //   CSV
        ///////////////////////////////////////////////////////////
#        $csv = Reader::createFromPath();
#
#        $input_bom = $csv->getInputBOM();
#        if ($input_bom === Reader::BOM_UTF16_LE || $input_bom === Reader::BOM_UTF16_BE) {
#            $output->writeln('Converting CSV from UTF-16 to UTF-8\n');
#            $csv->appendStreamFilter('convert.iconv.UTF-16/UTF-8');
#        }
#
#        $res = $csv
#            ->setDelimiter($this->config->getCsvDelimiter())
#            ->setOffset($this->config->getOffset())
#            ->setLimit($this->config->getLimit())
#            ->fetchAll();
#
#        foreach ($res as $line) {
#            if ($this->config->getDebug()) {
#                print_r($line);
#            }
#
#            if (!empty($line[1])) {
#                // TODO: make CSV column mapping configurable, or detect from header row.
#                $date_value = $line[7];
#                // FIXME: dont hardcode the time value
#                $time_value = "12:00:00";
#
#                // Issue key.
#                $issueKey = $line[12];
#
#                // The description of the task.
#                $comment  = $line[5];
#
#                // Time spent, in decimal value.
#                sscanf($line[11], "%d:%d:%d", $hours, $minutes, $seconds);
#                // Round hours to the nearest 15 minutes.
#                $hours = ceil(($hours + $minutes / 60 + $seconds / 3600) / 0.25) * 0.25;
#
#                if ($this->config->getDebug()) {
#                    echo "DATE: $date_value TIME: $time_value ISSUE: $issueKey COMMENT: $comment SPENT: $hours\n";
#                }
#
#                // Make sure timezone is correct, it can have an impact on the day the timelog is saved into.
#                $date = \DateTime::createFromFormat(
#                    $this->config->getDateFormat(),
#                    $date_value . ' ' . $time_value,
#                    new \DateTimeZone($this->config->getDateTimezone())
#                );
#
#                echo implode(', ', array($date->format('Y-m-d H:i:s'), $issueKey, $comment, $hours)) . "h\n";
#
#                try {
#                    $workLog = new Worklog();
#                    $workLog->setComment($comment)
#                        ->setStarted($date)
#                        ->setTimeSpent($hours . 'h');
#
#                    $issueService = new IssueService();
#
#                    if (empty($input->getOption('dru-run'))) {
#                        $ret = $issueService->addWorklog($issueKey, $workLog);
#                        $workLogid = $ret->{'id'};
#
#                        // Show output from the api call
#                        if ($this->config->getDebug()) {
#                            var_dump($ret);
#                        }
#                    } else {
#                        if ($this->config->getDebug()) {
#                            print_r($issueKey);
#                            print_r($workLog);
#                        }
#                    }
#                } catch (JiraException $e) {
#                    echo 'ERROR: ' . $e->getMessage() . "\n";
#                }
#            }
#        }
    }

    /**
     * Parse a Jira issue key, eg BSP-9, inside a random string.
     *
     * Supported formats:
     * - "random characters BSP-9 more random characters"
     *
     * @param string $string
     *   The string to search.
     *
     * @return string
     *   The issue key or NULL if not found.
     */
    function parse_key($string) {
        $issueKeyRegex = '/.*?([A-Z][A-Z0-9]+-\d+).*?/';
        if (!preg_match_all($issueKeyRegex, $string, $captureGroups)) {
            return NULL;
        }
        return $captureGroups[1][0];
    }

    /**
     * Remove issue key prefix from a comment string.
     *
     * Sometimes the only place to put the issue key is at the beginning of a
     * comment or note.
     *
     * This function is used to strip off the issue key from the start of a
     * comment.  Supported formats include:
     *
     * BSP-9 - Timesheets
     * BSP-9 -   Timesheets
     * BSP-9: Timesheets
     * BSP-9 : Timesheets
     * BSP-9  :  Timesheets
     * BSP-9  Timesheets
     * BSP-9. Timesheets
     * https://regex101.com/r/Tk7Bc6/1
     *
     * @param string $string
     *   The string to search.
     *
     * @return string
     *   The comment or NULL if not found.
     */
    function parse_comment($string) {
        $commentWithoutIssueKeyRegex = '/^([A-Z][A-Z0-9]+-\d+)(\s+)?(-|:|\.)?(\s+)?(.+)/s';
        if (!preg_match_all($commentWithoutIssueKeyRegex, $string, $captureGroups)) {
            return $string;
        }
        return $captureGroups[5][0];
    }

    /**
     * Convert time (hh:mm:ss) to decimal rounded up to nearest quarter-hour.
     *
     * @param string $time
     *   Hours in 'hh:mm:ss' format. Eg '1:27:33'.
     *
     * @return string
     *   Hours in Jira Decimal format. Eg '1.5h'.
     */
    function jira_hours_format($time) {
        $hms = explode(":", $time);
        $hours = $hms[0] + 0;
        $fractional_hour = ($hms[1] / 60) + ($hms[2] / 3600);
        $fractional_hour = $this->nearest_quarter_hour($fractional_hour);
        return (($hours + $fractional_hour) . 'h');
    }

    /**
     * Round a fraction up to the nearest quarter-hour.
     *
     * @param float $fractional_hour
     *   Minutes and seconds fraction.
     *
     * @return float
     *   Nearest quarter hour (.00, .25, .50, .75, or 1.00).
     */
    function nearest_quarter_hour($fractional_hour) {
        // We're looking for fourths (.00, .25, .50, .75), so multiply the number by
        // 4, round to nearest whole number as desired (ceil if up), then divide by 4.
        $denominator = 4;
        $x = $fractional_hour * $denominator;
        $x = ceil($x);
        $x = $x / $denominator;
        return $x;
    }

    /**
     * Print debug output to console.
     *
     * @param string $var
     *   The debug message.
     */
    function debug($var = '') {
        if ($this->config->getDebug()) {
            if (!empty($var)) {
                ob_start();
                print(PHP_EOL);
                print("Debug 👷 = ");
                var_export($var);
                $result = ob_get_clean();
                $this->write($result);
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
            $this->write($result);
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

}
