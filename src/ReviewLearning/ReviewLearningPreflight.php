<?php

declare(strict_types=1);

namespace JiraWorklogImport\ReviewLearning;

use DateTimeImmutable;
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
    $groups = [
      'reviewed' => [],
      'current' => [],
      'pending' => [],
    ];
    foreach (($result['runs'] ?? []) as $run) {
      if (!is_array($run)) {
        continue;
      }
      $status = (string) ($run['status'] ?? '');
      if ($status === 'reviewed') {
        $groups['reviewed'][] = $run;
      }
      elseif ($status === 'no_op_already_processed') {
        $groups['current'][] = $run;
      }
      else {
        $groups['pending'][] = $run;
      }
    }

    $learnedCount = count($groups['reviewed']);
    $currentCount = count($groups['current']);
    $pendingCount = count($groups['pending']);
    $lines = [
      'Timing review learning',
      self::summaryLine($learnedCount, $currentCount, $pendingCount),
    ];

    if ($groups['reviewed'] !== []) {
      $lines[] = '';
      $lines[] = 'Learned now';
      foreach ($groups['reviewed'] as $run) {
        $lines[] = '  - ' . self::reviewLabel($run);
      }
    }
    if ($groups['current'] !== []) {
      $lines[] = '';
      $lines[] = 'Already learned — no action needed';
      foreach ($groups['current'] as $run) {
        $lines[] = '  - ' . self::reviewLabel($run);
      }
    }
    if ($groups['pending'] !== []) {
      $lines[] = '';
      $lines[] = 'Needs attention';
      foreach ($groups['pending'] as $run) {
        $lines[] = '  - ' . self::reviewLabel($run);
        $lines[] = '    ' . self::pendingMessage($run);
      }
    }

    $lines[] = '';
    $lines[] = 'No Jira or Tempo worklogs were created or changed.';
    return $lines;
  }

  private static function summaryLine(int $learned, int $current, int $pending): string {
    if ($learned === 0 && $current === 0 && $pending === 0) {
      return 'No pending reviews were found.';
    }

    $sentences = [];
    $sentences[] = $learned === 0
      ? 'Nothing new was learned.'
      : sprintf('Learned from %d %s.', $learned, $learned === 1 ? 'review' : 'reviews');
    if ($current > 0) {
      $sentences[] = sprintf(
        '%d %s already up to date.',
        $current,
        $current === 1 ? 'review was' : 'reviews were',
      );
    }
    if ($pending > 0) {
      $sentences[] = sprintf(
        '%d %s attention.',
        $pending,
        $pending === 1 ? 'review needs' : 'reviews need',
      );
    }
    return implode(' ', $sentences);
  }

  /**
   * @param array<string, mixed> $run
   */
  private static function reviewLabel(array $run): string {
    $runId = (string) ($run['run_id'] ?? 'Review');
    $slug = (string) preg_replace('/^\d{8}T\d{6}Z-/', '', $runId);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:-(.+))?$/', $slug, $matches)) {
      $label = self::formatDate($matches[1]);
      if (!empty($matches[2])) {
        $description = self::humanizeSlug($matches[2]);
        if (in_array($description, ['Acceptance', 'Gap fill'], TRUE)) {
          $description = 'Time review';
        }
        $label .= ' — ' . $description;
      }
      return $label;
    }
    return self::humanizeSlug($slug);
  }

  private static function humanizeSlug(string $slug): string {
    $label = trim((string) preg_replace('/\s+/', ' ', str_replace('-', ' ', $slug)));
    $label = (string) preg_replace('/\bcontrib25\b/i', 'CONTRIB-25', $label);
    return $label === '' ? 'Review' : ucfirst($label);
  }

  /**
   * @param array<string, mixed> $run
   */
  private static function pendingMessage(array $run): string {
    return match ((string) ($run['status'] ?? '')) {
      'pending_partial_export' => self::partialExportMessage($run),
      'pending_ambiguous' => 'The Timing entries no longer match the original draft clearly enough to learn from them. Nothing was changed; this review needs a manual comparison.',
      'pending_filtered_or_incomplete_export' => 'The export does not match all current Timing entries for this review. Export All Activities from Timing without filters, then run learning again.',
      'pending_stale_export' => 'The export is older than this review. Create a fresh Timing export, then run learning again.',
      'pending_wrong_export_path' => 'This review expects a different Timing export file. Use the configured All Activities export, then run learning again.',
      'pending_current_timing_unavailable' => 'Current Timing entries could not be read safely. Check that the Timing data is available, then run learning again.',
      'pending_invalid_export' => 'The Timing export contains inconsistent time data. Correct or recreate the export, then run learning again.',
      'pending_invalid_manifest_or_export' => 'The saved review or Timing export is malformed. Nothing was learned; inspect the review data before trying again.',
      'pending_invalid_readback' => 'Current Timing data could not be compared safely with the export. Nothing was learned; inspect the Timing data before trying again.',
      default => self::fallbackPendingMessage($run),
    };
  }

  /**
   * @param array<string, mixed> $run
   */
  private static function partialExportMessage(array $run): string {
    $dates = is_array($run['missing_local_dates'] ?? NULL)
      ? array_values(array_unique(array_map('strval', $run['missing_local_dates'])))
      : [];
    sort($dates, SORT_STRING);
    if (count($dates) === 1) {
      return sprintf(
        'This export does not include %s, which is needed for this review. Export that date from Timing, then run learning again.',
        self::formatDate($dates[0]),
      );
    }
    if (count($dates) > 1) {
      return sprintf(
        'This export is missing %d dates needed for this review, spanning %s to %s. Export the complete date range from Timing, then run learning again.',
        count($dates),
        self::formatDate($dates[0]),
        self::formatDate($dates[count($dates) - 1]),
      );
    }
    return 'This export does not cover every date needed for this review. Export the complete date range from Timing, then run learning again.';
  }

  /**
   * @param array<string, mixed> $run
   */
  private static function fallbackPendingMessage(array $run): string {
    $reason = trim((string) ($run['reason'] ?? ''));
    if ($reason === '') {
      return 'This review could not be learned safely. Nothing was changed; inspect it manually.';
    }
    return rtrim($reason, '.') . '. Nothing was learned from this review.';
  }

  private static function formatDate(string $date): string {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($parsed === FALSE || $parsed->format('Y-m-d') !== $date) {
      return $date;
    }
    return $parsed->format('M j, Y');
  }

}
