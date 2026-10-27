<?php

declare(strict_types=1);

namespace JiraWorklogImport\ReviewLearning;

use DateTimeImmutable;
use DateTimeZone;

interface Clock {

  public function now(): DateTimeImmutable;

}

final class SystemClock implements Clock {

  public function now(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
  }

}

final class FixedClock implements Clock {

  public function __construct(private readonly DateTimeImmutable $time) {
  }

  public function now(): DateTimeImmutable {
    return $this->time;
  }

}
