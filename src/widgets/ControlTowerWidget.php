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
    /**
     * Alert metric handles to show as stat cards — any registered metric, built-in or not.
     * A checkbox group posts '' when nothing is ticked, hence the union.
     *
     * @var string[]|string|null
     */
    public array|string|null $metrics = [];

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
            'metricOptions' => Plugin::getInstance()->metricRegistry->options(),
        ]);
    }

    public function getBodyHtml(): ?string
    {
        $view = Craft::$app->getView();
        $plugin = Plugin::getInstance();

        if (!$plugin->license->getIsValid()) {
            return $view->renderTemplate('control-tower/_widgets/locked', [
                'message' => $plugin->license->getStatusMessage(),
            ]);
        }

        $view->registerAssetBundle(ControlTowerWidgetAsset::class);

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
            'metricCards' => $plugin->metricRegistry->snapshot($this->getMetricHandles()),
        ]);
    }

    /**
     * The chosen metric handles, in the order they were saved. Handles whose metric is no longer
     * registered are kept here (re-registering brings the card back) and skipped when rendering.
     *
     * @return string[]
     */
    public function getMetricHandles(): array
    {
        if (!is_array($this->metrics)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $this->metrics), fn($h) => $h !== ''));
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['refreshInterval'], 'integer', 'min' => 5];
        return $rules;
    }
}
