<?php

declare(strict_types=1);

namespace JiraWorklogImport\ReviewLearning;

final class ReviewLearningRepository {

  public function __construct(private readonly AtomicJsonStore $store) {
  }

  /**
   * Retrieve reviewed examples and durable preferences for later reconstruction.
   *
   * Supported criteria are timing_project, jira_issue, issue_type, entry_type,
   * repository_signature, activity_signature, wording_pattern,
   * billing_status, duration_shape, and outcome.
   *
   * @param array<string, string> $criteria
   * @return array<string, mixed>
   */
  public function retrieve(array $criteria = [], int $limit = 10): array {
    $examples = [];
    $paths = glob($this->store->reviewedDirectory() . '/*.json') ?: [];
    sort($paths, SORT_STRING);
    foreach ($paths as $path) {
      $reviewed = $this->store->readObject($path);
      foreach (($reviewed['retrieval_examples'] ?? []) as $example) {
        if (!is_array($example)) {
          continue;
        }
        $score = $this->score($example, $criteria);
        if ($criteria !== [] && $score === 0) {
          continue;
        }
        $examples[] = [
          'score' => $score,
          'reviewed_manifest_path' => $path,
          'example' => $example,
        ];
      }
    }
    usort($examples, static function (array $left, array $right): int {
      $score = $right['score'] <=> $left['score'];
      if ($score !== 0) {
        return $score;
      }
      return strcmp((string) ($left['example']['example_id'] ?? ''), (string) ($right['example']['example_id'] ?? ''));
    });

    $preferencesPath = $this->store->root() . '/preferences.json';
    $preferences = $this->store->readObject($preferencesPath, [
      'schema_version' => ReviewLearningEngine::SCHEMA_VERSION,
      'updated_at' => NULL,
      'preferences' => [],
    ]);

    return [
      'criteria' => $criteria,
      'examples' => array_slice($examples, 0, max(0, $limit)),
      'preferences' => $preferences['preferences'] ?? [],
      'precedence' => 'Current evidence and explicit user direction outrank reviewed examples and preferences.',
    ];
  }

  /**
   * @param array<string, mixed> $example
   * @param array<string, string> $criteria
   */
  private function score(array $example, array $criteria): int {
    $facets = is_array($example['facets'] ?? NULL) ? $example['facets'] : [];
    $score = 0;
    if (isset($criteria['timing_project']) && ($facets['timing_project'] ?? NULL) === $criteria['timing_project']) {
      $score += 4;
    }
    if (isset($criteria['jira_issue']) && in_array($criteria['jira_issue'], [
      $facets['original_jira_issue'] ?? NULL,
      $facets['final_jira_issue'] ?? NULL,
    ], TRUE)) {
      $score += 5;
    }
    if (isset($criteria['billing_status']) && ($facets['billing_status'] ?? NULL) === $criteria['billing_status']) {
      $score += 2;
    }
    if (isset($criteria['duration_shape']) && ($facets['duration_shape'] ?? NULL) === $criteria['duration_shape']) {
      $score += 1;
    }
    if (isset($criteria['issue_type']) && ($facets['issue_type'] ?? NULL) === $criteria['issue_type']) {
      $score += 2;
    }
    if (isset($criteria['entry_type']) && ($facets['entry_type'] ?? NULL) === $criteria['entry_type']) {
      $score += 2;
    }
    if (isset($criteria['repository_signature']) && in_array($criteria['repository_signature'], $facets['repository_signatures'] ?? [], TRUE)) {
      $score += 3;
    }
    if (isset($criteria['activity_signature']) && in_array($criteria['activity_signature'], $facets['activity_signatures'] ?? [], TRUE)) {
      $score += 3;
    }
    if (isset($criteria['wording_pattern']) && ($facets['wording_pattern'] ?? NULL) === $criteria['wording_pattern']) {
      $score += 1;
    }
    if (isset($criteria['outcome']) && ($example['outcome'] ?? NULL) === $criteria['outcome']) {
      $score += 2;
    }
    return $score;
  }

}
