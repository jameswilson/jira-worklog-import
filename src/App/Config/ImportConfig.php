<?php

namespace App\Config;

use Dotenv\Dotenv;
use JiraRestApi\JiraException;
use Symfony\Component\Console\Input\InputInterface;

const DEFAULT_DATE_FORMAT = \DateTime::ATOM;
const DEFAULT_DATE_TIMEZONE = 'America/Bogota';

class ImportConfig {
  private $date_format;
  private $date_timezone;
  private $csv_delimiter;
  private $offset;
  private $limit;
  private $debug;

  /**
   * ImportConfig constructor.
   */
  public function __construct() {
    $this->loadEnvConfig();
  }

  /**
   * @return mixed
   */
  public function getDateFormat() {
    return $this->date_format;
  }

  /**
   * @param mixed $date_format
   */
  public function setDateFormat($date_format) {
    $this->date_format = $date_format;
  }

  /**
   * @return mixed
   */
  public function getDateTimezone() {
    return $this->date_timezone;
  }

  /**
   * @param mixed $csv_date_timezone
   */
  public function setDateTimezone($date_timezone) {
    $this->date_timezone = $date_timezone;
  }

  /**
   * @return mixed
   */
  public function getCsvDelimiter() {
    return $this->csv_delimiter;
  }

  /**
   * @param mixed $csv_delimiter
   */
  public function setCsvDelimiter($csv_delimiter) {
    $this->csv_delimiter = $csv_delimiter;
  }

  /**
   * @return mixed
   */
  public function getOffset() {
    return $this->offset;
  }

  /**
   * @param mixed $offset
   */
  public function setOffset($offset) {
    $this->offset = $offset;
  }

  /**
   * @return mixed
   */
  public function getLimit() {
    return $this->limit;
  }

  /**
   * @param mixed $limit
   */
  public function setLimit($limit) {
    $this->limit = $limit;
  }

  /**
   * @return mixed
   */
  public function getDebug() {
    return $this->debug;
  }

  /**
   * @param mixed $debug
   */
  public function setDebug($debug) {
    $this->debug = $debug;
  }

  /**
   *
   */
  private function loadEnvConfig() {
    $this->loadDotEnv();

    if ($date_format = $this->env('DATE_FORMAT')) {
      $this->date_format = $date_format;
    } else {
      $this->date_format = DEFAULT_DATE_FORMAT;
    }

    if ($date_timezone = $this->env('DATE_TIMEZONE')) {
      $this->date_timezone = $date_timezone;
    } else {
      $this->date_timezone = DEFAULT_DATE_TIMEZONE;
    }

    if ($csv_delimiter = $this->env('CSV_DELIMITER')) {
      $this->csv_delimiter = $csv_delimiter;
    } else {
      $this->csv_delimiter = ',';
    }

    if ($offset = $this->env('OFFSET')) {
      $this->offset = $offset;
    } else {
      $this->offset = 1;
    }

    if ($limit = $this->env('LIMIT')) {
      $this->limit = $limit;
    } else {
      $this->limit = 1000;
    }

    if ($debug = $this->env('DEBUG')) {
      $this->debug = $debug;
    } else {
      $this->debug = false;
    }
  }

  /**
   *
   */
  private function loadDotEnv() {
    Dotenv::createImmutable('.');
  }

  /**
   * Gets the value of an environment variable. Supports boolean, empty and null.
   *
   * @param string $key
   * @param mixed  $default
   *
   * @return mixed
   */
  private function env($key, $default = null) {
    $value = getenv($key);

    if ($value === false) {
      return $default;
    }

    switch (strtolower($value)) {
      case 'true':
      case '(true)':
        return true;

      case 'false':
      case '(false)':
        return false;

      case 'empty':
      case '(empty)':
        return '';

      case 'null':
      case '(null)':
        return;
    }

    if ($this->startsWith($value, '"') && $this->endsWith($value, '"')) {
      return substr($value, 1, -1);
    }

    return $value;
  }

  /**
   * @param \Symfony\Component\Console\Input\InputInterface $input
   */
  public function loadOptions(InputInterface $input) {
    if ($input->getOption('csv-date-format')) {
      $this->date_format = $input->getOption('date-format');
    }
    if ($input->getOption('csv-date-timezone')) {
      $this->date_timezone = $input->getOption('date-timezone');
    }
    if ($input->getOption('limit')) {
      $this->limit = $input->getOption('limit');
    }
    if ($input->getOption('offset')) {
      $this->offset = $input->getOption('offset');
    }
  }

  /**
   * Determine if a given string starts with a given substring.
   *
   * @param string       $haystack
   * @param string|array $needles
   *
   * @return bool
   */
  public function startsWith($haystack, $needles) {
    foreach ((array) $needles as $needle) {
      if ($needle != '' && strpos($haystack, $needle) === 0) {
        return true;
      }
    }

    return false;
  }

  /**
   * Determine if a given string ends with a given substring.
   *
   * @param string       $haystack
   * @param string|array $needles
   *
   * @return bool
   */
  public function endsWith($haystack, $needles) {
    foreach ((array) $needles as $needle) {
      if ((string) $needle === substr($haystack, -strlen($needle))) {
        return true;
      }
    }

    return false;
  }
}
