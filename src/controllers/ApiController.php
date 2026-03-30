<?php

namespace justinholtweb\controltower\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\controltower\Plugin;

class ApiController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessPlugin-control-tower');

        return true;
    }

    public function actionOverview(): \yii\web\Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        return $this->asJson([
            'visitors' => $plugin->visitorTracking->getActiveVisitorCount($settings->visitorTimeout),
            'editors' => $plugin->editorTracking->getActiveEditorCount($settings->editorTimeout),
            'queueSummary' => $plugin->queueMonitor->getSummary(),
            'serverHealth' => $plugin->metricsCollector->getServerHealth(),
            'alertCount' => $plugin->alerts->getActiveAlertCount(),
            'trafficBreakdown' => $plugin->visitorTracking->getTrafficBreakdown(),
            'contentPipeline' => $plugin->contentHealth->getContentPipeline(),
        ]);
    }

    public function actionVisitors(): \yii\web\Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $period = Craft::$app->getRequest()->getQueryParam('period', '1h');

        return $this->asJson([
            'count' => $plugin->visitorTracking->getActiveVisitorCount($settings->visitorTimeout),
            'requestsPerMinute' => $plugin->visitorTracking->getRequestsPerMinute(),
            'topUrls' => $plugin->visitorTracking->getTopUrls(),
            'breakdown' => $plugin->visitorTracking->getTrafficBreakdown(),
            'timeline' => $plugin->visitorTracking->getTrafficTimeline($period),
        ]);
    }

    public function actionEditors(): \yii\web\Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        return $this->asJson([
            'editors' => $plugin->editorTracking->getActiveEditors($settings->editorTimeout),
            'collisions' => $plugin->editorTracking->getCollisions($settings->editorTimeout),
            'count' => $plugin->editorTracking->getActiveEditorCount($settings->editorTimeout),
        ]);
    }

    public function actionContent(): \yii\web\Response
    {
        $plugin = Plugin::getInstance();

        return $this->asJson([
            'summary' => $plugin->contentHealth->getContentSummary(),
            'pipeline' => $plugin->contentHealth->getContentPipeline(),
            'recentEvents' => $plugin->contentHealth->getRecentContentEvents(20, '1h'),
        ]);
    }

    public function actionQueue(): \yii\web\Response
    {
        $plugin = Plugin::getInstance();

        return $this->asJson([
            'summary' => $plugin->queueMonitor->getSummary(),
            'health' => $plugin->queueMonitor->getQueueHealth(),
            'failedJobs' => $plugin->queueMonitor->getFailedJobs(10),
            'recentJobs' => $plugin->queueMonitor->getRecentJobs(15),
        ]);
    }

    public function actionSystem(): \yii\web\Response
    {
        $plugin = Plugin::getInstance();
        $metric = Craft::$app->getRequest()->getQueryParam('metric');
        $period = Craft::$app->getRequest()->getQueryParam('period', '1h');

        $data = [
            'snapshot' => $plugin->metricsCollector->getCurrentSnapshot(),
            'health' => $plugin->metricsCollector->getServerHealth(),
        ];

        if ($metric) {
            $data['timeline'] = $plugin->metricsCollector->getMetricTimeline($metric, $period);
        }

        return $this->asJson($data);
    }

    public function actionAlerts(): \yii\web\Response
    {
        $plugin = Plugin::getInstance();

        return $this->asJson([
            'active' => $plugin->alerts->getActiveAlerts(),
            'count' => $plugin->alerts->getActiveAlertCount(),
        ]);
    }

    public function actionWidget(): \yii\web\Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        return $this->asJson([
            'visitors' => $plugin->visitorTracking->getActiveVisitorCount($settings->visitorTimeout),
            'editors' => $plugin->editorTracking->getActiveEditorCount($settings->editorTimeout),
            'queueSummary' => $plugin->queueMonitor->getSummary(),
            'queueHealth' => $plugin->queueMonitor->getQueueHealth(),
            'serverHealth' => $plugin->metricsCollector->getServerHealth(),
            'alertCount' => $plugin->alerts->getActiveAlertCount(),
            'activeAlerts' => $plugin->alerts->getActiveAlerts(),
            'trafficBreakdown' => $plugin->visitorTracking->getTrafficBreakdown(),
            'contentPipeline' => $plugin->contentHealth->getContentPipeline(),
            'topUrls' => $plugin->visitorTracking->getTopUrls(5),
            'editors_list' => $plugin->editorTracking->getActiveEditors($settings->editorTimeout),
        ]);
    }
}
