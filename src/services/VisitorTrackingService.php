<?php

namespace justinholtweb\controltower\services;

use Craft;
use craft\helpers\Db;
use justinholtweb\controltower\Plugin;
use justinholtweb\controltower\records\VisitorRecord;
use yii\base\Component;

class VisitorTrackingService extends Component
{
    public function recordVisit(
        string $path,
        ?string $userAgent,
        ?string $referrer,
        string $method,
        bool $isGuest,
    ): void {
        $sessionHash = $this->_buildSessionHash();
        $now = Db::prepareDateForDb(new \DateTime());

        // Upsert: update lastSeenAt if same session, else insert
        $existing = VisitorRecord::find()
            ->where(['sessionHash' => $sessionHash])
            ->andWhere(['>=', 'lastSeenAt', Db::prepareDateForDb(new \DateTime('-5 minutes'))])
            ->one();

        if ($existing) {
            $existing->url = $path;
            $existing->lastSeenAt = $now;
            $existing->save(false);
            return;
        }

        $record = new VisitorRecord();
        $record->sessionHash = $sessionHash;
        $record->ipHash = $this->_hashIp();
        $record->userAgentHash = $userAgent ? hash('sha256', $userAgent) : null;
        $record->url = $path;
        $record->referrer = $referrer;
        $record->method = $method;
        $record->isGuest = $isGuest;
        $record->isBot = $this->_isBot($userAgent);
        $record->firstSeenAt = $now;
        $record->lastSeenAt = $now;
        $record->save(false);
    }

    public function getActiveVisitorCount(int $withinMinutes = 2): int
    {
        $since = Db::prepareDateForDb(new \DateTime("-{$withinMinutes} minutes"));

        return (int) VisitorRecord::find()
            ->where(['>=', 'lastSeenAt', $since])
            ->count();
    }

    public function getActiveVisitors(int $withinMinutes = 2): array
    {
        $since = Db::prepareDateForDb(new \DateTime("-{$withinMinutes} minutes"));

        return VisitorRecord::find()
            ->where(['>=', 'lastSeenAt', $since])
            ->orderBy(['lastSeenAt' => SORT_DESC])
            ->all();
    }

    public function getRequestsPerMinute(int $minutes = 1): float
    {
        $since = Db::prepareDateForDb(new \DateTime("-{$minutes} minutes"));

        $count = (int) VisitorRecord::find()
            ->where(['>=', 'lastSeenAt', $since])
            ->count();

        return round($count / max($minutes, 1), 1);
    }

    public function getTopUrls(int $withinMinutes = 5, int $limit = 10): array
    {
        $since = Db::prepareDateForDb(new \DateTime("-{$withinMinutes} minutes"));

        return (new \craft\db\Query())
            ->select(['url', 'COUNT(*) as hits'])
            ->from('{{%controltower_visitors}}')
            ->where(['>=', 'lastSeenAt', $since])
            ->groupBy(['url'])
            ->orderBy(['hits' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    public function getTrafficBreakdown(int $withinMinutes = 5): array
    {
        $since = Db::prepareDateForDb(new \DateTime("-{$withinMinutes} minutes"));

        $total = (int) VisitorRecord::find()
            ->where(['>=', 'lastSeenAt', $since])
            ->count();

        $bots = (int) VisitorRecord::find()
            ->where(['>=', 'lastSeenAt', $since])
            ->andWhere(['isBot' => true])
            ->count();

        $guests = (int) VisitorRecord::find()
            ->where(['>=', 'lastSeenAt', $since])
            ->andWhere(['isGuest' => true, 'isBot' => false])
            ->count();

        $authenticated = $total - $bots - $guests;

        return [
            'total' => $total,
            'bots' => $bots,
            'guests' => $guests,
            'authenticated' => max(0, $authenticated),
        ];
    }

    public function getTrafficTimeline(string $period = '1h', int $bucketMinutes = 5): array
    {
        $since = match ($period) {
            '15m' => new \DateTime('-15 minutes'),
            '1h' => new \DateTime('-1 hour'),
            '24h' => new \DateTime('-24 hours'),
            '7d' => new \DateTime('-7 days'),
            default => new \DateTime('-1 hour'),
        };

        $sinceDb = Db::prepareDateForDb($since);

        $rows = (new \craft\db\Query())
            ->select([
                'COUNT(*) as count',
                'MIN(lastSeenAt) as bucket',
            ])
            ->from('{{%controltower_visitors}}')
            ->where(['>=', 'lastSeenAt', $sinceDb])
            ->groupBy([new \yii\db\Expression(
                "FLOOR(UNIX_TIMESTAMP(lastSeenAt) / :interval)",
                [':interval' => $bucketMinutes * 60]
            )])
            ->orderBy(['bucket' => SORT_ASC])
            ->all();

        return $rows;
    }

    public function cleanup(int $retentionDays = 30): int
    {
        $cutoff = Db::prepareDateForDb(new \DateTime("-{$retentionDays} days"));

        return Craft::$app->getDb()->createCommand()
            ->delete('{{%controltower_visitors}}', ['<', 'lastSeenAt', $cutoff])
            ->execute();
    }

    private function _buildSessionHash(): string
    {
        $request = Craft::$app->getRequest();
        $ip = $request->getUserIP() ?? 'unknown';
        $ua = $request->getUserAgent() ?? 'unknown';

        return hash('sha256', $ip . '|' . $ua . '|' . date('Y-m-d'));
    }

    private function _hashIp(): ?string
    {
        $ip = Craft::$app->getRequest()->getUserIP();
        return $ip ? hash('sha256', $ip) : null;
    }

    private function _isBot(?string $userAgent): bool
    {
        if (!$userAgent) {
            return false;
        }

        $botPatterns = [
            'bot', 'crawl', 'spider', 'slurp', 'mediapartners',
            'wget', 'curl', 'python', 'java/', 'perl', 'ruby',
            'headless', 'phantom', 'lighthouse', 'pagespeed',
            'googlebot', 'bingbot', 'yandex', 'baidu', 'duckduck',
            'facebookexternalhit', 'twitterbot', 'linkedinbot',
            'semrush', 'ahrefs', 'mj12bot', 'dotbot',
        ];

        $ua = strtolower($userAgent);
        foreach ($botPatterns as $pattern) {
            if (str_contains($ua, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
