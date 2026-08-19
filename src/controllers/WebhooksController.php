<?php

namespace justinholtweb\controltower\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\controltower\assets\ControlTowerCpAsset;
use justinholtweb\controltower\models\Webhook;
use justinholtweb\controltower\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class WebhooksController extends Controller
{
    use LicenseGateTrait;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_ALERTS);

        return $this->enforceLicense();
    }

    public function actionIndex(): Response
    {
        $this->view->registerAssetBundle(ControlTowerCpAsset::class);

        return $this->renderTemplate('control-tower/_cp/_webhooks/index', [
            'title' => 'Webhooks — Control Tower',
            'selectedTab' => 'webhooks',
            'webhooks' => Plugin::getInstance()->webhooks->all(),
            'types' => Webhook::TYPES,
        ]);
    }

    public function actionEdit(?int $id = null, ?Webhook $webhook = null): Response
    {
        $this->view->registerAssetBundle(ControlTowerCpAsset::class);

        if ($webhook === null) {
            $webhook = $id ? Plugin::getInstance()->webhooks->get($id) : new Webhook();
            if ($webhook === null) {
                throw new NotFoundHttpException('Webhook not found.');
            }
        }

        return $this->renderTemplate('control-tower/_cp/_webhooks/edit', [
            'title' => ($webhook->id ? 'Edit Webhook' : 'New Webhook') . ' — Control Tower',
            'selectedTab' => 'webhooks',
            'webhook' => $webhook,
            'types' => Webhook::TYPES,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();

        $id = $request->getBodyParam('id');
        $webhook = $id ? Plugin::getInstance()->webhooks->get((int) $id) : new Webhook();
        if ($webhook === null) {
            throw new NotFoundHttpException('Webhook not found.');
        }

        $webhook->name = (string) $request->getBodyParam('name', $webhook->name);
        $webhook->type = (string) $request->getBodyParam('type', $webhook->type);
        $webhook->url = (string) $request->getBodyParam('url', $webhook->url);
        $webhook->isEnabled = (bool) $request->getBodyParam('isEnabled', false);

        if (!Plugin::getInstance()->webhooks->save($webhook)) {
            Craft::$app->getSession()->setError('Couldn’t save webhook.');
            Craft::$app->getUrlManager()->setRouteParams(['webhook' => $webhook]);
            return null;
        }

        Craft::$app->getSession()->setNotice('Webhook saved.');
        return $this->redirect('control-tower/webhooks');
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $id = (int) Craft::$app->getRequest()->getRequiredBodyParam('id');
        Plugin::getInstance()->webhooks->delete($id);

        Craft::$app->getSession()->setNotice('Webhook deleted.');
        return $this->redirect('control-tower/webhooks');
    }

    public function actionToggle(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int) Craft::$app->getRequest()->getRequiredBodyParam('id');
        $webhook = Plugin::getInstance()->webhooks->toggle($id);

        if (!$webhook) {
            return $this->asJson(['success' => false]);
        }

        return $this->asJson(['success' => true, 'isEnabled' => $webhook->isEnabled]);
    }

    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $id = $request->getBodyParam('id');

        if ($id) {
            $webhook = Plugin::getInstance()->webhooks->get((int) $id);
        } else {
            $webhook = new Webhook();
            $webhook->name = (string) $request->getBodyParam('name', 'Untitled');
            $webhook->type = (string) $request->getBodyParam('type', Webhook::TYPE_GENERIC);
            $webhook->url = (string) $request->getBodyParam('url', '');
        }

        if (!$webhook || !$webhook->url) {
            return $this->asJson([
                'success' => false,
                'message' => 'A webhook URL is required to send a test.',
            ]);
        }

        $result = Plugin::getInstance()->alertNotifier->testWebhook($webhook);

        return $this->asJson([
            'success' => $result['ok'],
            'status' => $result['status'],
            'body' => mb_strimwidth((string) ($result['body'] ?? ''), 0, 500, '…'),
            'message' => $result['ok']
                ? "Test payload delivered (HTTP {$result['status']})."
                : "Delivery failed" . ($result['status'] ? " (HTTP {$result['status']})" : '') . ".",
        ]);
    }
}
