<?php

namespace justinholtweb\controltower\notifications;

class TeamsFormatter
{
    public static function format(NotificationContext $ctx): array
    {
        $verb = $ctx->event === 'resolved' ? 'Resolved' : 'Firing';
        $color = match ($ctx->rule->severity) {
            'critical' => 'Attention',
            'warning' => 'Warning',
            default => 'Accent',
        };

        $facts = [
            ['title' => 'Severity', 'value' => $ctx->rule->severity],
            ['title' => 'Status', 'value' => $verb],
            ['title' => 'Site', 'value' => $ctx->siteName],
            ['title' => 'Environment', 'value' => $ctx->environment],
            ['title' => 'Metric', 'value' => $ctx->rule->metric],
        ];

        return [
            'type' => 'message',
            'attachments' => [
                [
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'contentUrl' => null,
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => [
                            [
                                'type' => 'TextBlock',
                                'size' => 'Large',
                                'weight' => 'Bolder',
                                'color' => $color,
                                'text' => "{$ctx->severityEmoji()} {$ctx->rule->name}",
                                'wrap' => true,
                            ],
                            [
                                'type' => 'TextBlock',
                                'text' => $ctx->alert->message ?: '_No message._',
                                'wrap' => true,
                            ],
                            [
                                'type' => 'FactSet',
                                'facts' => $facts,
                            ],
                        ],
                        'actions' => [
                            [
                                'type' => 'Action.OpenUrl',
                                'title' => 'Open Control Tower',
                                'url' => $ctx->alertUrl,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
