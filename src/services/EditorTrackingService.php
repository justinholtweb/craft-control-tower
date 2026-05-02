<?php

namespace justinholtweb\controltower\services;

use Craft;
use craft\helpers\Db;
use justinholtweb\controltower\Plugin;
use justinholtweb\controltower\records\EditorActivityRecord;
use justinholtweb\controltower\records\EditorSessionRecord;
use yii\base\Component;

class EditorTrackingService extends Component
{
    public function recordActivity(int $userId, string $route, string $url): void
    {
        $now = Db::prepareDateForDb(new \DateTime());
        $parsed = $this->_parseRoute($route, $url);

        // Update or create session
        $this->_upsertSession($userId, $route, $url, $parsed, $now);

        // Log activity row
        $activity = new EditorActivityRecord();
        $activity->userId = $userId;
        $activity->route = $route;
        $activity->url = $url;
        $activity->sectionHandle = $parsed['sectionHandle'];
        $activity->elementType = $parsed['elementType'];
        $activity->elementId = $parsed['elementId'];
        $activity->draftId = $parsed['draftId'];
        $activity->action = $parsed['action'];
        $activity->recordedAt = $now;
        $activity->save(false);
    }

    public function getActiveEditors(int $withinMinutes = 5): array
    {
        $since = Db::prepareDateForDb(new \DateTime("-{$withinMinutes} minutes"));

        $sessions = EditorSessionRecord::find()
            ->where(['>=', 'lastSeenAt', $since])
            ->orderBy(['lastSeenAt' => SORT_DESC])
            ->all();

        $results = [];
        foreach ($sessions as $session) {
            $user = Craft::$app->getUsers()->getUserById($session->userId);
            if (!$user) {
                continue;
            }

            $results[] = [
                'userId' => $session->userId,
                'userName' => $user->fullName ?: $user->username,
                'email' => $user->email,
                'photoUrl' => $user->getPhoto()?->getUrl(['width' => 40]),
                'lastSeenAt' => $session->lastSeenAt,
                'startedAt' => $session->startedAt,
                'currentRoute' => $session->currentRoute,
                'sectionHandle' => $session->sectionHandle,
                'elementType' => $session->elementType,
                'elementId' => $session->elementId,
                'draftId' => $session->draftId,
                'action' => $session->action,
            ];
        }

        return $results;
    }

    public function getActiveEditorCount(int $withinMinutes = 5): int
    {
        $since = Db::prepareDateForDb(new \DateTime("-{$withinMinutes} minutes"));

        return (int) EditorSessionRecord::find()
            ->where(['>=', 'lastSeenAt', $since])
            ->count('DISTINCT [[userId]]');
    }

    public function getCollisions(int $withinMinutes = 5): array
    {
        $since = Db::prepareDateForDb(new \DateTime("-{$withinMinutes} minutes"));

        $rows = (new \craft\db\Query())
            ->select(['elementId', 'elementType', 'sectionHandle', 'GROUP_CONCAT(DISTINCT userId) as userIds', 'COUNT(DISTINCT userId) as editorCount'])
            ->from('{{%controltower_editor_sessions}}')
            ->where(['>=', 'lastSeenAt', $since])
            ->andWhere(['not', ['elementId' => null]])
            ->andWhere(['action' => 'editing'])
            ->groupBy(['elementId', 'elementType', 'sectionHandle'])
            ->having(['>', 'editorCount', 1])
            ->all();

        return $rows;
    }

    public function getDraftCountsByUser(): array
    {
        return (new \craft\db\Query())
            ->select(['d.creatorId as userId', 'COUNT(*) as draftCount'])
            ->from(['e' => '{{%elements}}'])
            ->innerJoin(['d' => '{{%drafts}}'], '[[e.draftId]] = [[d.id]]')
            ->where(['e.dateDeleted' => null])
            ->andWhere(['not', ['d.creatorId' => null]])
            ->groupBy(['d.creatorId'])
            ->all();
    }

    public function getEditorTimeline(string $period = '24h', int $bucketMinutes = 60): array
    {
        $since = match ($period) {
            '1h' => new \DateTime('-1 hour'),
            '24h' => new \DateTime('-24 hours'),
            '7d' => new \DateTime('-7 days'),
            default => new \DateTime('-24 hours'),
        };

        $sinceDb = Db::prepareDateForDb($since);

        return (new \craft\db\Query())
            ->select([
                'COUNT(*) as count',
                'COUNT(DISTINCT userId) as uniqueEditors',
                'MIN(recordedAt) as bucket',
            ])
            ->from('{{%controltower_editor_activity}}')
            ->where(['>=', 'recordedAt', $sinceDb])
            ->groupBy([new \yii\db\Expression(
                "FLOOR(UNIX_TIMESTAMP(recordedAt) / :interval)",
                [':interval' => $bucketMinutes * 60]
            )])
            ->orderBy(['bucket' => SORT_ASC])
            ->all();
    }

    public function cleanup(int $retentionDays = 90): int
    {
        $cutoff = Db::prepareDateForDb(new \DateTime("-{$retentionDays} days"));

        $deleted = Craft::$app->getDb()->createCommand()
            ->delete('{{%controltower_editor_activity}}', ['<', 'recordedAt', $cutoff])
            ->execute();

        $deleted += Craft::$app->getDb()->createCommand()
            ->delete('{{%controltower_editor_sessions}}', ['<', 'lastSeenAt', $cutoff])
            ->execute();

        return $deleted;
    }

    private function _upsertSession(int $userId, string $route, string $url, array $parsed, string $now): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $since = Db::prepareDateForDb(new \DateTime("-{$settings->editorTimeout} minutes"));

        $session = EditorSessionRecord::find()
            ->where(['userId' => $userId])
            ->andWhere(['>=', 'lastSeenAt', $since])
            ->one();

        if (!$session) {
            $session = new EditorSessionRecord();
            $session->userId = $userId;
            $session->startedAt = $now;
        }

        $session->lastSeenAt = $now;
        $session->currentRoute = $route;
        $session->currentUrl = $url;
        $session->sectionHandle = $parsed['sectionHandle'];
        $session->elementType = $parsed['elementType'];
        $session->elementId = $parsed['elementId'];
        $session->draftId = $parsed['draftId'];
        $session->action = $parsed['action'];
        $session->save(false);
    }

    private function _parseRoute(string $route, string $url): array
    {
        $result = [
            'sectionHandle' => null,
            'elementType' => null,
            'elementId' => null,
            'draftId' => null,
            'action' => 'browsing',
        ];

        // Match entry edit: entries/<section>/<entryId>-<slug>
        if (preg_match('#entries/([^/]+)/(\d+)#', $route, $m)) {
            $result['sectionHandle'] = $m[1];
            $result['elementType'] = 'entry';
            $result['elementId'] = (int) $m[2];
            $result['action'] = 'editing';
        }
        // Match asset edit
        elseif (preg_match('#assets/([^/]+)/(\d+)#', $route, $m)) {
            $result['elementType'] = 'asset';
            $result['elementId'] = (int) $m[2];
            $result['action'] = 'editing';
        }
        // Match category edit
        elseif (preg_match('#categories/([^/]+)/(\d+)#', $route, $m)) {
            $result['sectionHandle'] = $m[1];
            $result['elementType'] = 'category';
            $result['elementId'] = (int) $m[2];
            $result['action'] = 'editing';
        }
        // Match global set edit
        elseif (preg_match('#globals/([^/]+)#', $route, $m)) {
            $result['sectionHandle'] = $m[1];
            $result['elementType'] = 'globalSet';
            $result['action'] = 'editing';
        }
        // Entry listing
        elseif (preg_match('#entries/([^/]+)$#', $route, $m)) {
            $result['sectionHandle'] = $m[1];
            $result['action'] = 'viewing';
        }
        // Dashboard
        elseif (str_contains($route, 'dashboard')) {
            $result['action'] = 'browsing';
        }

        // Check for draft parameter
        if (preg_match('#[?&]draftId=(\d+)#', $url, $dm)) {
            $result['draftId'] = (int) $dm[1];
        }

        return $result;
    }
}
