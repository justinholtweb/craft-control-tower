<?php

namespace justinholtweb\controltower\services;

use Craft;
use craft\helpers\Db;
use justinholtweb\controltower\Plugin;
use justinholtweb\controltower\records\ContentEventRecord;
use yii\base\Component;

class ContentHealthService extends Component
{
    public function recordContentEvent(
        string $elementType,
        int $elementId,
        string $action,
        ?string $sectionHandle = null,
    ): void {
        $record = new ContentEventRecord();
        $record->elementType = $elementType;
        $record->elementId = $elementId;
        $record->action = $action;
        $record->sectionHandle = $sectionHandle;
        $record->userId = Craft::$app->getUser()->getId();
        $record->recordedAt = Db::prepareDateForDb(new \DateTime());
        $record->save(false);
    }

    public function getContentSummary(): array
    {
        $entries = (int) \craft\elements\Entry::find()->status(null)->count();
        $assets = (int) \craft\elements\Asset::find()->count();
        $categories = (int) \craft\elements\Category::find()->count();
        $users = (int) \craft\elements\User::find()->count();

        return [
            'entries' => $entries,
            'assets' => $assets,
            'categories' => $categories,
            'users' => $users,
            'total' => $entries + $assets + $categories + $users,
        ];
    }

    public function getEntriesBySection(): array
    {
        $sections = Craft::$app->getEntries()->getAllSections();
        $results = [];

        foreach ($sections as $section) {
            $results[] = [
                'handle' => $section->handle,
                'name' => $section->name,
                'type' => $section->type->value,
                'count' => (int) \craft\elements\Entry::find()
                    ->section($section->handle)
                    ->status(null)
                    ->count(),
            ];
        }

        usort($results, fn($a, $b) => $b['count'] <=> $a['count']);

        return $results;
    }

    public function getPendingDrafts(): array
    {
        return (new \craft\db\Query())
            ->select(['COUNT(*) as count'])
            ->from(['e' => '{{%elements}}'])
            ->innerJoin(['d' => '{{%drafts}}'], '[[e.draftId]] = [[d.id]]')
            ->where(['e.dateDeleted' => null])
            ->scalar();
    }

    public function getStaleEntries(?int $days = null): array
    {
        $days = $days ?? Plugin::getInstance()->getSettings()->staleContentDays;
        $cutoff = Db::prepareDateForDb(new \DateTime("-{$days} days"));

        $entries = \craft\elements\Entry::find()
            ->status('live')
            ->dateUpdated('< ' . $cutoff)
            ->orderBy(['elements.dateUpdated' => SORT_ASC])
            ->limit(50)
            ->all();

        return array_map(fn($entry) => [
            'id' => $entry->id,
            'title' => $entry->title,
            'section' => $entry->section?->name,
            'sectionHandle' => $entry->section?->handle,
            'dateUpdated' => $entry->dateUpdated?->format('Y-m-d H:i'),
            'daysStale' => $entry->dateUpdated
                ? (int) $entry->dateUpdated->diff(new \DateTime())->days
                : null,
            'cpEditUrl' => $entry->getCpEditUrl(),
        ], $entries);
    }

    public function getScheduledEntries(): array
    {
        $entries = \craft\elements\Entry::find()
            ->status('pending')
            ->postDate('> ' . Db::prepareDateForDb(new \DateTime()))
            ->orderBy(['postDate' => SORT_ASC])
            ->limit(20)
            ->all();

        return array_map(fn($entry) => [
            'id' => $entry->id,
            'title' => $entry->title,
            'section' => $entry->section?->name,
            'postDate' => $entry->postDate?->format('Y-m-d H:i'),
            'cpEditUrl' => $entry->getCpEditUrl(),
        ], $entries);
    }

    public function getExpiredEntries(): array
    {
        $entries = \craft\elements\Entry::find()
            ->status('expired')
            ->orderBy(['expiryDate' => SORT_DESC])
            ->limit(20)
            ->all();

        return array_map(fn($entry) => [
            'id' => $entry->id,
            'title' => $entry->title,
            'section' => $entry->section?->name,
            'expiryDate' => $entry->expiryDate?->format('Y-m-d H:i'),
            'cpEditUrl' => $entry->getCpEditUrl(),
        ], $entries);
    }

    public function getRecentContentEvents(int $limit = 50, ?string $period = null): array
    {
        $query = ContentEventRecord::find()
            ->orderBy(['recordedAt' => SORT_DESC])
            ->limit($limit);

        if ($period) {
            $since = match ($period) {
                '1h' => new \DateTime('-1 hour'),
                '24h' => new \DateTime('-24 hours'),
                '7d' => new \DateTime('-7 days'),
                default => new \DateTime('-24 hours'),
            };
            $query->andWhere(['>=', 'recordedAt', Db::prepareDateForDb($since)]);
        }

        return array_map(fn($record) => [
            'elementType' => $record->elementType,
            'elementId' => $record->elementId,
            'action' => $record->action,
            'sectionHandle' => $record->sectionHandle,
            'userId' => $record->userId,
            'recordedAt' => $record->recordedAt,
        ], $query->all());
    }

    public function getContentPipeline(): array
    {
        $todayStart = Db::prepareDateForDb(new \DateTime('today'));

        $query = fn(string $action) => (int) ContentEventRecord::find()
            ->where(['>=', 'recordedAt', $todayStart])
            ->andWhere(['action' => $action])
            ->count();

        return [
            'draftsCreatedToday' => $query('created'),
            'publishedToday' => $query('saved'),
            'assetsUploadedToday' => $query('uploaded'),
            'deletedToday' => $query('deleted'),
            'scheduledUpcoming' => count($this->getScheduledEntries()),
        ];
    }

    public function getAssetVolumeSummary(): array
    {
        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        $results = [];

        foreach ($volumes as $volume) {
            $count = (int) \craft\elements\Asset::find()
                ->volume($volume->handle)
                ->count();

            $results[] = [
                'handle' => $volume->handle,
                'name' => $volume->name,
                'count' => $count,
            ];
        }

        return $results;
    }

    public function cleanup(int $retentionDays = 90): int
    {
        $cutoff = Db::prepareDateForDb(new \DateTime("-{$retentionDays} days"));

        return Craft::$app->getDb()->createCommand()
            ->delete('{{%controltower_content_events}}', ['<', 'recordedAt', $cutoff])
            ->execute();
    }
}
