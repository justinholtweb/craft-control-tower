<?php

namespace justinholtweb\controltower\metrics;

use justinholtweb\controltower\models\AlertRule;
use yii\base\BaseObject;

/**
 * Base class for metrics: configure the descriptive properties, implement `getValue()`.
 */
abstract class Metric extends BaseObject implements MetricInterface
{
    public string $handle = '';
    public string $label = '';
    public string $unit = '';
    public ?string $description = null;
    /** @var string[] */
    public array $operators = AlertRule::OPERATORS;
    public ?int $window = null;

    public function getHandle(): string
    {
        return $this->handle;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getUnit(): string
    {
        return $this->unit;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getOperators(): array
    {
        return $this->operators;
    }

    public function getWindow(): ?int
    {
        return $this->window;
    }
}
