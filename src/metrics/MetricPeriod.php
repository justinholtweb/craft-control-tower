<?php

namespace justinholtweb\controltower\metrics;

use DateTimeImmutable;

/**
 * The span a metric is evaluated over. For a point-in-time gauge `start` and `end` are the same
 * moment; for a windowed metric `start` is `end` minus the metric's window.
 */
final class MetricPeriod
{
    public function __construct(
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
    ) {
    }

    public static function forWindow(?int $seconds, ?DateTimeImmutable $end = null): self
    {
        $end ??= new DateTimeImmutable();

        return new self($seconds ? $end->modify("-{$seconds} seconds") : $end, $end);
    }

    public function getSeconds(): int
    {
        return $this->end->getTimestamp() - $this->start->getTimestamp();
    }

    public function isInstant(): bool
    {
        return $this->getSeconds() === 0;
    }
}
