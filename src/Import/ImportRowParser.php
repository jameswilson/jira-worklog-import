<?php

declare(strict_types=1);

namespace JiraWorklogImport\Import;

use DateTimeImmutable;
use DateTimeZone;
use stdClass;

final class ImportRowParser {

  private const ROUTING_FIELDS = ['title', 'project', 'notes'];

  public function __construct(
    private readonly string $dateFormat,
    private readonly DateTimeZone $timezone,
  ) {
  }

  public function parse(mixed $input, int $lineNumber): stdClass {
    $row = new stdClass();
    $row->line = $lineNumber;
    $row->status = '🟠';
    $row->status_message = 'parsing';
    $row->project = '';
    $row->issueKey = '';
    $row->hours = '';
    $row->datetime = '';
    $row->comment = '';

    $errors = [];
    $warnings = [];
    if (!is_object($input)) {
      $errors[] = 'row must be a JSON object';
      return $this->finish($row, $errors, $warnings);
    }

    $fields = [];
    $missing = [];
    $invalid = [];
    foreach (['project', 'title', 'notes', 'duration', 'startDate'] as $field) {
      if (!property_exists($input, $field) || $input->{$field} === NULL) {
        $fields[$field] = '';
        $missing[] = $field;
        continue;
      }
      if (!is_string($input->{$field})) {
        $fields[$field] = '';
        $invalid[] = $field;
        $errors[] = $field . ' must be a string';
        continue;
      }
      $fields[$field] = trim($input->{$field});
      if ($fields[$field] === '') {
        $missing[] = $field;
      }
    }

    $missingRequired = array_values(array_intersect($missing, ['duration', 'startDate']));
    if ($missingRequired !== []) {
      $errors[] = 'missing required ' . self::fieldList($missingRequired);
    }
    $missingMetadata = array_values(array_intersect($missing, self::ROUTING_FIELDS));
    if ($missingMetadata !== []) {
      $warnings[] = 'missing ' . self::fieldList($missingMetadata);
    }

    $row->project = $fields['project'];

    [$issueKey, $issueKeySource] = $this->firstJiraKey($fields);
    if ($issueKey === NULL) {
      $row->issueKey = '⭕ Jira key required';
      $errors[] = 'Jira key not found in title, project, or notes';
    }
    else {
      $row->issueKey = $issueKey;
      if ($issueKeySource !== 'title') {
        $warnings[] = 'Jira key sourced from ' . $issueKeySource;
      }
    }

    [$comment, $commentSource] = $this->firstComment($fields);
    if ($comment === NULL) {
      $row->comment = '⭕ A worklog comment is required.';
      $errors[] = 'worklog comment not found in notes, title, or project';
    }
    else {
      $row->comment = str_replace("\n", '\\n', $comment);
      if ($commentSource !== 'notes') {
        $warnings[] = 'worklog comment sourced from ' . $commentSource;
      }
    }

    if (in_array('duration', $invalid, TRUE)) {
      $row->hours = '⭕ invalid duration';
    }
    elseif ($fields['duration'] === '') {
      $row->hours = '⭕ duration required';
    }
    else {
      $row->hours = $this->hours($fields['duration']);
      if ($row->hours === NULL) {
        $row->hours = '⭕ invalid duration';
        $errors[] = 'duration must use HH:MM:SS with valid minutes and seconds';
      }
    }

    if (in_array('startDate', $invalid, TRUE)) {
      $row->datetime = '⭕ invalid startDate';
    }
    elseif ($fields['startDate'] === '') {
      $row->datetime = '⭕ startDate required';
    }
    else {
      $date = DateTimeImmutable::createFromFormat($this->dateFormat, $fields['startDate'], $this->timezone);
      $dateErrors = DateTimeImmutable::getLastErrors();
      if (
        $date === FALSE
        || ($dateErrors !== FALSE && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
      ) {
        $row->datetime = '⭕ ' . $fields['startDate'];
        $errors[] = 'startDate must be a valid ISO 8601 timestamp';
      }
      else {
        $row->datetime = $date->format('Y-m-d H:i:s');
      }
    }

    return $this->finish($row, $errors, $warnings);
  }

  /**
   * @param array<string, string> $fields
   * @return array{?string, ?string}
   */
  private function firstJiraKey(array $fields): array {
    foreach (self::ROUTING_FIELDS as $field) {
      if (preg_match('/[A-Z][A-Z0-9]+-\d+/', $fields[$field], $matches) === 1) {
        return [$matches[0], $field];
      }
    }
    return [NULL, NULL];
  }

  /**
   * @param array<string, string> $fields
   * @return array{?string, ?string}
   */
  private function firstComment(array $fields): array {
    foreach (['notes', 'title', 'project'] as $field) {
      if ($fields[$field] === '') {
        continue;
      }
      $comment = preg_replace('/^([A-Z][A-Z0-9]+-\d+)(\s+)?(-|:|\.)?(\s+)?/s', '', $fields[$field]);
      $comment = trim((string) $comment);
      if ($comment !== '') {
        return [$comment, $field];
      }
    }
    return [NULL, NULL];
  }

  private function hours(string $duration): ?string {
    if (preg_match('/^(\d+):([0-5]\d):([0-5]\d)$/', $duration, $matches) !== 1) {
      return NULL;
    }
    $hours = (int) $matches[1];
    $fractionalHour = ((int) $matches[2] / 60) + ((int) $matches[3] / 3600);
    $fractionalHour = ceil($fractionalHour * 4) / 4;
    return ($hours + $fractionalHour) . 'h';
  }

  /**
   * @param string[] $fields
   */
  private static function fieldList(array $fields): string {
    return (count($fields) === 1 ? 'field: ' : 'fields: ') . implode(', ', $fields);
  }

  /**
   * @param string[] $errors
   * @param string[] $warnings
   */
  private function finish(stdClass $row, array $errors, array $warnings): stdClass {
    if ($errors !== []) {
      $row->status = '🔴';
      $row->status_message = 'skipped';
    }
    $details = [];
    if ($errors !== []) {
      $details[] = 'errors: ' . implode('; ', array_values(array_unique($errors)));
    }
    if ($warnings !== []) {
      $details[] = 'warnings: ' . implode('; ', array_values(array_unique($warnings)));
    }
    if ($details !== []) {
      $row->details = implode(' | ', $details);
    }
    return $row;
  }

}
