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

  public function pendingDirectory(): string {
    return $this->learningRoot . '/pending';
  }

  public function reviewedDirectory(): string {
    return $this->learningRoot . '/reviewed';
  }

  /**
   * @return string[]
   */
  public function pendingPaths(): array {
    if (!is_dir($this->pendingDirectory())) {
      return [];
    }
    $paths = glob($this->pendingDirectory() . '/*.json');
    if ($paths === FALSE) {
      throw new RuntimeException('Could not list pending learning manifests.');
    }
    sort($paths, SORT_STRING);
    return $paths;
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

  private function ensureDirectory(string $directory): void {
    if (is_dir($directory)) {
      return;
    }
    if (!@mkdir($directory, 0700, TRUE) && !is_dir($directory)) {
      throw new RuntimeException('Could not create learning state directory: ' . $directory);
    }
  }

}
