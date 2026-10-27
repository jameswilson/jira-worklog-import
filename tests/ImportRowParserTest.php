<?php

declare(strict_types=1);

use JiraWorklogImport\Import\ImportRowParser;

require dirname(__DIR__) . '/src/Import/ImportRowParser.php';

final class ImportRowParserTest {

  private int $assertions = 0;

  public function run(): void {
    set_error_handler(static function (int $severity, string $message): never {
      throw new ErrorException($message, 0, $severity);
    });
    try {
      $this->testMissingTitleWithJiraKeyInNotes();
      $this->testMissingTitleWithoutJiraKey();
      $this->testMissingRequiredFields();
      $this->testInvalidValues();
    }
    finally {
      restore_error_handler();
    }
    print "OK ({$this->assertions} assertions)\n";
  }

  private function testMissingTitleWithJiraKeyInNotes(): void {
    $row = $this->parser()->parse((object) [
      'project' => 'BSP - Bluespark Internal',
      'notes' => 'BSP-598 - Clean slow environment, uninstall apps',
      'duration' => '00:30:00',
      'startDate' => '2026-10-23T18:59:00-05:00',
    ], 273);

    $this->same('🟠', $row->status, 'recoverable row remains importable');
    $this->same('BSP-598', $row->issueKey, 'Jira key is recovered from notes');
    $this->same('Clean slow environment, uninstall apps', $row->comment, 'leading Jira key is removed from comment');
    $this->same('warnings: missing field: title; Jira key sourced from notes', $row->details, 'fallback source is reported clearly');
  }

  private function testMissingTitleWithoutJiraKey(): void {
    $row = $this->parser()->parse((object) [
      'project' => 'IUL - Indiana University Libraries',
      'notes' => 'IULD8: Webform v2 fallback fix, Sprint 101 Scrum Board, Remove obsolete reservation term',
      'duration' => '00:45:00',
      'startDate' => '2026-10-23T19:30:00-05:00',
    ], 274);

    $this->same('🔴', $row->status, 'row without a Jira key is skipped');
    $this->same('skipped', $row->status_message, 'skipped status remains concise');
    $this->same('⭕ Jira key required', $row->issueKey, 'issue column explains what is required');
    $this->same(
      'errors: Jira key not found in title, project, or notes | warnings: missing field: title',
      $row->details,
      'missing title and missing Jira key are distinguished',
    );
  }

  private function testMissingRequiredFields(): void {
    $row = $this->parser()->parse((object) [
      'project' => 'BSP - Bluespark Internal',
      'title' => 'BSP-598 - Timesheets',
    ], 1);

    $this->same('🔴', $row->status, 'missing required fields skip the row');
    $this->same('⭕ duration required', $row->hours, 'missing duration is identified in its column');
    $this->same('⭕ startDate required', $row->datetime, 'missing start date is identified in its column');
    $this->same(
      'errors: missing required fields: duration, startDate | warnings: missing field: notes; worklog comment sourced from title',
      $row->details,
      'missing required and fallback fields are summarized',
    );
  }

  private function testInvalidValues(): void {
    $row = $this->parser()->parse((object) [
      'project' => 'BSP - Bluespark Internal',
      'title' => 'BSP-598 - Timesheets',
      'notes' => 'Importer validation',
      'duration' => '45 minutes',
      'startDate' => 'October 23',
    ], 1);

    $this->same('🔴', $row->status, 'invalid values skip the row');
    $this->same('⭕ invalid duration', $row->hours, 'invalid duration is identified in its column');
    $this->same('⭕ October 23', $row->datetime, 'invalid timestamp preserves the supplied value');
    $this->same(
      'errors: duration must use HH:MM:SS with valid minutes and seconds; startDate must be a valid ISO 8601 timestamp',
      $row->details,
      'invalid field formats are reported precisely',
    );
  }

  private function parser(): ImportRowParser {
    return new ImportRowParser(DateTime::ATOM, new DateTimeZone('America/Bogota'));
  }

  private function same(mixed $expected, mixed $actual, string $message): void {
    $this->assertions++;
    if ($expected !== $actual) {
      throw new RuntimeException($message . '; expected ' . var_export($expected, TRUE) . ', got ' . var_export($actual, TRUE));
    }
  }

}

(new ImportRowParserTest())->run();
