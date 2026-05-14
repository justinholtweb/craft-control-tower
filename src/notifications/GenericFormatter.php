<?php

namespace justinholtweb\controltower\notifications;

class GenericFormatter
{
    public static function format(NotificationContext $ctx): array
    {
        return [
            'event' => $ctx->event,
            'severity' => $ctx->rule->severity,
            'ruleId' => $ctx->rule->id,
            'ruleName' => $ctx->rule->name,
            'metric' => $ctx->rule->metric,
            'operator' => $ctx->rule->operator,
            'threshold' => $ctx->rule->threshold,
            'alertId' => $ctx->alert->id,
            'alertMessage' => $ctx->alert->message,
            'alertContext' => $ctx->decodedContext(),
            'siteName' => $ctx->siteName,
            'environment' => $ctx->environment,
            'firedAt' => $ctx->alert->createdAt,
            'resolvedAt' => $ctx->alert->resolvedAt,
            'alertUrl' => $ctx->alertUrl,
        ];
    }
}
