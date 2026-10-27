<?php

declare(strict_types=1);

namespace JiraWorklogImport\ReviewLearning;

use RuntimeException;

final class ReviewLearningPreflight {

  /**
   * @return array<string, mixed>
   */
  public static function run(
    string $projectRoot,
    ?TimingEntryProvider $provider = NULL,
    ?Clock $clock = NULL,
    ?string $exportPath = NULL,
  ): array {
    $projectRoot = rtrim($projectRoot, '/');
    $learningRoot = $projectRoot . '/worklogs/learning';
    $store = new AtomicJsonStore($learningRoot);
    if ($provider === NULL) {
      $home = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');
      if ($home === '') {
        throw new RuntimeException('Cannot locate the Timing database because the user home directory is unavailable.');
      }
      $provider = new SqliteTimingEntryProvider(
        rtrim($home, '/') . '/Library/Application Support/info.eurocomp.Timing2/SQLite.db',
      );
    }
    $engine = new ReviewLearningEngine($projectRoot, $store, $provider, $clock ?? new SystemClock());
    return $engine->process($exportPath ?? $projectRoot . '/files/All Activities.json');
  }

  /**
   * @param array<string, mixed> $result
   * @return string[]
   */
  public static function report(array $result): array {
    $lines = [
      sprintf(
        'Review learning preflight: %s (%d reviewed, %d no-op, %d pending)',
        $result['status'] ?? 'unknown',
        $result['reviewed_count'] ?? 0,
        $result['no_op_count'] ?? 0,
        $result['pending_count'] ?? 0,
      ),
    ];
    foreach (($result['runs'] ?? []) as $run) {
      $line = sprintf('  %s: %s', $run['run_id'] ?? 'unknown-run', $run['status'] ?? 'unknown');
      if (!empty($run['reason'])) {
        $line .= ' — ' . $run['reason'];
      }
      $lines[] = $line;
    }
    $lines[] = '  Local learning only; no Jira or Tempo API was invoked.';
    return $lines;
  }

}
