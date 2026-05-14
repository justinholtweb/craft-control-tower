<?php

namespace justinholtweb\controltower\notifications;

class SlackFormatter
{
    public static function format(NotificationContext $ctx): array
    {
        $verb = $ctx->event === 'resolved' ? 'Resolved' : 'Firing';
        $headerText = "{$ctx->severityEmoji()} {$verb}: {$ctx->rule->name}";

        $contextLine = "Site: *{$ctx->siteName}* · Env: *{$ctx->environment}* · Severity: *{$ctx->rule->severity}*";

        return [
            'text' => "[{$ctx->rule->severity}] {$verb}: {$ctx->rule->name} — {$ctx->alert->message}",
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => $headerText,
                        'emoji' => true,
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $ctx->alert->message ?: '_No message._',
                    ],
                ],
                [
                    'type' => 'context',
                    'elements' => [
                        ['type' => 'mrkdwn', 'text' => $contextLine],
                        ['type' => 'mrkdwn', 'text' => "<{$ctx->alertUrl}|Open Control Tower>"],
                    ],
                ],
            ],
        ];
    }
}
