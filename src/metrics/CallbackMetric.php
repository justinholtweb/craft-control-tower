<?php

namespace justinholtweb\controltower\metrics;

/**
 * A metric whose value comes from a callable — `fn(MetricPeriod $period): ?float`. The quickest
 * way to register one from a module, and how the built-ins are defined.
 */
class CallbackMetric extends Metric
{
    /** @var callable|null */
    public $evaluator = null;

    public function getValue(MetricPeriod $period): ?float
    {
        if (!is_callable($this->evaluator)) {
            return null;
        }

        $value = ($this->evaluator)($period);

        return $value === null ? null : (float) $value;
    }
}
