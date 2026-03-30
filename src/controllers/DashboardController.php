<?php

namespace justinholtweb\controltower\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\controltower\Plugin;
use justinholtweb\controltower\assets\ControlTowerCpAsset;

class DashboardController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-control-tower');

        return true;
    }

    public function actionOverview(): \yii\web\Response
    {
        $this->view->registerAssetBundle(ControlTowerCpAsset::class);

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        return $this->renderTemplate('control-tower/_cp/overview', [
            'title' => 'Control Tower',
            'selectedTab' => 'overview',
            'settings' => $settings,
            'visitorCount' => $plugin->visitorTracking->getActiveVisitorCount($settings->visitorTimeout),
            'editorCount' => $plugin->editorTracking->getActiveEditorCount($settings->editorTimeout),
            'queueSummary' => $plugin->queueMonitor->getSummary(),
            'serverHealth' => $plugin->metricsCollector->getServerHealth(),
            'alertCount' => $plugin->alerts->getActiveAlertCount(),
            'activeAlerts' => $plugin->alerts->getActiveAlerts(),
            'contentSummary' => $plugin->contentHealth->getContentSummary(),
            'contentPipeline' => $plugin->contentHealth->getContentPipeline(),
            'editors' => $plugin->editorTracking->getActiveEditors($settings->editorTimeout),
            'topUrls' => $plugin->visitorTracking->getTopUrls(),
            'trafficBreakdown' => $plugin->visitorTracking->getTrafficBreakdown(),
        ]);
    }

    public function actionVisitors(): \yii\web\Response
    {
        $this->view->registerAssetBundle(ControlTowerCpAsset::class);

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        return $this->renderTemplate('control-tower/_cp/visitors', [
            'title' => 'Live Traffic — Control Tower',
            'selectedTab' => 'visitors',
            'settings' => $settings,
            'visitorCount' => $plugin->visitorTracking->getActiveVisitorCount($settings->visitorTimeout),
            'visitors' => $plugin->visitorTracking->getActiveVisitors($settings->visitorTimeout),
            'topUrls' => $plugin->visitorTracking->getTopUrls(15),
            'trafficBreakdown' => $plugin->visitorTracking->getTrafficBreakdown(15),
            'requestsPerMinute' => $plugin->visitorTracking->getRequestsPerMinute(),
        ]);
    }

    public function actionEditors(): \yii\web\Response
    {
        $this->view->registerAssetBundle(ControlTowerCpAsset::class);

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        return $this->renderTemplate('control-tower/_cp/editors', [
            'title' => 'Editors — Control Tower',
            'selectedTab' => 'editors',
            'settings' => $settings,
            'editors' => $plugin->editorTracking->getActiveEditors($settings->editorTimeout),
            'collisions' => $plugin->editorTracking->getCollisions($settings->editorTimeout),
            'draftCounts' => $plugin->editorTracking->getDraftCountsByUser(),
        ]);
    }

    public function actionContent(): \yii\web\Response
    {
        $this->view->registerAssetBundle(ControlTowerCpAsset::class);

        $plugin = Plugin::getInstance();

        return $this->renderTemplate('control-tower/_cp/content', [
            'title' => 'Content Health — Control Tower',
            'selectedTab' => 'content',
            'contentSummary' => $plugin->contentHealth->getContentSummary(),
            'entriesBySection' => $plugin->contentHealth->getEntriesBySection(),
            'staleEntries' => $plugin->contentHealth->getStaleEntries(),
            'scheduledEntries' => $plugin->contentHealth->getScheduledEntries(),
            'expiredEntries' => $plugin->contentHealth->getExpiredEntries(),
            'contentPipeline' => $plugin->contentHealth->getContentPipeline(),
            'assetVolumes' => $plugin->contentHealth->getAssetVolumeSummary(),
            'pendingDrafts' => $plugin->contentHealth->getPendingDrafts(),
            'recentEvents' => $plugin->contentHealth->getRecentContentEvents(30, '24h'),
        ]);
    }

    public function actionQueue(): \yii\web\Response
    {
        $this->view->registerAssetBundle(ControlTowerCpAsset::class);

        $plugin = Plugin::getInstance();

        return $this->renderTemplate('control-tower/_cp/queue', [
            'title' => 'Queue Watch — Control Tower',
            'selectedTab' => 'queue',
            'queueSummary' => $plugin->queueMonitor->getSummary(),
            'queueHealth' => $plugin->queueMonitor->getQueueHealth(),
            'failedJobs' => $plugin->queueMonitor->getFailedJobs(),
            'recentJobs' => $plugin->queueMonitor->getRecentJobs(),
            'commonFailures' => $plugin->queueMonitor->getCommonFailures(),
        ]);
    }

    public function actionSystem(): \yii\web\Response
    {
        $this->view->registerAssetBundle(ControlTowerCpAsset::class);

        $plugin = Plugin::getInstance();

        return $this->renderTemplate('control-tower/_cp/system', [
            'title' => 'System Pulse — Control Tower',
            'selectedTab' => 'system',
            'snapshot' => $plugin->metricsCollector->getCurrentSnapshot(),
            'serverHealth' => $plugin->metricsCollector->getServerHealth(),
        ]);
    }

    public function actionAlerts(): \yii\web\Response
    {
        $this->view->registerAssetBundle(ControlTowerCpAsset::class);

        $plugin = Plugin::getInstance();

        return $this->renderTemplate('control-tower/_cp/alerts', [
            'title' => 'Alerts — Control Tower',
            'selectedTab' => 'alerts',
            'activeAlerts' => $plugin->alerts->getActiveAlerts(),
            'alertHistory' => $plugin->alerts->getAlertHistory(),
        ]);
    }

    public function actionPluginSettings(): \yii\web\Response
    {
        $this->view->registerAssetBundle(ControlTowerCpAsset::class);

        $plugin = Plugin::getInstance();

        return $this->renderTemplate('control-tower/_cp/settings', [
            'title' => 'Settings — Control Tower',
            'selectedTab' => 'settings',
            'settings' => $plugin->getSettings(),
        ]);
    }
}
