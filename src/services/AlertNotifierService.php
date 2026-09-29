<?php

namespace justinholtweb\controltower\services;

use Craft;
use craft\elements\User;
use craft\helpers\Db;
use justinholtweb\controltower\helpers\Ip;
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
     * POSTs a payload to a webhook URL — only if the URL is somewhere a webhook may go.
     *
     * The URL is typed into the CP, so without these rules anybody who may manage webhooks can
     * make the server POST to the cloud metadata service or anything on the private network:
     *
     * 1. `http` and `https` only.
     * 2. Every address the host resolves to must be public ({@see Ip::resolvePublic()}).
     * 3. The connection is pinned to those addresses with `CURLOPT_RESOLVE`, so a second DNS
     *    lookup at connect time cannot rebind the host somewhere private.
     * 4. Redirects are not followed — a public host answering 302 to `http://127.0.0.1` is the
     *    same attack one hop later. Slack, Teams and Zapier all answer webhooks directly.
     *
     * Sites that genuinely post to an internal endpoint set `allowPrivateWebhookHosts` in
     * `config/control-tower.php`; rules 2 and 3 are then skipped, 1 and 4 still hold.
     *
     * @return array{ok: bool, status: int|null, body: string}
     */
    private function postWebhook(string $url, array $payload): array
    {
        $target = $this->webhookTarget($url);

        if (is_string($target)) {
            Craft::warning("Control Tower refused to POST a webhook to {$url}: {$target}", __METHOD__);

            return ['ok' => false, 'status' => null, 'body' => $target];
        }

        $options = [
            'json' => $payload,
            'http_errors' => false,
            'allow_redirects' => false,
        ];

        if ($target['addresses'] !== []) {
            $options['curl'] = [
                CURLOPT_RESOLVE => array_map(
                    static fn(string $ip) => sprintf('%s:%d:%s', $target['host'], $target['port'], str_contains($ip, ':') ? "[{$ip}]" : $ip),
                    $target['addresses'],
                ),
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ];
        }

        $client = Craft::createGuzzleClient([
            'timeout' => 5,
            'connect_timeout' => 3,
        ]);

        try {
            $response = $client->post($url, $options);
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
            $ok = $status >= 200 && $status < 300;

            if (!$ok) {
                Craft::warning("Control Tower webhook POST failed ({$status}) to {$url}: " . mb_strimwidth($body, 0, 500, '…'), __METHOD__);
            }

            return ['ok' => $ok, 'status' => $status, 'body' => $body];
        } catch (\Throwable $e) {
            Craft::error("Control Tower webhook POST threw: " . $e->getMessage(), __METHOD__);
            return ['ok' => false, 'status' => null, 'body' => $e->getMessage()];
        }
    }

    /**
     * Where a webhook URL may be sent, or why it may not.
     *
     * @return array{host: string, port: int, addresses: string[]}|string The pinned target, or the refusal reason.
     */
    public function webhookTarget(string $url): array|string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'Only http:// and https:// webhook URLs are allowed.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Webhook URLs may not carry a username or password.';
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (Plugin::getInstance()->getSettings()->allowPrivateWebhookHosts) {
            return ['host' => $host, 'port' => $port, 'addresses' => []];
        }

        $addresses = Ip::resolvePublic($host);

        if ($addresses === []) {
            return 'The webhook host does not resolve to a public address.';
        }

        return ['host' => trim($host, '[]'), 'port' => $port, 'addresses' => $addresses];
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
