<?php

namespace justinholtweb\controltower\widgets;

use Craft;
use craft\base\Widget;
use justinholtweb\controltower\assets\ControlTowerWidgetAsset;
use justinholtweb\controltower\Plugin;

class ControlTowerWidget extends Widget
{
    public bool $showVisitors = true;
    public bool $showEditors = true;
    public bool $showQueue = true;
    public bool $showServer = true;
    public bool $showAlerts = true;
    public bool $showContent = true;
    public bool $showTopUrls = true;
    public int $refreshInterval = 30;

    public static function displayName(): string
    {
        return 'Control Tower';
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@justinholtweb/controltower/icon.svg');
    }

    public static function maxColspan(): ?int
    {
        return null; // Allow any width
    }

    public function getTitle(): ?string
    {
        return 'Control Tower';
    }

    public function getSubtitle(): ?string
    {
        return 'Live operational overview';
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('control-tower/_widgets/settings', [
            'widget' => $this,
        ]);
    }

    public function getBodyHtml(): ?string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(ControlTowerWidgetAsset::class);

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        return $view->renderTemplate('control-tower/_widgets/body', [
            'widget' => $this,
            'visitorCount' => $plugin->visitorTracking->getActiveVisitorCount($settings->visitorTimeout),
            'editorCount' => $plugin->editorTracking->getActiveEditorCount($settings->editorTimeout),
            'queueSummary' => $plugin->queueMonitor->getSummary(),
            'queueHealth' => $plugin->queueMonitor->getQueueHealth(),
            'serverHealth' => $plugin->metricsCollector->getServerHealth(),
            'alertCount' => $plugin->alerts->getActiveAlertCount(),
            'activeAlerts' => $plugin->alerts->getActiveAlerts(),
            'trafficBreakdown' => $plugin->visitorTracking->getTrafficBreakdown(),
            'topUrls' => $plugin->visitorTracking->getTopUrls(5),
            'editors' => $plugin->editorTracking->getActiveEditors($settings->editorTimeout),
            'contentPipeline' => $plugin->contentHealth->getContentPipeline(),
        ]);
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['refreshInterval'], 'integer', 'min' => 5];
        return $rules;
    }
}
