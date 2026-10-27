<?php

declare(strict_types=1);

namespace JiraWorklogImport\ReviewLearning;

use RuntimeException;

final class AtomicJsonStore {

  public function __construct(private readonly string $learningRoot) {
  }

  public function root(): string {
    return $this->learningRoot;
  }

  public function proposalsDirectory(): string {
    return $this->learningRoot . '/proposals';
  }

  public function reviewsDirectory(): string {
    return $this->learningRoot . '/reviews';
  }

  /**
   * @return string[]
   */
  public function proposalPaths(): array {
    if (!is_dir($this->proposalsDirectory())) {
      return [];
    }
    $paths = glob($this->proposalsDirectory() . '/*.json');
    if ($paths === FALSE) {
      throw new RuntimeException('Could not list learning proposal manifests.');
    }
    sort($paths, SORT_STRING);
    return $paths;
  }

  /**
   * Rename the original runtime layout without changing immutable proposals.
   *
   * This must run while the caller holds the learning-state lock.
   */
  public function migrateLegacyLayout(): bool {
    $moves = [
      $this->learningRoot . '/pending' => $this->proposalsDirectory(),
      $this->learningRoot . '/reviewed' => $this->reviewsDirectory(),
    ];
    $required = [];
    foreach ($moves as $legacy => $current) {
      if (!is_dir($legacy)) {
        continue;
      }
      if (file_exists($current)) {
        throw new RuntimeException(sprintf(
          'Cannot migrate learning state because both legacy and current directories exist: %s and %s',
          $legacy,
          $current,
        ));
      }
      $required[$legacy] = $current;
    }
    if ($required === []) {
      return FALSE;
    }

    $moved = [];
    try {
      foreach ($required as $legacy => $current) {
        if (!@rename($legacy, $current)) {
          throw new RuntimeException('Could not migrate learning state directory: ' . $legacy);
        }
        $moved[$legacy] = $current;
      }
    }
    catch (\Throwable $exception) {
      foreach (array_reverse($moved, TRUE) as $legacy => $current) {
        @rename($current, $legacy);
      }
      throw $exception;
    }

    $this->rewriteLegacyPathReferences();
    return TRUE;
  }

  /**
   * @template T
   * @param callable(): T $callback
   * @return T
   */
  public function withExclusiveLock(callable $callback): mixed {
    $this->ensureDirectory($this->learningRoot);
    $lockPath = $this->learningRoot . '/.review-learning.lock';
    $handle = @fopen($lockPath, 'c');
    if ($handle === FALSE) {
      throw new RuntimeException('Could not open review-learning lock: ' . $lockPath);
    }
    try {
      if (!flock($handle, LOCK_EX)) {
        throw new RuntimeException('Could not acquire review-learning lock: ' . $lockPath);
      }
      return $callback();
    }
    finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
  }

  /**
   * @return array<string, mixed>
   */
  public function readObject(string $path, array $default = []): array {
    if (!is_file($path)) {
      return $default;
    }
    $contents = @file_get_contents($path);
    if ($contents === FALSE) {
      throw new RuntimeException('Could not read JSON state: ' . $path);
    }
    try {
      $decoded = json_decode($contents, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new RuntimeException('Invalid JSON state at ' . $path . ': ' . $exception->getMessage(), 0, $exception);
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
      throw new RuntimeException('Expected a JSON object at ' . $path);
    }
    return $decoded;
  }

  /**
   * @param array<string, mixed> $data
   */
  public function writeObject(string $path, array $data): void {
    $directory = dirname($path);
    $this->ensureDirectory($directory);
    try {
      $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
    catch (\JsonException $exception) {
      throw new RuntimeException('Could not encode JSON state for ' . $path . ': ' . $exception->getMessage(), 0, $exception);
    }

    $temporary = tempnam($directory, '.' . basename($path) . '.tmp-');
    if ($temporary === FALSE) {
      throw new RuntimeException('Could not create temporary state file in ' . $directory);
    }
    try {
      $handle = @fopen($temporary, 'wb');
      if ($handle === FALSE) {
        throw new RuntimeException('Could not open temporary state file: ' . $temporary);
      }
      try {
        $written = fwrite($handle, $json);
        if ($written !== strlen($json) || !fflush($handle)) {
          throw new RuntimeException('Could not fully write temporary state file: ' . $temporary);
        }
        if (function_exists('fsync')) {
          fsync($handle);
        }
      }
      finally {
        fclose($handle);
      }
      @chmod($temporary, 0600);
      if (!@rename($temporary, $path)) {
        throw new RuntimeException('Could not atomically replace JSON state: ' . $path);
      }
    }
    finally {
      if (is_file($temporary)) {
        @unlink($temporary);
      }
    }
  }

  public function relativePath(string $path): string {
    $root = rtrim($this->learningRoot, '/') . '/';
    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
  }

  private function rewriteLegacyPathReferences(): void {
    $paths = glob($this->learningRoot . '/*.json') ?: [];
    $reviewPaths = glob($this->reviewsDirectory() . '/*.json') ?: [];
    $paths = array_values(array_unique(array_merge($paths, $reviewPaths)));
    sort($paths, SORT_STRING);
    foreach ($paths as $path) {
      $state = $this->readObject($path);
      $updated = $this->rewriteLegacyPathValue($state);
      if ($updated !== $state) {
        $this->writeObject($path, $updated);
      }
    }
  }

  private function rewriteLegacyPathValue(mixed $value): mixed {
    if (is_string($value)) {
      return str_replace(
        [
          $this->learningRoot . '/pending/',
          $this->learningRoot . '/reviewed/',
        ],
        [
          $this->proposalsDirectory() . '/',
          $this->reviewsDirectory() . '/',
        ],
        $value,
      );
    }
    if (!is_array($value)) {
      return $value;
    }
    foreach ($value as $key => $item) {
      $value[$key] = $this->rewriteLegacyPathValue($item);
    }
    return $value;
  }

  private function ensureDirectory(string $directory): void {
    if (is_dir($directory)) {
      return;
    }
    if (!@mkdir($directory, 0700, TRUE) && !is_dir($directory)) {
      throw new RuntimeException('Could not create learning state directory: ' . $directory);
    }
  }

}
