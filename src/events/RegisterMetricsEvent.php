<?php

namespace justinholtweb\controltower\events;

use justinholtweb\controltower\metrics\MetricInterface;
use yii\base\Event;

/**
 * Fired by `MetricRegistryService::EVENT_REGISTER_METRICS`. `$metrics` arrives holding the
 * built-ins; append yours, or remove one to hide it.
 */
class RegisterMetricsEvent extends Event
{
    /**
     * Each entry is a `MetricInterface` instance, a class name implementing it, or a config array —
     * with a `class` key, or with an `evaluator` callable to make a `CallbackMetric`.
     *
     * @var array<MetricInterface|string|array<string, mixed>>
     */
    public array $metrics = [];
}
