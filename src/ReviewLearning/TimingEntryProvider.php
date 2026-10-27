<?php

declare(strict_types=1);

namespace JiraWorklogImport\ReviewLearning;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SQLite3;

interface TimingEntryProvider {

  /**
   * @param string[] $localDates
   */
  public function read(array $localDates, DateTimeZone $timezone): TimingReadback;

}

final class TimingReadback {

  /**
   * @param array<int, array<string, mixed>> $entries
   * @param string[] $coveredLocalDates
   */
  public function __construct(
    public readonly array $entries,
    public readonly array $coveredLocalDates,
    public readonly string $method,
    public readonly string $source,
  ) {
  }

}

final class ArrayTimingEntryProvider implements TimingEntryProvider {

  /**
   * @param array<int, array<string, mixed>> $entries
   */
  public function __construct(
    private readonly array $entries,
    private readonly string $source = 'fixture',
  ) {
  }

  public function read(array $localDates, DateTimeZone $timezone): TimingReadback {
    $wanted = array_fill_keys($localDates, TRUE);
    $entries = array_values(array_filter($this->entries, static function (array $entry) use ($wanted, $timezone): bool {
      $start = EntryNormalizer::timestamp($entry['startDate'] ?? $entry['start'] ?? NULL);
      return isset($wanted[$start->setTimezone($timezone)->format('Y-m-d')]);
    }));

    return new TimingReadback($entries, array_values($localDates), 'array_fixture', $this->source);
  }

}

final class JsonTimingEntryProvider implements TimingEntryProvider {

  public function __construct(private readonly string $path) {
  }

  public function read(array $localDates, DateTimeZone $timezone): TimingReadback {
    $contents = @file_get_contents($this->path);
    if ($contents === FALSE) {
      throw new RuntimeException('Could not read Timing snapshot: ' . $this->path);
    }
    try {
      $decoded = json_decode($contents, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new RuntimeException('Timing snapshot is not valid JSON: ' . $exception->getMessage(), 0, $exception);
    }
    $entries = is_array($decoded) && isset($decoded['entries']) ? $decoded['entries'] : $decoded;
    if (!is_array($entries) || !array_is_list($entries)) {
      throw new RuntimeException('Timing snapshot must be a JSON list or an object with an entries list.');
    }

    return (new ArrayTimingEntryProvider($entries, $this->path))->read($localDates, $timezone);
  }

}

final class SqliteTimingEntryProvider implements TimingEntryProvider {

  private const APPLE_EPOCH_OFFSET = 978307200;

  public function __construct(private readonly string $databasePath) {
  }

  public function read(array $localDates, DateTimeZone $timezone): TimingReadback {
    if (!is_file($this->databasePath)) {
      throw new RuntimeException('Timing database does not exist: ' . $this->databasePath);
    }

    $database = new SQLite3($this->databasePath, SQLITE3_OPEN_READONLY);
    $database->busyTimeout(5000);
    if (!$database->exec('PRAGMA query_only = ON')) {
      throw new RuntimeException('Could not enforce query-only access to the Timing database.');
    }

    $clauses = [];
    foreach (array_values(array_unique($localDates)) as $index => $date) {
      $start = new DateTimeImmutable($date . ' 00:00:00', $timezone);
      $end = $start->modify('+1 day');
      $startApple = $start->getTimestamp() - self::APPLE_EPOCH_OFFSET;
      $endApple = $end->getTimestamp() - self::APPLE_EPOCH_OFFSET;
      $clauses[] = sprintf('(t.startDate >= %d AND t.startDate < %d)', $startApple, $endApple);
    }
    if ($clauses === []) {
      return new TimingReadback([], [], 'sqlite_readonly', $this->databasePath);
    }

    $sql = <<<SQL
SELECT
  CAST(t.id AS TEXT) AS timing_entry_id,
  t.startDate,
  t.endDate,
  p.title AS project,
  COALESCE(t.title, '') AS title,
  COALESCE(t.notes, '') AS notes,
  COALESCE(t.property_bag, '') AS property_bag
FROM TaskActivity t
LEFT JOIN Project p ON p.id = t.projectID
WHERE t.isDeleted = 0
  AND (
SQL;
    $sql .= implode("\n    OR ", $clauses) . "\n  )\nORDER BY t.startDate, t.endDate, t.id";
    $result = $database->query($sql);
    if ($result === FALSE) {
      throw new RuntimeException('Timing readback query failed: ' . $database->lastErrorMsg());
    }

    $entries = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
      $startEpoch = (int) round((float) $row['startDate'] + self::APPLE_EPOCH_OFFSET);
      $endEpoch = (int) round((float) $row['endDate'] + self::APPLE_EPOCH_OFFSET);
      $propertyBag = json_decode((string) $row['property_bag'], TRUE);
      $billing = is_array($propertyBag) ? ($propertyBag['_billing_status'] ?? 'unspecified') : 'unspecified';
      $entries[] = [
        'timing_entry_id' => (string) $row['timing_entry_id'],
        'project' => (string) ($row['project'] ?? ''),
        'title' => (string) $row['title'],
        'notes' => (string) $row['notes'],
        'startDate' => gmdate('c', $startEpoch),
        'endDate' => gmdate('c', $endEpoch),
        'duration_seconds' => max(0, $endEpoch - $startEpoch),
        'billingStatus' => (string) $billing,
      ];
    }
    $result->finalize();
    $database->close();

    return new TimingReadback($entries, array_values($localDates), 'sqlite3_readonly_fallback', $this->databasePath);
  }

}
