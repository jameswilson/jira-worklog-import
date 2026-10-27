<?php

declare(strict_types=1);

require __DIR__ . '/src/ReviewLearning/bootstrap.php';

use JiraWorklogImport\ReviewLearning\JsonTimingEntryProvider;
use JiraWorklogImport\ReviewLearning\ReviewLearningPreflight;

$options = [
  'root' => __DIR__,
  'export' => NULL,
  'timing-snapshot' => NULL,
];
foreach (array_slice($argv, 1) as $argument) {
  if ($argument === '--help') {
    print "Usage: php review-learning.php [--root=PATH] [--export=PATH] [--timing-snapshot=PATH]\n";
    print "Runs local review learning only. It never loads or invokes a Jira/Tempo client.\n";
    exit(0);
  }
  if (!preg_match('/^--(root|export|timing-snapshot)=(.+)$/', $argument, $matches)) {
    fwrite(STDERR, 'Unsupported argument: ' . $argument . PHP_EOL);
    exit(2);
  }
  $options[$matches[1]] = $matches[2];
}

$root = (string) $options['root'];
$export = $options['export'] === NULL ? $root . '/files/All Activities.json' : (string) $options['export'];
$provider = $options['timing-snapshot'] === NULL
  ? NULL
  : new JsonTimingEntryProvider((string) $options['timing-snapshot']);

try {
  $result = ReviewLearningPreflight::run($root, $provider, NULL, $export);
  foreach (ReviewLearningPreflight::report($result) as $line) {
    print $line . PHP_EOL;
  }
}
catch (Throwable $exception) {
  fwrite(STDERR, 'Review learning failed safely: ' . $exception->getMessage() . PHP_EOL);
  exit(1);
}
