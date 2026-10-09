<?php

namespace justinholtweb\controltower\metrics;

/**
 * The contract every alert metric meets — the eleven built-ins and anything registered through
 * `MetricRegistryService::EVENT_REGISTER_METRICS` alike. Extend `Metric`, or register a
 * `CallbackMetric`, rather than implementing this from scratch.
 */
interface MetricInterface
{
    /**
     * Stored on alert rules, so treat it as permanent once rules use it. Letters, digits, `_`, `-`,
     * `.` and `:`, starting with a letter, at most 50 characters. Prefix it with your plugin or
     * module handle (`myplugin:orders_failed`) to stay clear of the built-ins.
     */
    public function getHandle(): string;

    public function getLabel(): string;

    /**
     * Shown after values and thresholds: `%`, `ms`, `jobs`… Empty for a bare number.
     */
    public function getUnit(): string;

    public function getDescription(): ?string;

    /**
     * The rule operators that make sense for this metric — a subset of `AlertRule::OPERATORS`.
     * A rule can only be saved with one of these.
     *
     * @return string[]
     */
    public function getOperators(): array;

    /**
     * How far back the value looks, in seconds — e.g. 3600 for "in the last hour". `null` for a
     * point-in-time gauge such as CPU usage or queue depth.
     */
    public function getWindow(): ?int;

    /**
     * The value for the given period. Return `null` when it can't be measured right now — a rule
     * on this metric is then skipped for that check rather than compared against zero.
     */
    public function getValue(MetricPeriod $period): ?float;
}
