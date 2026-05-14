<?php

namespace justinholtweb\controltower\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\controltower\assets\ControlTowerCpAsset;
use justinholtweb\controltower\models\AlertRule;
use justinholtweb\controltower\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class RulesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_ALERTS);

        return true;
    }

    public function actionIndex(): Response
    {
        $this->view->registerAssetBundle(ControlTowerCpAsset::class);

        $plugin = Plugin::getInstance();

        return $this->renderTemplate('control-tower/_cp/_rules/index', [
            'title' => 'Alert Rules — Control Tower',
            'selectedTab' => 'rules',
            'rules' => $plugin->alerts->getRules(),
            'registry' => $plugin->metricRegistry->all(),
        ]);
    }

    public function actionEdit(?int $id = null, ?AlertRule $rule = null): Response
    {
        $this->view->registerAssetBundle(ControlTowerCpAsset::class);

        $plugin = Plugin::getInstance();

        if ($rule === null) {
            $rule = $id ? $plugin->alerts->getRule($id) : new AlertRule();
            if ($rule === null) {
                throw new NotFoundHttpException('Rule not found.');
            }
        }

        return $this->renderTemplate('control-tower/_cp/_rules/edit', [
            'title' => ($rule->id ? 'Edit Rule' : 'New Rule') . ' — Control Tower',
            'selectedTab' => 'rules',
            'rule' => $rule,
            'registry' => $plugin->metricRegistry->all(),
            'webhooks' => $plugin->webhooks->all(),
            'operators' => AlertRule::OPERATORS,
            'severities' => AlertRule::SEVERITIES,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        $id = $request->getBodyParam('id');
        $rule = $id ? $plugin->alerts->getRule((int) $id) : new AlertRule();
        if ($rule === null) {
            throw new NotFoundHttpException('Rule not found.');
        }

        $rule->name = (string) $request->getBodyParam('name', $rule->name);
        $rule->description = $request->getBodyParam('description');
        $rule->metric = (string) $request->getBodyParam('metric', $rule->metric);
        $rule->operator = (string) $request->getBodyParam('operator', $rule->operator);
        $rule->threshold = (float) $request->getBodyParam('threshold', $rule->threshold);
        $rule->severity = (string) $request->getBodyParam('severity', $rule->severity);
        $rule->isEnabled = (bool) $request->getBodyParam('isEnabled', false);
        $rule->notifyAdmins = (bool) $request->getBodyParam('notifyAdmins', false);
        $rule->notifyEmails = $request->getBodyParam('notifyEmails');
        $rule->webhookIds = array_map('intval', (array) $request->getBodyParam('webhookIds', []));
        $rule->notifyOnResolve = (bool) $request->getBodyParam('notifyOnResolve', false);
        $rule->minNotifyInterval = (int) $request->getBodyParam('minNotifyInterval', 0);

        if (!$plugin->alerts->saveRule($rule)) {
            Craft::$app->getSession()->setError('Couldn’t save rule.');
            Craft::$app->getUrlManager()->setRouteParams(['rule' => $rule]);
            return null;
        }

        Craft::$app->getSession()->setNotice('Rule saved.');
        return $this->redirect('control-tower/rules');
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $id = (int) Craft::$app->getRequest()->getRequiredBodyParam('id');
        Plugin::getInstance()->alerts->deleteRule($id);

        Craft::$app->getSession()->setNotice('Rule deleted.');
        return $this->redirect('control-tower/rules');
    }

    public function actionToggle(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int) Craft::$app->getRequest()->getRequiredBodyParam('id');
        $rule = Plugin::getInstance()->alerts->toggleRule($id);

        if (!$rule) {
            return $this->asJson(['success' => false]);
        }

        return $this->asJson(['success' => true, 'isEnabled' => $rule->isEnabled]);
    }
}
