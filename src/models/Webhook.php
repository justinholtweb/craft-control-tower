<?php

namespace justinholtweb\controltower\models;

use craft\base\Model;

class Webhook extends Model
{
    public const TYPE_SLACK = 'slack';
    public const TYPE_TEAMS = 'teams';
    public const TYPE_GENERIC = 'generic';

    public const TYPES = [
        self::TYPE_SLACK => 'Slack',
        self::TYPE_TEAMS => 'Microsoft Teams',
        self::TYPE_GENERIC => 'Generic JSON (Zapier, IFTTT, custom)',
    ];

    public ?int $id = null;
    public string $name = '';
    public string $type = self::TYPE_GENERIC;
    public string $url = '';
    public bool $isEnabled = true;

    public function defineRules(): array
    {
        return [
            [['name', 'type', 'url'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['type'], 'in', 'range' => array_keys(self::TYPES)],
            [['url'], 'url', 'defaultScheme' => 'https'],
        ];
    }

    public function maskedUrl(): string
    {
        $len = strlen($this->url);
        if ($len <= 12) {
            return str_repeat('•', max(0, $len - 4)) . substr($this->url, -4);
        }
        return substr($this->url, 0, 12) . str_repeat('•', 8) . substr($this->url, -6);
    }
}
