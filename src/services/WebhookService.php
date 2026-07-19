<?php

namespace justinholtweb\controltower\services;

use justinholtweb\controltower\models\Webhook;
use justinholtweb\controltower\records\WebhookRecord;
use yii\base\Component;

class WebhookService extends Component
{
    /**
     * @return Webhook[]
     */
    public function all(): array
    {
        /** @var WebhookRecord[] $records */
        $records = WebhookRecord::find()->orderBy(['name' => SORT_ASC])->all();
        return array_map(fn($r) => $this->_recordToModel($r), $records);
    }

    public function get(int $id): ?Webhook
    {
        $record = WebhookRecord::findOne(['id' => $id]);
        return $record ? $this->_recordToModel($record) : null;
    }

    /**
     * @param int[] $ids
     * @return Webhook[]
     */
    public function getEnabledByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        /** @var WebhookRecord[] $records */
        $records = WebhookRecord::find()
            ->where(['id' => $ids, 'isEnabled' => true])
            ->all();
        return array_map(fn($r) => $this->_recordToModel($r), $records);
    }

    public function save(Webhook $webhook): bool
    {
        if (!$webhook->validate()) {
            return false;
        }

        $record = $webhook->id ? WebhookRecord::findOne(['id' => $webhook->id]) : null;
        if (!$record) {
            $record = new WebhookRecord();
        }

        $record->name = $webhook->name;
        $record->type = $webhook->type;
        $record->url = $webhook->url;
        $record->isEnabled = $webhook->isEnabled;

        if (!$record->save()) {
            foreach ($record->getErrors() as $attr => $errs) {
                foreach ($errs as $err) {
                    $webhook->addError($attr, $err);
                }
            }
            return false;
        }

        $webhook->id = $record->id;
        return true;
    }

    public function delete(int $id): bool
    {
        $record = WebhookRecord::findOne(['id' => $id]);
        if (!$record) {
            return false;
        }
        return (bool) $record->delete();
    }

    public function toggle(int $id): ?Webhook
    {
        $record = WebhookRecord::findOne(['id' => $id]);
        if (!$record) {
            return null;
        }
        $record->isEnabled = !$record->isEnabled;
        $record->save(false, ['isEnabled', 'dateUpdated']);
        return $this->_recordToModel($record);
    }

    private function _recordToModel(WebhookRecord $record): Webhook
    {
        $w = new Webhook();
        $w->id = (int) $record->id;
        $w->name = (string) $record->name;
        $w->type = (string) $record->type;
        $w->url = (string) $record->url;
        $w->isEnabled = (bool) $record->isEnabled;
        return $w;
    }
}
