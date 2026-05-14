<?php

namespace justinholtweb\controltower\services;

use Craft;
use craft\elements\User;
use craft\helpers\Db;
use GuzzleHttp\Exception\GuzzleException;
use justinholtweb\controltower\models\AlertRule;
use justinholtweb\controltower\models\Webhook;
use justinholtweb\controltower\notifications\GenericFormatter;
use justinholtweb\controltower\notifications\NotificationContext;
use justinholtweb\controltower\notifications\SlackFormatter;
use justinholtweb\controltower\notifications\TeamsFormatter;
use justinholtweb\controltower\Plugin;
use justinholtweb\controltower\records\AlertRecord;
use justinholtweb\controltower\records\AlertRuleRecord;
use yii\base\Component;

class AlertNotifierService extends Component
{
    public const EVENT_FIRING = 'firing';
    public const EVENT_RESOLVED = 'resolved';

    public function notify(int $alertId, string $event): void
    {
        $alert = AlertRecord::findOne(['id' => $alertId]);
        if (!$alert || !$alert->alertRuleId) {
            return;
        }

        $rule = Plugin::getInstance()->alerts->getRule((int) $alert->alertRuleId);
        if (!$rule) {
            return;
        }

        if (!$this->shouldNotify($rule, $event)) {
            return;
        }

        $ctx = new NotificationContext($event, $alert, $rule);

        $this->sendEmails($ctx);
        $this->sendWebhooks($ctx);

        if ($event === self::EVENT_FIRING) {
            $this->touchLastNotifiedAt($rule->id);
        }
    }

    /**
     * Synchronous test fire of a sample payload — used by the webhook "Send test" UI.
     */
    public function testWebhook(Webhook $webhook): array
    {
        $rule = new AlertRule();
        $rule->name = 'Control Tower test alert';
        $rule->metric = 'cpu_percent';
        $rule->operator = '>=';
        $rule->threshold = 90;
        $rule->severity = 'warning';

        $alert = new AlertRecord();
        $alert->message = 'This is a test message from Control Tower. If you can see this, your webhook is configured correctly.';
        $alert->severity = 'warning';
        $alert->createdAt = Db::prepareDateForDb(new \DateTime());
        $alert->isActive = true;

        $ctx = new NotificationContext(self::EVENT_FIRING, $alert, $rule);
        $payload = $this->buildPayload($webhook->type, $ctx);

        return $this->postWebhook($webhook->url, $payload);
    }

    private function shouldNotify(AlertRule $rule, string $event): bool
    {
        if ($event === self::EVENT_RESOLVED) {
            return $rule->notifyOnResolve;
        }

        if ($rule->minNotifyInterval > 0 && $rule->lastNotifiedAt) {
            try {
                $last = new \DateTime($rule->lastNotifiedAt);
                $diff = (time() - $last->getTimestamp()) / 60;
                if ($diff < $rule->minNotifyInterval) {
                    Craft::info(
                        "Control Tower: suppressing notification for rule {$rule->id} (throttle: {$rule->minNotifyInterval}m, since last: " . round($diff, 1) . "m)",
                        __METHOD__,
                    );
                    return false;
                }
            } catch (\Throwable $e) {
                // Bad timestamp — don't suppress.
            }
        }

        return true;
    }

    private function sendEmails(NotificationContext $ctx): void
    {
        $recipients = $this->resolveRecipients($ctx->rule);
        if (empty($recipients)) {
            return;
        }

        $subjectVerb = $ctx->event === self::EVENT_RESOLVED ? 'Resolved' : 'Firing';
        $subject = "[Control Tower · {$ctx->rule->severity}] {$subjectVerb}: {$ctx->rule->name}";

        try {
            $body = Craft::$app->getView()->renderTemplate(
                'control-tower/_cp/_emails/alert',
                ['ctx' => $ctx],
                \craft\web\View::TEMPLATE_MODE_CP,
            );
        } catch (\Throwable $e) {
            Craft::error('Control Tower: failed to render alert email template: ' . $e->getMessage(), __METHOD__);
            $body = $this->fallbackEmailBody($ctx);
        }

        $mailer = Craft::$app->getMailer();

        foreach ($recipients as $email) {
            try {
                $mailer->compose()
                    ->setTo($email)
                    ->setSubject($subject)
                    ->setHtmlBody($body)
                    ->send();
            } catch (\Throwable $e) {
                Craft::error("Control Tower: failed to email {$email}: " . $e->getMessage(), __METHOD__);
            }
        }
    }

    private function sendWebhooks(NotificationContext $ctx): void
    {
        $ids = $ctx->rule->webhookIds;
        if (empty($ids)) {
            return;
        }

        $webhooks = Plugin::getInstance()->webhooks->getEnabledByIds($ids);

        foreach ($webhooks as $webhook) {
            $payload = $this->buildPayload($webhook->type, $ctx);
            $this->postWebhook($webhook->url, $payload);
        }
    }

    /**
     * @return array{ok: bool, status: int|null, body: string}
     */
    private function postWebhook(string $url, array $payload): array
    {
        $client = Craft::createGuzzleClient([
            'timeout' => 5,
            'connect_timeout' => 3,
        ]);

        try {
            $response = $client->post($url, [
                'json' => $payload,
                'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
            $ok = $status >= 200 && $status < 300;

            if (!$ok) {
                Craft::warning("Control Tower webhook POST failed ({$status}) to {$url}: {$body}", __METHOD__);
            }

            return ['ok' => $ok, 'status' => $status, 'body' => $body];
        } catch (GuzzleException|\Throwable $e) {
            Craft::error("Control Tower webhook POST threw: " . $e->getMessage(), __METHOD__);
            return ['ok' => false, 'status' => null, 'body' => $e->getMessage()];
        }
    }

    private function buildPayload(string $type, NotificationContext $ctx): array
    {
        return match ($type) {
            Webhook::TYPE_SLACK => SlackFormatter::format($ctx),
            Webhook::TYPE_TEAMS => TeamsFormatter::format($ctx),
            default => GenericFormatter::format($ctx),
        };
    }

    /**
     * @return string[]
     */
    private function resolveRecipients(AlertRule $rule): array
    {
        $emails = $rule->parseEmails();

        if ($rule->notifyAdmins) {
            $admins = User::find()->admin(true)->status(null)->all();
            foreach ($admins as $admin) {
                if ($admin->email) {
                    $emails[] = $admin->email;
                }
            }
        }

        return array_values(array_unique(array_filter($emails)));
    }

    private function touchLastNotifiedAt(?int $ruleId): void
    {
        if (!$ruleId) {
            return;
        }

        $record = AlertRuleRecord::findOne(['id' => $ruleId]);
        if (!$record) {
            return;
        }

        $record->lastNotifiedAt = Db::prepareDateForDb(new \DateTime());
        $record->save(false, ['lastNotifiedAt']);
    }

    private function fallbackEmailBody(NotificationContext $ctx): string
    {
        $verb = $ctx->event === self::EVENT_RESOLVED ? 'Resolved' : 'Firing';
        $msg = htmlspecialchars($ctx->alert->message ?? '', ENT_QUOTES);
        $name = htmlspecialchars($ctx->rule->name, ENT_QUOTES);
        $url = htmlspecialchars($ctx->alertUrl, ENT_QUOTES);

        return <<<HTML
<h2 style="color:#{$ctx->severityColorHex()};">{$verb}: {$name}</h2>
<p>{$msg}</p>
<p><a href="{$url}">Open Control Tower</a></p>
HTML;
    }
}
