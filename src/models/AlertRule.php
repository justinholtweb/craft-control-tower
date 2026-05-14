<?php

namespace justinholtweb\controltower\models;

use craft\base\Model;

class AlertRule extends Model
{
    public const OPERATORS = ['>', '>=', '<', '<=', '='];
    public const SEVERITIES = ['info', 'warning', 'critical'];

    public ?int $id = null;
    public string $name = '';
    public ?string $description = null;
    public string $metric = '';
    public string $operator = '>=';
    public float $threshold = 0;
    public string $severity = 'warning';
    public bool $isEnabled = true;
    public bool $notifyAdmins = false;
    public ?string $notifyEmails = null;
    /** @var int[] */
    public array $webhookIds = [];
    public bool $notifyOnResolve = false;
    public int $minNotifyInterval = 0;
    public ?string $lastNotifiedAt = null;

    public function defineRules(): array
    {
        return [
            [['name', 'metric', 'operator', 'severity'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['metric'], 'string', 'max' => 50],
            [['operator'], 'in', 'range' => self::OPERATORS],
            [['severity'], 'in', 'range' => self::SEVERITIES],
            [['threshold'], 'number'],
            [['minNotifyInterval'], 'integer', 'min' => 0],
            [['notifyEmails'], 'validateEmails'],
        ];
    }

    public function validateEmails(string $attribute): void
    {
        $raw = (string) ($this->$attribute ?? '');
        if ($raw === '') {
            return;
        }

        foreach ($this->parseEmails() as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addError($attribute, "“{$email}” is not a valid email address.");
            }
        }
    }

    /**
     * @return string[]
     */
    public function parseEmails(): array
    {
        $raw = (string) ($this->notifyEmails ?? '');
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $parts))));
    }

    public function evaluate(float $currentValue): bool
    {
        return match ($this->operator) {
            '>' => $currentValue > $this->threshold,
            '>=' => $currentValue >= $this->threshold,
            '<' => $currentValue < $this->threshold,
            '<=' => $currentValue <= $this->threshold,
            '=' => $currentValue == $this->threshold,
            default => false,
        };
    }
}
