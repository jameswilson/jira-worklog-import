<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/ReviewLearning/bootstrap.php';

use JiraWorklogImport\ReviewLearning\AtomicJsonStore;
use JiraWorklogImport\ReviewLearning\FixedClock;
use JiraWorklogImport\ReviewLearning\JsonTimingEntryProvider;
use JiraWorklogImport\ReviewLearning\ReviewLearningEngine;
use JiraWorklogImport\ReviewLearning\ReviewLearningRepository;
use JiraWorklogImport\ReviewLearning\SqliteTimingEntryProvider;
use JiraWorklogImport\ReviewLearning\TimingEntryProvider;
use JiraWorklogImport\ReviewLearning\TimingReadback;

final class ReviewLearningTestSuite {

  private int $assertions = 0;

  /** @var string[] */
  private array $temporaryDirectories = [];

  public function run(): void {
    try {
      $this->testAllOutcomesAndNoOp();
      $this->testFailClosedFixtures();
      $this->testCurrentReadbackAndManualAdditionProvenanceAreRequired();
      $this->testPreferencePromotionThreshold();
      $this->testConcurrentProcessing();
      $this->testStandaloneLearningDoesNotLoadJira();
      $this->testSqliteProviderIsReadOnly();
      $this->testImporterHookOrder();
      print 'OK (' . $this->assertions . " assertions)\n";
    }
    finally {
      foreach (array_reverse($this->temporaryDirectories) as $directory) {
        $this->removeTree($directory);
      }
    }
  }

  private function testAllOutcomesAndNoOp(): void {
    $context = $this->fixtureContext('all-outcomes');
    $pendingHash = hash_file('sha256', $context['pending']);
    $engine = $this->engine($context);

    $result = $engine->process($context['export']);
    $this->same('completed', $result['status'], 'all-outcomes run completes');
    $this->same(1, $result['reviewed_count'], 'one run reviewed');
    $this->same($pendingHash, hash_file('sha256', $context['pending']), 'pending manifest is immutable');

    $reviewedPath = $context['root'] . '/worklogs/learning/reviewed/fixture-all-outcomes.json';
    $reviewed = $this->readJson($reviewedPath);
    $byProposal = [];
    foreach ($reviewed['outcomes'] as $outcome) {
      $byProposal[$outcome['proposal_id']] = $outcome;
    }
    $expected = [
      'accepted' => 'accepted_unchanged',
      'edited' => 'title_or_notes_edited',
      'reassigned' => 'jira_issue_or_timing_project_reassigned',
      'time-changed' => 'time_duration_or_billing_changed',
      'duration-changed' => 'time_duration_or_billing_changed',
      'billing-changed' => 'time_duration_or_billing_changed',
      'deleted' => 'deleted',
      'split' => 'split_into_multiple_entries',
      'merge-a' => 'merged_with_another_entry',
      'merge-b' => 'merged_with_another_entry',
      'replaced' => 'replaced_or_manually_added_entry',
      'manual-addition' => 'replaced_or_manually_added_entry',
    ];
    foreach ($expected as $proposal => $outcome) {
      $this->same($outcome, $byProposal[$proposal]['outcome'] ?? NULL, $proposal . ' outcome');
    }
    $this->same('replacement', $byProposal['replaced']['mode'], 'replacement is distinguished');
    $this->same('accompanied_by_manual_addition', $byProposal['manual-addition']['mode'], 'manual addition is distinguished');
    $this->true(str_starts_with($byProposal['manual-addition']['related_final_entries'][0]['start'], '2026-10-05T20:00:00-05:00'), 'UTC-next-day rows use the pending timezone for coverage');
    $this->same($reviewed['review_export']['sha256'], $byProposal['accepted']['export_sha256'], 'each outcome records the export hash');
    $this->same('none', $reviewed['remote_writes']['jira'], 'review records no Jira writes');
    $this->same('none', $reviewed['remote_writes']['tempo'], 'review records no Tempo writes');
    $this->same(-300, $reviewed['metrics']['duration_delta_seconds'], 'duration metric deduplicates split, merged, and manual final entries');

    $receiptsPath = $context['root'] . '/worklogs/learning/export-receipts.json';
    $receipts = $this->readJson($receiptsPath);
    $this->same(1, count($receipts['receipts']), 'one learning receipt written');
    $this->true(str_starts_with($receipts['receipts'][0]['receipt_key'], 'sha256:'), 'receipt has deterministic pair key');
    $reviewedHash = hash_file('sha256', $reviewedPath);

    $again = $engine->process($context['export']);
    $this->same(1, $again['no_op_count'], 'same export hash and run ID is a no-op');
    $this->same(0, $again['reviewed_count'], 'no-op creates no second review');
    $this->same(1, count($this->readJson($receiptsPath)['receipts']), 'no-op creates no second receipt');
    $this->same($reviewedHash, hash_file('sha256', $reviewedPath), 'no-op does not rewrite reviewed state');

    $repository = new ReviewLearningRepository(new AtomicJsonStore($context['root'] . '/worklogs/learning'));
    $retrieved = $repository->retrieve(['jira_issue' => 'CCC-30']);
    $this->true(count($retrieved['examples']) >= 1, 'reviewed examples are retrievable');
    $this->same('reassigned', $retrieved['examples'][0]['example']['proposal_id'], 'retrieval ranks the matching correction');
    $signatureMatch = $repository->retrieve(['repository_signature' => 'project-c-repository']);
    $this->same('reassigned', $signatureMatch['examples'][0]['example']['proposal_id'], 'repository signatures are retrievable');
    $this->true(is_file($context['root'] . '/worklogs/learning/preferences.json'), 'preferences are available for later retrieval');
    $this->same([], $this->readJson($context['root'] . '/worklogs/learning/preferences.json')['preferences'], 'one-off correction is not promoted');
    $this->same([], glob($context['root'] . '/worklogs/learning/.*.tmp-*') ?: [], 'atomic writes leave no temporary files');
  }

  private function testCurrentReadbackAndManualAdditionProvenanceAreRequired(): void {
    $unavailable = $this->fixtureContext('all-outcomes');
    $provider = new class implements TimingEntryProvider {

      public function read(array $localDates, DateTimeZone $timezone): TimingReadback {
        throw new RuntimeException('fixture readback unavailable');
      }

    };
    $engine = new ReviewLearningEngine(
      $unavailable['root'],
      new AtomicJsonStore($unavailable['root'] . '/worklogs/learning'),
      $provider,
      new FixedClock(new DateTimeImmutable('2026-10-27T12:00:00Z')),
    );
    $result = $engine->process($unavailable['export']);
    $this->same('pending_current_timing_unavailable', $result['runs'][0]['status'], 'missing current Timing readback remains pending');
    $this->false(is_file($unavailable['root'] . '/worklogs/learning/export-receipts.json'), 'unavailable readback writes no receipt');

    $unknownBaseline = $this->fixtureContext('all-outcomes');
    $pending = $this->readJson($unknownBaseline['pending']);
    unset($pending['source_state']);
    file_put_contents($unknownBaseline['pending'], json_encode($pending, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $result = $this->engine($unknownBaseline)->process($unknownBaseline['export']);
    $this->same('pending_ambiguous', $result['runs'][0]['status'], 'manual additions without a pre-review ID set remain pending');
    $this->false(is_file($unknownBaseline['root'] . '/worklogs/learning/reviewed/fixture-all-outcomes.json'), 'ambiguous manual additions create no reviewed example');
  }

  private function testFailClosedFixtures(): void {
    $expected = [
      'ambiguous' => 'pending_ambiguous',
      'filtered' => 'pending_filtered_or_incomplete_export',
      'partial' => 'pending_partial_export',
      'stale' => 'pending_stale_export',
    ];
    foreach ($expected as $fixture => $status) {
      $context = $this->fixtureContext($fixture);
      $pendingHash = hash_file('sha256', $context['pending']);
      $result = $this->engine($context)->process($context['export']);
      $this->same($status, $result['runs'][0]['status'] ?? NULL, $fixture . ' export remains pending');
      $this->same(0, $result['reviewed_count'], $fixture . ' creates no learned review');
      $this->same($pendingHash, hash_file('sha256', $context['pending']), $fixture . ' preserves pending manifest');
      $this->same([], glob($context['root'] . '/worklogs/learning/reviewed/*.json') ?: [], $fixture . ' writes no reviewed example');
      $this->false(is_file($context['root'] . '/worklogs/learning/export-receipts.json'), $fixture . ' writes no receipt');
      $this->false(is_file($context['root'] . '/worklogs/learning/preferences.json'), $fixture . ' writes no preferences');
    }
  }

  private function testConcurrentProcessing(): void {
    if (!function_exists('pcntl_fork')) {
      print "SKIP concurrency test: pcntl unavailable\n";
      return;
    }
    $context = $this->fixtureContext('all-outcomes');
    $children = [];
    for ($i = 0; $i < 2; $i++) {
      $pid = pcntl_fork();
      if ($pid === -1) {
        throw new RuntimeException('Could not fork concurrency test.');
      }
      if ($pid === 0) {
        try {
          $this->engine($context)->process($context['export']);
          exit(0);
        }
        catch (Throwable $exception) {
          fwrite(STDERR, $exception->getMessage() . PHP_EOL);
          exit(1);
        }
      }
      $children[] = $pid;
    }
    foreach ($children as $pid) {
      pcntl_waitpid($pid, $status);
      $this->same(0, pcntl_wexitstatus($status), 'concurrent worker exits successfully');
    }
    $receipts = $this->readJson($context['root'] . '/worklogs/learning/export-receipts.json');
    $this->same(1, count($receipts['receipts']), 'concurrent workers write one pair receipt');
    $this->true(is_file($context['root'] . '/worklogs/learning/reviewed/fixture-all-outcomes.json'), 'concurrent review file is valid and present');
  }

  private function testPreferencePromotionThreshold(): void {
    $root = $this->temporaryDirectory();
    mkdir($root . '/files', 0700, TRUE);
    mkdir($root . '/worklogs/learning/pending', 0700, TRUE);
    $export = [];
    $snapshot = [];
    for ($index = 1; $index <= 3; $index++) {
      $hour = 8 + $index;
      $startLocal = sprintf('2026-10-05T%02d:00:00-05:00', $hour);
      $endLocal = sprintf('2026-10-05T%02d:15:00-05:00', $hour);
      $startUtc = (new DateTimeImmutable($startLocal))->setTimezone(new DateTimeZone('UTC'))->format('c');
      $endUtc = (new DateTimeImmutable($endLocal))->setTimezone(new DateTimeZone('UTC'))->format('c');
      $entryId = (string) (800 + $index);
      $pending = [
        'schema_version' => 1,
        'run_id' => 'preference-run-' . $index,
        'created_at' => '2026-01-01T00:00:00Z',
        'requested_local_dates' => ['2026-10-05'],
        'timezone' => 'America/Guayaquil',
        'expected_review_export_path' => 'files/All Activities.json',
        'source_state' => ['existing_timing_entry_ids' => []],
        'proposed_entries' => [[
          'proposal_id' => 'preference-' . $index,
          'verified_timing_entry_id' => $entryId,
          'project' => 'Repeated Project',
          'primary_jira_key' => 'OLD-1',
          'title' => 'OLD-1 - Old assignment',
          'notes' => 'Repeated correction',
          'start' => $startLocal,
          'end' => $endLocal,
          'normalized_duration_seconds' => 900,
          'billing_classification' => 'billable',
        ]],
      ];
      file_put_contents(
        $root . '/worklogs/learning/pending/preference-run-' . $index . '.json',
        json_encode($pending, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
      );
      $row = [
        'project' => 'Repeated Project',
        'title' => 'NEW-2 - Preferred assignment',
        'notes' => 'Repeated correction',
        'duration' => '0:15:00',
        'startDate' => $startUtc,
        'endDate' => $endUtc,
        'billingStatus' => 'Billable',
      ];
      $export[] = $row;
      $snapshot[] = ['timing_entry_id' => $entryId] + $row;
    }
    $exportPath = $root . '/files/All Activities.json';
    $snapshotPath = $root . '/timing-readback.json';
    file_put_contents($exportPath, json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    file_put_contents($snapshotPath, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    touch($exportPath, (new DateTimeImmutable('2026-10-27T00:00:00Z'))->getTimestamp());

    $engine = new ReviewLearningEngine(
      $root,
      new AtomicJsonStore($root . '/worklogs/learning'),
      new JsonTimingEntryProvider($snapshotPath),
      new FixedClock(new DateTimeImmutable('2026-10-27T12:00:00Z')),
    );
    $result = $engine->process($exportPath);
    $this->same(3, $result['reviewed_count'], 'three independent corrections are reviewed');
    $preferences = $this->readJson($root . '/worklogs/learning/preferences.json')['preferences'];
    $this->same(1, count($preferences), 'three independent corrections promote one preference');
    $this->same('active', $preferences[0]['status'], 'promoted preference is active');
    $this->same('high', $preferences[0]['confidence'], 'promoted preference has high confidence');
    $this->same(3, $preferences[0]['source']['independent_run_count'], 'preference links three independent runs');
    $this->same(3, $preferences[0]['source']['independent_timing_entry_count'], 'preference links three independent Timing entries');
  }

  private function testStandaloneLearningDoesNotLoadJira(): void {
    $context = $this->fixtureContext('all-outcomes');
    $script = dirname(__DIR__) . '/review-learning.php';
    $command = implode(' ', [
      escapeshellarg(PHP_BINARY),
      escapeshellarg($script),
      escapeshellarg('--root=' . $context['root']),
      escapeshellarg('--export=' . $context['export']),
      escapeshellarg('--timing-snapshot=' . $context['snapshot']),
      '2>&1',
    ]);
    exec($command, $output, $exitCode);
    $this->same(0, $exitCode, 'standalone learning runs without Composer vendor or Jira client');
    $this->true(str_contains(implode("\n", $output), 'no Jira or Tempo API was invoked'), 'standalone output states remote boundary');
    $source = file_get_contents($script);
    $this->false(str_contains((string) $source, 'vendor/autoload.php'), 'standalone CLI does not load remote client dependencies');
  }

  private function testSqliteProviderIsReadOnly(): void {
    $directory = $this->temporaryDirectory();
    $path = $directory . '/Timing-fixture.db';
    $database = new SQLite3($path);
    $database->exec('CREATE TABLE Project (id INTEGER PRIMARY KEY, title TEXT)');
    $database->exec('CREATE TABLE TaskActivity (id INTEGER PRIMARY KEY, startDate REAL, endDate REAL, projectID INTEGER, title TEXT, notes TEXT, isDeleted INTEGER, property_bag TEXT)');
    $start = (new DateTimeImmutable('2026-10-05T14:00:00Z'))->getTimestamp() - 978307200;
    $end = $start + 900;
    $database->exec("INSERT INTO Project VALUES (1, 'Project A')");
    $database->exec("INSERT INTO TaskActivity VALUES (700, $start, $end, 1, 'AAA-1 - SQLite', 'Read only', 0, '{\"_billing_status\":\"billable\"}')");
    $database->close();
    $before = hash_file('sha256', $path);

    $readback = (new SqliteTimingEntryProvider($path))->read(['2026-10-05'], new DateTimeZone('America/Guayaquil'));
    $this->same(1, count($readback->entries), 'SQLite provider reads fixture entry');
    $this->same('700', $readback->entries[0]['timing_entry_id'], 'SQLite provider preserves stable ID');
    $this->same('sqlite3_readonly_fallback', $readback->method, 'SQLite provider reports read-only method');
    $this->same($before, hash_file('sha256', $path), 'SQLite provider does not mutate database');
  }

  private function testImporterHookOrder(): void {
    $source = file_get_contents(dirname(__DIR__) . '/jira-worklog-import.php');
    $preflight = strpos((string) $source, 'ReviewLearningPreflight::run');
    $vendor = strpos((string) $source, "vendor/autoload.php");
    $client = strpos((string) $source, 'new IssueService');
    $this->true($preflight !== FALSE && $vendor !== FALSE && $preflight < $vendor, 'learning preflight runs before remote dependencies load');
    $this->true($vendor !== FALSE && $client !== FALSE && $vendor < $client, 'legacy Jira client remains after dependency load');
    $this->true(str_contains((string) $source, 'filing remains non-idempotent'), 'importer preserves remote duplicate warning');
  }

  /**
   * @return array{root: string, pending: string, export: string, snapshot: string}
   */
  private function fixtureContext(string $name): array {
    $fixture = __DIR__ . '/fixtures/review-learning/' . $name;
    $root = $this->temporaryDirectory();
    mkdir($root . '/files', 0700, TRUE);
    mkdir($root . '/worklogs/learning/pending', 0700, TRUE);
    $pending = $root . '/worklogs/learning/pending/' . $name . '.json';
    $export = $root . '/files/All Activities.json';
    $snapshot = $root . '/timing-readback.json';
    copy($fixture . '/pending.json', $pending);
    copy($fixture . '/export.json', $export);
    copy($fixture . '/timing-readback.json', $snapshot);
    touch($export, (new DateTimeImmutable('2026-10-27T00:00:00Z'))->getTimestamp());
    return compact('root', 'pending', 'export', 'snapshot');
  }

  /**
   * @param array{root: string, snapshot: string} $context
   */
  private function engine(array $context): ReviewLearningEngine {
    return new ReviewLearningEngine(
      $context['root'],
      new AtomicJsonStore($context['root'] . '/worklogs/learning'),
      new JsonTimingEntryProvider($context['snapshot']),
      new FixedClock(new DateTimeImmutable('2026-10-27T12:00:00Z')),
    );
  }

  /** @return array<string, mixed> */
  private function readJson(string $path): array {
    return json_decode((string) file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  private function temporaryDirectory(): string {
    $path = sys_get_temp_dir() . '/jira-worklog-review-learning-' . bin2hex(random_bytes(8));
    if (!mkdir($path, 0700, TRUE)) {
      throw new RuntimeException('Could not create test directory.');
    }
    $this->temporaryDirectories[] = $path;
    return $path;
  }

  private function removeTree(string $path): void {
    if (!is_dir($path)) {
      return;
    }
    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
      if ($item->isDir()) {
        rmdir($item->getPathname());
      }
      else {
        unlink($item->getPathname());
      }
    }
    rmdir($path);
  }

  private function same(mixed $expected, mixed $actual, string $message): void {
    $this->assertions++;
    if ($expected !== $actual) {
      throw new RuntimeException($message . '; expected ' . var_export($expected, TRUE) . ', got ' . var_export($actual, TRUE));
    }
  }

  private function true(bool $condition, string $message): void {
    $this->same(TRUE, $condition, $message);
  }

  private function false(bool $condition, string $message): void {
    $this->same(FALSE, $condition, $message);
  }

}

(new ReviewLearningTestSuite())->run();
