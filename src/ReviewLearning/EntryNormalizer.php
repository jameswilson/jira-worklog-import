<?php

declare(strict_types=1);

namespace JiraWorklogImport\ReviewLearning;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class EntryNormalizer {

  private const JIRA_KEY_PATTERN = '/\b([A-Z][A-Z0-9]+-\d+)\b/';

  /**
   * @param array<string, mixed> $entry
   * @return array<string, mixed>
   */
  public static function normalize(array $entry, DateTimeZone $timezone): array {
    $start = self::timestamp($entry['startDate'] ?? $entry['start'] ?? NULL);
    $duration = self::durationSeconds($entry['duration_seconds'] ?? $entry['duration'] ?? NULL);
    $endValue = $entry['endDate'] ?? $entry['end'] ?? NULL;
    $end = $endValue === NULL || $endValue === '' ? $start->modify('+' . $duration . ' seconds') : self::timestamp($endValue);
    if ($duration === 0 && $end >= $start) {
      $duration = $end->getTimestamp() - $start->getTimestamp();
    }

    $title = trim((string) ($entry['title'] ?? ''));
    $project = trim((string) ($entry['project'] ?? ''));
    $key = trim((string) ($entry['primary_jira_key'] ?? ''));
    if ($key === '') {
      $key = self::jiraKey($title) ?? self::jiraKey($project) ?? self::jiraKey((string) ($entry['notes'] ?? '')) ?? '';
    }

    return [
      'timing_entry_id' => self::entryId($entry),
      'project' => $project,
      'primary_jira_key' => $key,
      'title' => $title,
      'notes' => trim((string) ($entry['notes'] ?? '')),
      'start_epoch' => $start->getTimestamp(),
      'end_epoch' => $end->getTimestamp(),
      'start' => $start->setTimezone($timezone)->format('c'),
      'end' => $end->setTimezone($timezone)->format('c'),
      'local_date' => $start->setTimezone($timezone)->format('Y-m-d'),
      'duration_seconds' => $duration,
      'billing_status' => self::billingStatus($entry['billingStatus'] ?? $entry['billing_status'] ?? $entry['billing_classification'] ?? NULL),
    ];
  }

  public static function timestamp(mixed $value): DateTimeImmutable {
    if (!is_string($value) || trim($value) === '') {
      throw new InvalidArgumentException('Entry timestamp is missing.');
    }
    try {
      return new DateTimeImmutable($value);
    }
    catch (\Exception $exception) {
      throw new InvalidArgumentException('Invalid entry timestamp: ' . $value, 0, $exception);
    }
  }

  public static function durationSeconds(mixed $value): int {
    if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
      return max(0, (int) round((float) $value));
    }
    if (!is_string($value) || !preg_match('/^(\d+):(\d{2}):(\d{2})$/', trim($value), $matches)) {
      throw new InvalidArgumentException('Duration must be seconds or HH:MM:SS.');
    }
    if ((int) $matches[2] > 59 || (int) $matches[3] > 59) {
      throw new InvalidArgumentException('Duration minutes and seconds must be below 60.');
    }
    return ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) $matches[3];
  }

  /**
   * @param array<string, mixed> $entry
   */
  public static function fingerprint(array $entry): string {
    $values = [];
    foreach (['project', 'title', 'notes', 'start_epoch', 'end_epoch', 'duration_seconds', 'billing_status'] as $field) {
      $values[$field] = $entry[$field] ?? NULL;
    }
    return hash('sha256', json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
  }

  /**
   * @param array<string, mixed> $entry
   */
  public static function publicValues(array $entry): array {
    return [
      'project' => $entry['project'],
      'primary_jira_key' => $entry['primary_jira_key'],
      'title' => $entry['title'],
      'notes' => $entry['notes'],
      'start' => $entry['start'],
      'end' => $entry['end'],
      'duration_seconds' => $entry['duration_seconds'],
      'billing_status' => $entry['billing_status'],
    ];
  }

  private static function entryId(array $entry): ?string {
    foreach (['timing_entry_id', 'verified_timing_entry_id', 'id'] as $key) {
      if (isset($entry[$key]) && (string) $entry[$key] !== '') {
        return (string) $entry[$key];
      }
    }
    return NULL;
  }

  private static function jiraKey(string $value): ?string {
    return preg_match(self::JIRA_KEY_PATTERN, $value, $matches) ? $matches[1] : NULL;
  }

  private static function billingStatus(mixed $value): string {
    $normalized = strtolower(trim(str_replace([' ', '-'], '_', (string) ($value ?? ''))));
    return match ($normalized) {
      'billable' => 'billable',
      'not_billable', 'non_billable', 'notbillable' => 'not_billable',
      default => 'unspecified',
    };
  }

}
