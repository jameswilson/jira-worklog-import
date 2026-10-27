<?php

declare(strict_types=1);

namespace JiraWorklogImport\ReviewLearning;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class ReviewLearningEngine {

  public const SCHEMA_VERSION = 1;

  public function __construct(
    private readonly string $projectRoot,
    private readonly AtomicJsonStore $store,
    private readonly TimingEntryProvider $timingEntries,
    private readonly Clock $clock = new SystemClock(),
  ) {
  }

  /**
   * Process one reviewed Timing export against every pending manifest.
   *
   * @return array<string, mixed>
   */
  public function process(string $exportPath): array {
    if ($this->store->pendingPaths() === []) {
      return $this->summary('no_pending_manifests', [], NULL);
    }

    return $this->store->withExclusiveLock(function () use ($exportPath): array {
      $pendingPaths = $this->store->pendingPaths();
      if ($pendingPaths === []) {
        return $this->summary('no_pending_manifests', [], NULL);
      }

      $export = $this->readExport($exportPath);
      $receiptsPath = $this->store->root() . '/export-receipts.json';
      $receiptState = $this->store->readObject($receiptsPath, [
        'schema_version' => self::SCHEMA_VERSION,
        'receipts' => [],
      ]);
      $receipts = is_array($receiptState['receipts'] ?? NULL) ? $receiptState['receipts'] : [];
      $results = [];

      foreach ($pendingPaths as $pendingPath) {
        $pendingSha = hash_file('sha256', $pendingPath);
        if ($pendingSha === FALSE) {
          throw new RuntimeException('Could not hash pending manifest: ' . $pendingPath);
        }
        $pending = $this->store->readObject($pendingPath);
        $runId = $this->requireString($pending, 'run_id', $pendingPath);
        $receiptKey = $this->receiptKey($runId, $export['sha256']);
        if ($this->hasReceipt($receipts, $runId, $export['sha256'])) {
          $results[] = [
            'run_id' => $runId,
            'status' => 'no_op_already_processed',
            'receipt_key' => $receiptKey,
          ];
          continue;
        }

        $qualification = $this->qualify($pending, $pendingPath, $export);
        if ($qualification['status'] !== 'qualified') {
          $results[] = ['run_id' => $runId] + $qualification;
          continue;
        }

        $timezone = new DateTimeZone($qualification['timezone']);
        try {
          $readback = $this->timingEntries->read($qualification['requested_local_dates'], $timezone);
        }
        catch (\Throwable $exception) {
          $results[] = [
            'run_id' => $runId,
            'status' => 'pending_current_timing_unavailable',
            'reason' => $exception->getMessage(),
          ];
          continue;
        }

        $comparison = $this->compareCurrentTiming($readback, $export['entries'], $qualification['requested_local_dates'], $timezone);
        if ($comparison['status'] !== 'complete') {
          $results[] = [
            'run_id' => $runId,
            'status' => 'pending_' . $comparison['status'],
            'reason' => $comparison['reason'],
          ];
          continue;
        }

        $analysis = $this->analyze($pending, $comparison['current_entries'], $comparison['export_entries'], $timezone);
        if ($analysis['status'] !== 'reviewed') {
          $results[] = [
            'run_id' => $runId,
            'status' => 'pending_ambiguous',
            'reason' => $analysis['reason'],
            'ambiguities' => $analysis['ambiguities'],
          ];
          continue;
        }

        if (hash_file('sha256', $pendingPath) !== $pendingSha) {
          throw new RuntimeException('Pending manifest changed during review and was not processed: ' . $pendingPath);
        }

        $reviewedPath = $this->store->reviewedDirectory() . '/' . $runId . '.json';
        $priorReviewed = $this->store->readObject($reviewedPath, []);
        $reviewed = $this->buildReviewedManifest(
          $pending,
          $pendingPath,
          $pendingSha,
          $export,
          $readback,
          $comparison,
          $analysis,
          $priorReviewed,
        );
        $this->store->writeObject($reviewedPath, $reviewed);

        $preferenceResult = $this->updatePreferences();
        $reviewed['preferences'] = [
          'path' => $preferenceResult['path'],
          'sha256' => $preferenceResult['sha256'],
          'loaded' => TRUE,
          'updated_by_this_review' => $preferenceResult['updated'],
          'reason' => $preferenceResult['reason'],
        ];
        $this->store->writeObject($reviewedPath, $reviewed);

        $receipt = [
          'receipt_key' => $receiptKey,
          'run_id' => $runId,
          'export_path' => $export['path'],
          'export_sha256' => $export['sha256'],
          'export_mtime' => $export['mtime'],
          'processed_at' => $this->now(),
          'requested_local_dates' => $qualification['requested_local_dates'],
          'timezone' => $qualification['timezone'],
          'covered_local_dates' => $qualification['requested_local_dates'],
          'result' => $priorReviewed === [] ? 'reviewed' : 'reviewed_revision',
          'reviewed_manifest_path' => $reviewedPath,
          'outcome_count' => count($analysis['outcomes']),
          'preferences_path' => $preferenceResult['path'],
          'preferences_sha256' => $preferenceResult['sha256'],
          'live_import' => 'not_run_not_authorized',
          'remote_writes' => [
            'jira' => 'none',
            'tempo' => 'none',
          ],
        ];
        $receipts[] = $receipt;
        $receiptState = [
          'schema_version' => self::SCHEMA_VERSION,
          'receipts' => array_values($receipts),
        ];
        $this->store->writeObject($receiptsPath, $receiptState);

        $results[] = [
          'run_id' => $runId,
          'status' => 'reviewed',
          'receipt_key' => $receiptKey,
          'reviewed_manifest_path' => $reviewedPath,
          'outcome_count' => count($analysis['outcomes']),
        ];
      }

      $status = array_reduce($results, static function (string $carry, array $result): string {
        if (str_starts_with((string) ($result['status'] ?? ''), 'pending_')) {
          return 'completed_with_pending_runs';
        }
        return $carry;
      }, 'completed');

      return $this->summary($status, $results, $export['sha256']);
    });
  }

  /**
   * @return array<string, mixed>
   */
  private function readExport(string $path): array {
    if (!is_file($path)) {
      throw new RuntimeException('Reviewed Timing export does not exist: ' . $path);
    }
    $contents = @file_get_contents($path);
    if ($contents === FALSE) {
      throw new RuntimeException('Could not read reviewed Timing export: ' . $path);
    }
    try {
      $decoded = json_decode($contents, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new RuntimeException('Reviewed Timing export is not valid JSON: ' . $exception->getMessage(), 0, $exception);
    }

    $explicitScope = NULL;
    if (is_array($decoded) && !array_is_list($decoded) && isset($decoded['entries'])) {
      $entries = $decoded['entries'];
      $scope = $decoded['scope']['local_dates'] ?? NULL;
      if ($scope !== NULL) {
        if (!is_array($scope) || !array_is_list($scope)) {
          throw new RuntimeException('Export scope.local_dates must be a list.');
        }
        $explicitScope = array_values(array_unique(array_map('strval', $scope)));
      }
    }
    else {
      $entries = $decoded;
    }
    if (!is_array($entries) || !array_is_list($entries)) {
      throw new RuntimeException('Reviewed Timing export must be a JSON list or an object with an entries list.');
    }

    foreach ($entries as $index => $entry) {
      if (!is_array($entry)) {
        throw new RuntimeException('Export row ' . ($index + 1) . ' is not an object.');
      }
      foreach (['project', 'title', 'duration', 'startDate', 'endDate', 'billingStatus'] as $field) {
        if (!array_key_exists($field, $entry)) {
          throw new RuntimeException('Export row ' . ($index + 1) . ' is missing ' . $field . '.');
        }
      }
    }

    $mtime = filemtime($path);
    $sha = hash_file('sha256', $path);
    if ($mtime === FALSE || $sha === FALSE) {
      throw new RuntimeException('Could not stat or hash reviewed Timing export: ' . $path);
    }

    return [
      'path' => realpath($path) ?: $path,
      'sha256' => $sha,
      'mtime_epoch' => $mtime,
      'mtime' => gmdate('c', $mtime),
      'raw_entries' => $entries,
      'entries' => $entries,
      'explicit_scope_dates' => $explicitScope,
    ];
  }

  /**
   * @param array<string, mixed> $pending
   * @param array<string, mixed> $export
   * @return array<string, mixed>
   */
  private function qualify(array $pending, string $pendingPath, array $export): array {
    try {
      $timezoneName = $this->requireString($pending, 'timezone', $pendingPath);
      $timezone = new DateTimeZone($timezoneName);
      $createdAt = EntryNormalizer::timestamp($this->requireString($pending, 'created_at', $pendingPath));
      $dates = $pending['requested_local_dates'] ?? [];
      if (!is_array($dates) || !array_is_list($dates) || $dates === []) {
        throw new InvalidArgumentException('requested_local_dates must be a non-empty list.');
      }
      $dates = array_values(array_unique(array_map('strval', $dates)));
      foreach ($dates as $date) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        if ($parsed === FALSE || $parsed->format('Y-m-d') !== $date) {
          throw new InvalidArgumentException('Invalid requested local date: ' . $date);
        }
      }

      $expectedPath = $this->requireString($pending, 'expected_review_export_path', $pendingPath);
      if (!$this->samePath($expectedPath, $export['path'])) {
        return [
          'status' => 'pending_wrong_export_path',
          'reason' => 'The export path does not match the pending manifest.',
        ];
      }
      if ($export['mtime_epoch'] <= $createdAt->getTimestamp()) {
        return [
          'status' => 'pending_stale_export',
          'reason' => 'The export modification time is not newer than the pending proposal.',
        ];
      }

      $normalized = [];
      $derivedDates = [];
      foreach ($export['entries'] as $index => $entry) {
        $row = EntryNormalizer::normalize($entry, $timezone);
        if (abs(($row['end_epoch'] - $row['start_epoch']) - $row['duration_seconds']) > 1) {
          return [
            'status' => 'pending_invalid_export',
            'reason' => 'Export row ' . ($index + 1) . ' has inconsistent duration arithmetic.',
          ];
        }
        $normalized[] = $row;
        $derivedDates[$row['local_date']] = TRUE;
      }
      $coverageDates = $export['explicit_scope_dates'] ?? array_keys($derivedDates);
      $missingDates = array_values(array_diff($dates, $coverageDates));
      if ($missingDates !== []) {
        return [
          'status' => 'pending_partial_export',
          'reason' => 'The export does not demonstrate coverage for every requested local date.',
          'missing_local_dates' => $missingDates,
        ];
      }

      return [
        'status' => 'qualified',
        'timezone' => $timezoneName,
        'requested_local_dates' => $dates,
        'normalized_export_entries' => $normalized,
      ];
    }
    catch (\Throwable $exception) {
      return [
        'status' => 'pending_invalid_manifest_or_export',
        'reason' => $exception->getMessage(),
      ];
    }
  }

  /**
   * @param array<int, array<string, mixed>> $exportEntries
   * @param string[] $requestedDates
   * @return array<string, mixed>
   */
  private function compareCurrentTiming(TimingReadback $readback, array $exportEntries, array $requestedDates, DateTimeZone $timezone): array {
    $missingReadbackDates = array_values(array_diff($requestedDates, $readback->coveredLocalDates));
    if ($missingReadbackDates !== []) {
      return [
        'status' => 'current_timing_unavailable',
        'reason' => 'Current Timing readback did not cover: ' . implode(', ', $missingReadbackDates),
      ];
    }

    $wanted = array_fill_keys($requestedDates, TRUE);
    try {
      $current = [];
      $currentIds = [];
      foreach ($readback->entries as $entry) {
        $normalized = EntryNormalizer::normalize($entry, $timezone);
        if (!isset($wanted[$normalized['local_date']])) {
          continue;
        }
        if ($normalized['timing_entry_id'] === NULL) {
          throw new InvalidArgumentException('Current Timing readback row is missing its stable identifier.');
        }
        if (isset($currentIds[$normalized['timing_entry_id']])) {
          throw new InvalidArgumentException('Current Timing readback contains a duplicate identifier: ' . $normalized['timing_entry_id']);
        }
        $currentIds[$normalized['timing_entry_id']] = TRUE;
        $current[] = $normalized;
      }

      $export = [];
      foreach ($exportEntries as $entry) {
        $normalized = EntryNormalizer::normalize($entry, $timezone);
        if (isset($wanted[$normalized['local_date']])) {
          $export[] = $normalized;
        }
      }
    }
    catch (\Throwable $exception) {
      return [
        'status' => 'invalid_readback',
        'reason' => $exception->getMessage(),
      ];
    }

    $currentMultiset = $this->fingerprintMultiset($current);
    $exportMultiset = $this->fingerprintMultiset($export);
    if ($currentMultiset !== $exportMultiset) {
      return [
        'status' => 'filtered_or_incomplete_export',
        'reason' => 'The export rows do not exactly match the current active Timing entries for the requested dates.',
      ];
    }

    return [
      'status' => 'complete',
      'current_entries' => $current,
      'export_entries' => $export,
      'current_multiset' => $currentMultiset,
    ];
  }

  /**
   * @param array<string, mixed> $pending
   * @param array<int, array<string, mixed>> $currentEntries
   * @param array<int, array<string, mixed>> $exportEntries
   * @return array<string, mixed>
   */
  private function analyze(array $pending, array $currentEntries, array $exportEntries, DateTimeZone $timezone): array {
    $proposals = $pending['proposed_entries'] ?? NULL;
    if (!is_array($proposals) || !array_is_list($proposals) || $proposals === []) {
      return $this->ambiguous('The pending manifest has no proposed entries.', []);
    }

    $baselines = [];
    $capturedIds = [];
    try {
      foreach ($proposals as $index => $proposal) {
        if (!is_array($proposal)) {
          throw new InvalidArgumentException('Proposal ' . ($index + 1) . ' is not an object.');
        }
        $values = is_array($proposal['proposed_values'] ?? NULL) ? $proposal['proposed_values'] : $proposal;
        foreach (['project', 'primary_jira_key', 'title', 'notes', 'verified_timing_entry_id'] as $field) {
          if (!array_key_exists($field, $values) && array_key_exists($field, $proposal)) {
            $values[$field] = $proposal[$field];
          }
        }
        if (!array_key_exists('duration_seconds', $values)) {
          $values['duration_seconds'] = $proposal['normalized_duration_seconds'] ?? NULL;
        }
        $baseline = EntryNormalizer::normalize($values, $timezone);
        $baseline['proposal_id'] = (string) ($proposal['proposal_id'] ?? 'proposal-' . ($index + 1));
        $baseline['source_proposal'] = $proposal;
        if ($baseline['timing_entry_id'] === NULL) {
          throw new InvalidArgumentException('Proposal ' . $baseline['proposal_id'] . ' lacks verified_timing_entry_id.');
        }
        if (isset($capturedIds[$baseline['timing_entry_id']])) {
          throw new InvalidArgumentException('Pending manifest repeats Timing identifier ' . $baseline['timing_entry_id'] . '.');
        }
        $capturedIds[$baseline['timing_entry_id']] = TRUE;
        $baselines[$baseline['proposal_id']] = $baseline;
      }
    }
    catch (\Throwable $exception) {
      return $this->ambiguous($exception->getMessage(), []);
    }

    $currentById = [];
    foreach ($currentEntries as $entry) {
      $currentById[$entry['timing_entry_id']] = $entry;
    }
    $exportFingerprintCounts = $this->fingerprintMultiset($exportEntries);
    $active = [];
    $missing = [];
    $ambiguities = [];
    foreach ($baselines as $proposalId => $baseline) {
      $id = $baseline['timing_entry_id'];
      if (isset($currentById[$id])) {
        $final = $currentById[$id];
        $fingerprint = EntryNormalizer::fingerprint($final);
        if (($exportFingerprintCounts[$fingerprint] ?? 0) !== 1) {
          $ambiguities[] = 'Proposal ' . $proposalId . ' matches duplicate, indistinguishable export rows.';
          continue;
        }
        $active[$proposalId] = $final;
      }
      else {
        $missing[$proposalId] = $baseline;
      }
    }
    if ($ambiguities !== []) {
      return $this->ambiguous('At least one captured Timing entry could not be correlated uniquely with the export.', $ambiguities);
    }

    [$hasPreexistingSnapshot, $preexistingIds] = $this->preexistingIds($pending);
    $extraEntries = [];
    foreach ($currentById as $id => $entry) {
      if (!isset($capturedIds[$id]) && !isset($preexistingIds[$id])) {
        $extraEntries[$id] = $entry;
      }
    }

    $proposalCandidates = [];
    $candidateProposals = [];
    foreach ($baselines as $proposalId => $baseline) {
      foreach ($extraEntries as $entryId => $entry) {
        if ($this->related($baseline, $entry)) {
          $proposalCandidates[$proposalId][] = $entryId;
          $candidateProposals[$entryId][] = $proposalId;
        }
      }
      $proposalCandidates[$proposalId] ??= [];
    }

    if (!$hasPreexistingSnapshot) {
      foreach ($proposalCandidates as $proposalId => $candidates) {
        if ($candidates !== []) {
          $ambiguities[] = 'Proposal ' . $proposalId . ' has related rows, but the pending manifest did not preserve the pre-review Timing identifier set.';
        }
      }
      if ($ambiguities !== []) {
        return $this->ambiguous('Related replacement, split, merged, or manual entries cannot be distinguished from pre-existing rows.', $ambiguities);
      }
    }

    $outcomes = [];
    $usedExtras = [];
    $resolvedMissing = [];

    // Resolve strict many-proposals-to-one-entry merges first.
    foreach ($candidateProposals as $entryId => $proposalIds) {
      $missingIds = array_values(array_filter($proposalIds, static fn(string $id): bool => isset($missing[$id])));
      if (count($missingIds) < 2) {
        continue;
      }
      $strict = TRUE;
      foreach ($missingIds as $proposalId) {
        if (($proposalCandidates[$proposalId] ?? []) !== [$entryId]) {
          $strict = FALSE;
          break;
        }
      }
      if (!$strict || !$this->mergedIntervalIsDefensible($missingIds, $baselines, $extraEntries[$entryId])) {
        continue;
      }
      foreach ($missingIds as $proposalId) {
        $outcomes[$proposalId] = $this->specialOutcome(
          $baselines[$proposalId],
          'merged_with_another_entry',
          [$extraEntries[$entryId]],
          'Captured identifiers are absent; one new current entry uniquely covers multiple related proposals.',
        );
        $resolvedMissing[$proposalId] = TRUE;
      }
      $usedExtras[$entryId] = TRUE;
    }

    // Resolve strict one-proposal-to-many-entry splits.
    foreach ($missing as $proposalId => $baseline) {
      if (isset($resolvedMissing[$proposalId])) {
        continue;
      }
      $candidateIds = $proposalCandidates[$proposalId] ?? [];
      if (count($candidateIds) < 2) {
        continue;
      }
      $strict = TRUE;
      foreach ($candidateIds as $entryId) {
        if (($candidateProposals[$entryId] ?? []) !== [$proposalId]) {
          $strict = FALSE;
          break;
        }
      }
      if (!$strict || !$this->splitIntervalsAreDefensible($baseline, $candidateIds, $extraEntries)) {
        continue;
      }
      $related = array_map(static fn(string $id): array => $extraEntries[$id], $candidateIds);
      $outcomes[$proposalId] = $this->specialOutcome(
        $baseline,
        'split_into_multiple_entries',
        $related,
        'The captured identifier is absent; multiple new current entries uniquely partition its interval and context.',
      );
      $resolvedMissing[$proposalId] = TRUE;
      foreach ($candidateIds as $entryId) {
        $usedExtras[$entryId] = TRUE;
      }
    }

    // Resolve strict replacements and confirmed deletions.
    foreach ($missing as $proposalId => $baseline) {
      if (isset($resolvedMissing[$proposalId])) {
        continue;
      }
      $candidateIds = array_values(array_filter(
        $proposalCandidates[$proposalId] ?? [],
        static fn(string $id): bool => !isset($usedExtras[$id]),
      ));
      if ($candidateIds === []) {
        $outcomes[$proposalId] = $this->specialOutcome(
          $baseline,
          'deleted',
          [],
          'The captured identifier is absent and the complete current Timing readback contains no defensibly related replacement.',
        );
        $resolvedMissing[$proposalId] = TRUE;
        continue;
      }
      if (count($candidateIds) === 1 && ($candidateProposals[$candidateIds[0]] ?? []) === [$proposalId]) {
        $entryId = $candidateIds[0];
        $outcomes[$proposalId] = $this->specialOutcome(
          $baseline,
          'replaced_or_manually_added_entry',
          [$extraEntries[$entryId]],
          'The captured identifier is absent and one new current entry uniquely matches its date, context, and interval.',
          'replacement',
        );
        $resolvedMissing[$proposalId] = TRUE;
        $usedExtras[$entryId] = TRUE;
        continue;
      }
      $ambiguities[] = 'Missing proposal ' . $proposalId . ' has a non-unique related-entry graph.';
    }

    // Existing captured rows can also be accompanied by defensibly new entries.
    $activeAdditions = [];
    foreach ($extraEntries as $entryId => $entry) {
      if (isset($usedExtras[$entryId])) {
        continue;
      }
      $relatedActive = array_values(array_filter(
        $candidateProposals[$entryId] ?? [],
        static fn(string $proposalId): bool => isset($active[$proposalId]),
      ));
      if (count($relatedActive) > 1) {
        $ambiguities[] = 'New Timing entry ' . $entryId . ' is related to more than one active proposal.';
      }
      elseif (count($relatedActive) === 1) {
        $activeAdditions[$relatedActive[0]][] = $entry;
        $usedExtras[$entryId] = TRUE;
      }
    }

    if ($ambiguities !== []) {
      return $this->ambiguous('The comparison has non-unique relationships.', $ambiguities);
    }

    foreach ($active as $proposalId => $final) {
      if (($activeAdditions[$proposalId] ?? []) !== []) {
        $related = array_merge([$final], $activeAdditions[$proposalId]);
        $outcomes[$proposalId] = $this->specialOutcome(
          $baselines[$proposalId],
          'replaced_or_manually_added_entry',
          $related,
          'The captured entry remains and one or more new entries uniquely match its date, context, and interval.',
          'accompanied_by_manual_addition',
        );
      }
      else {
        $outcomes[$proposalId] = $this->changedOutcome($baselines[$proposalId], $final);
      }
    }

    ksort($outcomes, SORT_STRING);
    return [
      'status' => 'reviewed',
      'outcomes' => array_values($outcomes),
      'metrics' => $this->metrics(array_values($outcomes)),
      'ambiguities' => [],
    ];
  }

  /**
   * @param array<string, mixed> $baseline
   * @param array<string, mixed> $final
   * @return array<string, mixed>
   */
  private function changedOutcome(array $baseline, array $final): array {
    $changes = [];
    $unchanged = [];
    foreach (['project', 'primary_jira_key', 'title', 'notes', 'start', 'end', 'duration_seconds', 'billing_status'] as $field) {
      if ($baseline[$field] !== $final[$field]) {
        $changes[$field] = ['from' => $baseline[$field], 'to' => $final[$field]];
      }
      else {
        $unchanged[] = $field;
      }
    }
    $secondary = [];
    $reassigned = isset($changes['project']) || isset($changes['primary_jira_key']);
    $timeChanged = isset($changes['start']) || isset($changes['end']) || isset($changes['duration_seconds']) || isset($changes['billing_status']);
    $textChanged = isset($changes['title']) || isset($changes['notes']);
    if ($reassigned) {
      $outcome = 'jira_issue_or_timing_project_reassigned';
      if ($timeChanged) {
        $secondary[] = 'time_duration_or_billing_changed';
      }
      if ($textChanged) {
        $secondary[] = 'title_or_notes_edited';
      }
    }
    elseif ($timeChanged) {
      $outcome = 'time_duration_or_billing_changed';
      if ($textChanged) {
        $secondary[] = 'title_or_notes_edited';
      }
    }
    elseif ($textChanged) {
      $outcome = 'title_or_notes_edited';
    }
    else {
      $outcome = 'accepted_unchanged';
    }

    $changes['unchanged'] = $unchanged;
    return [
      'proposal_id' => $baseline['proposal_id'],
      'verified_timing_entry_id' => $baseline['timing_entry_id'],
      'outcome' => $outcome,
      'secondary_changes' => $secondary,
      'confidence' => 'high',
      'match_method' => 'Captured Timing identifier read back from the current database, then correlated to exactly one export row by date, start, end, project, title, notes, billing status, and duration.',
      'original_values' => EntryNormalizer::publicValues($baseline),
      'final_values' => EntryNormalizer::publicValues($final),
      'changes' => $changes,
      'related_final_entries' => [],
      'retrieval_context' => $this->retrievalContext($baseline),
    ];
  }

  /**
   * @param array<string, mixed> $baseline
   * @param array<int, array<string, mixed>> $related
   * @return array<string, mixed>
   */
  private function specialOutcome(array $baseline, string $outcome, array $related, string $method, ?string $mode = NULL): array {
    $finalValues = count($related) === 1 ? EntryNormalizer::publicValues($related[0]) : NULL;
    $result = [
      'proposal_id' => $baseline['proposal_id'],
      'verified_timing_entry_id' => $baseline['timing_entry_id'],
      'outcome' => $outcome,
      'secondary_changes' => [],
      'confidence' => 'high',
      'match_method' => $method,
      'original_values' => EntryNormalizer::publicValues($baseline),
      'final_values' => $finalValues,
      'changes' => $finalValues === NULL ? [] : $this->valueChanges($baseline, $related[0]),
      'related_final_entries' => array_map(static function (array $entry): array {
        return ['timing_entry_id' => $entry['timing_entry_id']] + EntryNormalizer::publicValues($entry);
      }, $related),
      'retrieval_context' => $this->retrievalContext($baseline),
    ];
    if ($mode !== NULL) {
      $result['mode'] = $mode;
    }
    return $result;
  }

  /**
   * @param array<string, mixed> $pending
   * @param array<string, mixed> $export
   * @param array<string, mixed> $comparison
   * @param array<string, mixed> $analysis
   * @param array<string, mixed> $priorReviewed
   * @return array<string, mixed>
   */
  private function buildReviewedManifest(
    array $pending,
    string $pendingPath,
    string $pendingSha,
    array $export,
    TimingReadback $readback,
    array $comparison,
    array $analysis,
    array $priorReviewed,
  ): array {
    $runId = (string) $pending['run_id'];
    $priorExports = is_array($priorReviewed['prior_review_exports'] ?? NULL) ? $priorReviewed['prior_review_exports'] : [];
    if (isset($priorReviewed['review_export']['sha256']) && $priorReviewed['review_export']['sha256'] !== $export['sha256']) {
      $priorExports[] = $priorReviewed['review_export'];
    }

    $outcomes = array_map(static function (array $outcome) use ($export): array {
      $outcome['export_sha256'] = $export['sha256'];
      return $outcome;
    }, $analysis['outcomes']);
    $reviewed = [
      'schema_version' => self::SCHEMA_VERSION,
      'run_id' => $runId,
      'status' => 'reviewed',
      'reviewed_at' => $this->now(),
      'requested_local_dates' => array_values($pending['requested_local_dates']),
      'timezone' => (string) $pending['timezone'],
      'pending_manifest' => [
        'path' => $pendingPath,
        'sha256' => $pendingSha,
        'preserved_unchanged' => TRUE,
      ],
      'prior_review_exports' => $priorExports,
      'review_export' => [
        'path' => $export['path'],
        'sha256' => $export['sha256'],
        'mtime' => $export['mtime'],
        'row_count' => count($export['entries']),
        'local_dates' => array_values($pending['requested_local_dates']),
        'schema_valid' => TRUE,
        'duration_arithmetic_valid' => TRUE,
      ],
      'current_timing_readback' => [
        'source' => $readback->source,
        'method' => $readback->method,
        'active_row_count' => count($comparison['current_entries']),
        'export_row_count' => count($comparison['export_entries']),
        'exact_requested_dates_multiset_match' => TRUE,
        'captured_timing_entry_ids' => array_values(array_filter(array_map(
          static fn(array $outcome): mixed => $outcome['verified_timing_entry_id'] ?? NULL,
          $analysis['outcomes'],
        ))),
      ],
      'outcomes' => $outcomes,
      'metrics' => $analysis['metrics'],
      'retrieval_examples' => $this->retrievalExamples($runId, $outcomes),
      'remote_writes' => [
        'jira' => 'none',
        'tempo' => 'none',
      ],
    ];
    return $reviewed;
  }

  /**
   * Promote only uncontradicted patterns observed in at least three runs.
   *
   * @return array{path: string, sha256: string, updated: bool, reason: string}
   */
  private function updatePreferences(): array {
    $path = $this->store->root() . '/preferences.json';
    $state = $this->store->readObject($path, [
      'schema_version' => self::SCHEMA_VERSION,
      'updated_at' => NULL,
      'preferences' => [],
    ]);
    $preferences = is_array($state['preferences'] ?? NULL) ? $state['preferences'] : [];
    $groups = [];
    $reviewedPaths = glob($this->store->reviewedDirectory() . '/*.json') ?: [];
    sort($reviewedPaths, SORT_STRING);
    foreach ($reviewedPaths as $reviewedPath) {
      $reviewed = $this->store->readObject($reviewedPath);
      $runId = (string) ($reviewed['run_id'] ?? '');
      foreach (($reviewed['outcomes'] ?? []) as $outcome) {
        if (($outcome['outcome'] ?? '') !== 'jira_issue_or_timing_project_reassigned') {
          continue;
        }
        $original = $outcome['original_values'] ?? [];
        $final = $outcome['final_values'] ?? [];
        $from = (string) ($original['primary_jira_key'] ?? '');
        $to = (string) ($final['primary_jira_key'] ?? '');
        $project = (string) ($final['project'] ?? $original['project'] ?? '');
        if ($from === '' || $to === '' || $from === $to || $project === '') {
          continue;
        }
        $scope = $project . "\0" . $from;
        $entryId = (string) ($outcome['verified_timing_entry_id'] ?? '');
        if ($runId === '' || $entryId === '') {
          continue;
        }
        $groups[$scope][$to]['runs'][$runId] = $reviewedPath;
        $groups[$scope][$to]['entry_ids'][$entryId] = TRUE;
      }
    }

    $existingById = [];
    foreach ($preferences as $index => $preference) {
      if (is_array($preference) && isset($preference['preference_id'])) {
        $existingById[(string) $preference['preference_id']] = $index;
      }
    }
    $changed = !is_file($path);
    foreach ($preferences as $index => $preference) {
      if (($preference['source']['type'] ?? NULL) !== 'repeated_reviewed_correction') {
        continue;
      }
      $project = (string) ($preference['scope']['timing_project'] ?? '');
      $from = (string) ($preference['scope']['original_jira_issue'] ?? '');
      $preferred = (string) ($preference['ranking']['prefer'] ?? '');
      $targets = $groups[$project . "\0" . $from] ?? [];
      $stillSupported = count($targets) === 1
        && array_key_first($targets) === $preferred
        && count($targets[$preferred]['runs'] ?? []) >= 3
        && count($targets[$preferred]['entry_ids'] ?? []) >= 3;
      if (!$stillSupported && ($preference['status'] ?? NULL) !== 'inactive_contradicted') {
        $preferences[$index]['status'] = 'inactive_contradicted';
        $preferences[$index]['confidence'] = 'low';
        $preferences[$index]['source']['status_reason'] = 'The reviewed evidence no longer contains three independent, uncontradicted runs and Timing entries for this correction.';
        $changed = TRUE;
      }
    }
    foreach ($groups as $scope => $targets) {
      if (count($targets) !== 1) {
        continue;
      }
      $target = array_key_first($targets);
      $runs = $targets[$target]['runs'];
      $entryIds = $targets[$target]['entry_ids'];
      if (count($runs) < 3 || count($entryIds) < 3) {
        continue;
      }
      [$project, $from] = explode("\0", $scope, 2);
      $id = 'learned-reassignment-' . substr(hash('sha256', $scope . "\0" . $target), 0, 16);
      $preference = [
        'preference_id' => $id,
        'status' => 'active',
        'scope' => [
          'timing_project' => $project,
          'original_jira_issue' => $from,
        ],
        'ranking' => [
          'prefer' => $target,
          'over' => $from,
          'when' => 'Current evidence leaves both issues otherwise plausible.',
        ],
        'guardrails' => [
          'Current evidence and explicit user direction outrank this preference.',
          'Do not weaken evidence, continuity, rounding, privacy, duplicate, database, or authorization safeguards.',
        ],
        'confidence' => 'high',
        'source' => [
          'type' => 'repeated_reviewed_correction',
          'independent_run_count' => count($runs),
          'independent_timing_entry_count' => count($entryIds),
          'run_ids' => array_keys($runs),
          'reviewed_manifests' => array_values($runs),
          'summary' => sprintf('The same %s to %s reassignment was accepted in at least three independent reviewed runs without contradiction.', $from, $target),
        ],
      ];
      if (!isset($existingById[$id])) {
        $preferences[] = $preference;
        $changed = TRUE;
      }
      elseif ($preferences[$existingById[$id]] !== $preference) {
        $preferences[$existingById[$id]] = $preference;
        $changed = TRUE;
      }
    }

    if ($changed) {
      $state = [
        'schema_version' => self::SCHEMA_VERSION,
        'updated_at' => $this->now(),
        'preferences' => array_values($preferences),
      ];
      $this->store->writeObject($path, $state);
    }
    $sha = hash_file('sha256', $path);
    if ($sha === FALSE) {
      throw new RuntimeException('Could not hash preferences state: ' . $path);
    }
    return [
      'path' => $path,
      'sha256' => $sha,
      'updated' => $changed,
      'reason' => $changed
        ? 'Preferences were initialized or promoted from at least three independent, uncontradicted reviewed runs.'
        : 'Corrections remain retrieval examples until the playbook promotion threshold is met.',
    ];
  }

  /**
   * @param array<int, array<string, mixed>> $outcomes
   * @return array<int, array<string, mixed>>
   */
  private function retrievalExamples(string $runId, array $outcomes): array {
    return array_map(static function (array $outcome) use ($runId): array {
      $original = $outcome['original_values'] ?? [];
      $context = is_array($outcome['retrieval_context'] ?? NULL) ? $outcome['retrieval_context'] : [];
      $final = $outcome['final_values'] ?? NULL;
      if (!is_array($final) && !empty($outcome['related_final_entries'][0])) {
        $final = $outcome['related_final_entries'][0];
      }
      $final = is_array($final) ? $final : [];
      $duration = (int) ($final['duration_seconds'] ?? $original['duration_seconds'] ?? 0);
      return [
        'example_id' => $runId . ':' . ($outcome['proposal_id'] ?? 'unknown'),
        'run_id' => $runId,
        'proposal_id' => $outcome['proposal_id'] ?? NULL,
        'outcome' => $outcome['outcome'] ?? NULL,
        'facets' => [
          'timing_project' => $final['project'] ?? $original['project'] ?? '',
          'original_jira_issue' => $original['primary_jira_key'] ?? '',
          'final_jira_issue' => $final['primary_jira_key'] ?? '',
          'billing_status' => $final['billing_status'] ?? $original['billing_status'] ?? 'unspecified',
          'duration_shape' => self::durationShape($duration),
          'issue_type' => $context['issue_type'] ?? '',
          'entry_type' => $context['entry_type'] ?? 'timing_entry',
          'repository_signatures' => $context['repository_signatures'] ?? [],
          'activity_signatures' => $context['activity_signatures'] ?? [],
          'wording_pattern' => $context['wording_pattern'] ?? '',
        ],
        'original_values' => $original,
        'final_values' => $outcome['final_values'] ?? NULL,
        'related_final_entries' => $outcome['related_final_entries'] ?? [],
      ];
    }, $outcomes);
  }

  /**
   * @param array<int, array<string, mixed>> $outcomes
   * @return array<string, int>
   */
  private function metrics(array $outcomes): array {
    $metrics = [
      'proposal_count' => count($outcomes),
      'accepted_unchanged_count' => 0,
      'title_or_notes_edited_count' => 0,
      'reassigned_count' => 0,
      'time_duration_or_billing_changed_count' => 0,
      'deleted_count' => 0,
      'split_count' => 0,
      'merged_count' => 0,
      'replaced_or_manually_added_count' => 0,
      'duration_delta_seconds' => 0,
      'unresolved_count' => 0,
    ];
    $map = [
      'accepted_unchanged' => 'accepted_unchanged_count',
      'title_or_notes_edited' => 'title_or_notes_edited_count',
      'jira_issue_or_timing_project_reassigned' => 'reassigned_count',
      'time_duration_or_billing_changed' => 'time_duration_or_billing_changed_count',
      'deleted' => 'deleted_count',
      'split_into_multiple_entries' => 'split_count',
      'merged_with_another_entry' => 'merged_count',
      'replaced_or_manually_added_entry' => 'replaced_or_manually_added_count',
    ];
    $originalDuration = 0;
    $finalDuration = 0;
    $seenFinalIds = [];
    foreach ($outcomes as $outcome) {
      $kind = (string) ($outcome['outcome'] ?? '');
      if (isset($map[$kind])) {
        $metrics[$map[$kind]]++;
      }
      foreach (($outcome['secondary_changes'] ?? []) as $secondary) {
        if (isset($map[$secondary])) {
          $metrics[$map[$secondary]]++;
        }
      }
      $originalDuration += (int) ($outcome['original_values']['duration_seconds'] ?? 0);
      $related = is_array($outcome['related_final_entries'] ?? NULL) ? $outcome['related_final_entries'] : [];
      if ($related !== []) {
        foreach ($related as $entry) {
          $id = (string) ($entry['timing_entry_id'] ?? '');
          if ($id !== '' && !isset($seenFinalIds[$id])) {
            $seenFinalIds[$id] = TRUE;
            $finalDuration += (int) ($entry['duration_seconds'] ?? 0);
          }
        }
      }
      elseif (is_array($outcome['final_values'] ?? NULL)) {
        $id = (string) ($outcome['verified_timing_entry_id'] ?? $outcome['proposal_id'] ?? '');
        if (!isset($seenFinalIds[$id])) {
          $seenFinalIds[$id] = TRUE;
          $finalDuration += (int) ($outcome['final_values']['duration_seconds'] ?? 0);
        }
      }
    }
    $metrics['duration_delta_seconds'] = $finalDuration - $originalDuration;
    return $metrics;
  }

  /**
   * @param array<string, mixed> $pending
   * @return array{0: bool, 1: array<string, bool>}
   */
  private function preexistingIds(array $pending): array {
    $hasSnapshot = FALSE;
    $ids = [];
    foreach ([['source_state', 'existing_timing_entry_ids'], ['review_context', 'preexisting_timing_entry_ids']] as [$section, $field]) {
      if (isset($pending[$section]) && is_array($pending[$section]) && array_key_exists($field, $pending[$section])) {
        $hasSnapshot = TRUE;
        if (!is_array($pending[$section][$field])) {
          throw new InvalidArgumentException($section . '.' . $field . ' must be a list.');
        }
        foreach ($pending[$section][$field] as $id) {
          $ids[(string) $id] = TRUE;
        }
      }
    }
    return [$hasSnapshot, $ids];
  }

  /**
   * @param array<string, mixed> $baseline
   * @param array<string, mixed> $entry
   */
  private function related(array $baseline, array $entry): bool {
    if ($baseline['local_date'] !== $entry['local_date']) {
      return FALSE;
    }
    $sameProject = $baseline['project'] !== '' && $baseline['project'] === $entry['project'];
    $sameIssue = $baseline['primary_jira_key'] !== '' && $baseline['primary_jira_key'] === $entry['primary_jira_key'];
    if (!$sameProject && !$sameIssue) {
      return FALSE;
    }
    $overlap = min($baseline['end_epoch'], $entry['end_epoch']) - max($baseline['start_epoch'], $entry['start_epoch']);
    $nearBoundaries = abs($baseline['start_epoch'] - $entry['start_epoch']) <= 300
      && abs($baseline['end_epoch'] - $entry['end_epoch']) <= 300;
    return $overlap > 0 || $nearBoundaries;
  }

  /**
   * @param string[] $proposalIds
   * @param array<string, array<string, mixed>> $baselines
   * @param array<string, mixed> $entry
   */
  private function mergedIntervalIsDefensible(array $proposalIds, array $baselines, array $entry): bool {
    $starts = [];
    $ends = [];
    foreach ($proposalIds as $proposalId) {
      $starts[] = $baselines[$proposalId]['start_epoch'];
      $ends[] = $baselines[$proposalId]['end_epoch'];
    }
    return $entry['start_epoch'] <= min($starts) + 60 && $entry['end_epoch'] >= max($ends) - 60;
  }

  /**
   * @param array<string, mixed> $baseline
   * @param string[] $candidateIds
   * @param array<string, array<string, mixed>> $entries
   */
  private function splitIntervalsAreDefensible(array $baseline, array $candidateIds, array $entries): bool {
    $lastEnd = NULL;
    $parts = array_map(static fn(string $id): array => $entries[$id], $candidateIds);
    usort($parts, static fn(array $a, array $b): int => $a['start_epoch'] <=> $b['start_epoch']);
    foreach ($parts as $part) {
      if ($part['start_epoch'] < $baseline['start_epoch'] - 60 || $part['end_epoch'] > $baseline['end_epoch'] + 60) {
        return FALSE;
      }
      if ($lastEnd !== NULL && $part['start_epoch'] < $lastEnd) {
        return FALSE;
      }
      $lastEnd = $part['end_epoch'];
    }
    return TRUE;
  }

  /**
   * @param array<string, mixed> $from
   * @param array<string, mixed> $to
   * @return array<string, mixed>
   */
  private function valueChanges(array $from, array $to): array {
    $changes = [];
    $unchanged = [];
    foreach (['project', 'primary_jira_key', 'title', 'notes', 'start', 'end', 'duration_seconds', 'billing_status'] as $field) {
      if ($from[$field] !== $to[$field]) {
        $changes[$field] = ['from' => $from[$field], 'to' => $to[$field]];
      }
      else {
        $unchanged[] = $field;
      }
    }
    $changes['unchanged'] = $unchanged;
    return $changes;
  }

  /**
   * Preserve explicitly recorded retrieval facets without synthesizing them.
   *
   * @param array<string, mixed> $baseline
   * @return array<string, mixed>
   */
  private function retrievalContext(array $baseline): array {
    $proposal = is_array($baseline['source_proposal'] ?? NULL) ? $baseline['source_proposal'] : [];
    $facets = is_array($proposal['retrieval_facets'] ?? NULL) ? $proposal['retrieval_facets'] : [];
    $context = [];
    foreach (['issue_type', 'entry_type', 'wording_pattern'] as $field) {
      $value = $facets[$field] ?? $proposal[$field] ?? NULL;
      if (is_string($value) && $value !== '') {
        $context[$field] = $value;
      }
    }
    foreach (['repository_signatures', 'activity_signatures'] as $field) {
      $value = $facets[$field] ?? $proposal[$field] ?? [];
      if (is_array($value)) {
        $context[$field] = array_values(array_unique(array_map('strval', $value)));
      }
    }
    return $context;
  }

  /**
   * @param array<int, array<string, mixed>> $entries
   * @return array<string, int>
   */
  private function fingerprintMultiset(array $entries): array {
    $multiset = [];
    foreach ($entries as $entry) {
      $fingerprint = EntryNormalizer::fingerprint($entry);
      $multiset[$fingerprint] = ($multiset[$fingerprint] ?? 0) + 1;
    }
    ksort($multiset, SORT_STRING);
    return $multiset;
  }

  /**
   * @param array<int, array<string, mixed>> $receipts
   */
  private function hasReceipt(array $receipts, string $runId, string $exportSha): bool {
    foreach ($receipts as $receipt) {
      if (is_array($receipt) && ($receipt['run_id'] ?? NULL) === $runId && ($receipt['export_sha256'] ?? NULL) === $exportSha) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function receiptKey(string $runId, string $exportSha): string {
    return 'sha256:' . hash('sha256', $exportSha . "\0" . $runId);
  }

  private function samePath(string $expected, string $actual): bool {
    $expectedPath = str_starts_with($expected, '/') ? $expected : $this->projectRoot . '/' . ltrim($expected, '/');
    return (realpath($expectedPath) ?: $expectedPath) === (realpath($actual) ?: $actual);
  }

  /**
   * @param array<string, mixed> $object
   */
  private function requireString(array $object, string $field, string $source): string {
    if (!isset($object[$field]) || !is_string($object[$field]) || trim($object[$field]) === '') {
      throw new InvalidArgumentException($source . ' requires non-empty ' . $field . '.');
    }
    return $object[$field];
  }

  /**
   * @param string[] $ambiguities
   * @return array<string, mixed>
   */
  private function ambiguous(string $reason, array $ambiguities): array {
    return [
      'status' => 'ambiguous',
      'reason' => $reason,
      'ambiguities' => $ambiguities,
    ];
  }

  /**
   * @param array<int, array<string, mixed>> $runs
   * @return array<string, mixed>
   */
  private function summary(string $status, array $runs, ?string $exportSha): array {
    return [
      'status' => $status,
      'export_sha256' => $exportSha,
      'runs' => $runs,
      'reviewed_count' => count(array_filter($runs, static fn(array $run): bool => ($run['status'] ?? '') === 'reviewed')),
      'no_op_count' => count(array_filter($runs, static fn(array $run): bool => ($run['status'] ?? '') === 'no_op_already_processed')),
      'pending_count' => count(array_filter($runs, static fn(array $run): bool => str_starts_with((string) ($run['status'] ?? ''), 'pending_'))),
      'remote_writes' => [
        'jira' => 'none',
        'tempo' => 'none',
      ],
    ];
  }

  private function now(): string {
    return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('c');
  }

  private static function durationShape(int $seconds): string {
    return match (TRUE) {
      $seconds < 900 => 'under_15_minutes',
      $seconds === 900 => '15_minutes',
      $seconds < 3600 => '15_to_59_minutes',
      default => 'one_hour_or_more',
    };
  }

}
