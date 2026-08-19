<?php

namespace justinholtweb\controltower;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Dashboard;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use justinholtweb\controltower\models\Settings;
use justinholtweb\controltower\services\AlertNotifierService;
use justinholtweb\controltower\services\AlertService;
use justinholtweb\controltower\services\ContentHealthService;
use justinholtweb\controltower\services\EditorTrackingService;
use justinholtweb\controltower\services\LicenseService;
use justinholtweb\controltower\services\MetricRegistryService;
use justinholtweb\controltower\services\MetricsCollectorService;
use justinholtweb\controltower\services\QueueMonitorService;
use justinholtweb\controltower\services\VisitorTrackingService;
use justinholtweb\controltower\services\WebhookService;
use justinholtweb\controltower\widgets\ControlTowerWidget;
use yii\base\Event;

/**
 * Control Tower — live operational monitoring for Craft CMS.
 *
 * @property-read AlertNotifierService $alertNotifier
 * @property-read AlertService $alerts
 * @property-read ContentHealthService $contentHealth
 * @property-read EditorTrackingService $editorTracking
 * @property-read LicenseService $license
 * @property-read MetricRegistryService $metricRegistry
 * @property-read MetricsCollectorService $metricsCollector
 * @property-read QueueMonitorService $queueMonitor
 * @property-read VisitorTrackingService $visitorTracking
 * @property-read WebhookService $webhooks
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'control-tower';

    public const EDITION_STANDARD = 'standard';

    public const PERMISSION_VIEW = 'controltower:viewDashboard';
    public const PERMISSION_MANAGE_ALERTS = 'controltower:manageAlerts';
    public const PERMISSION_MANAGE_SETTINGS = 'controltower:manageSettings';

    public string $schemaVersion = '1.1.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [
            self::EDITION_STANDARD,
        ];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'alertNotifier' => AlertNotifierService::class,
                'alerts' => AlertService::class,
                'contentHealth' => ContentHealthService::class,
                'editorTracking' => EditorTrackingService::class,
                'license' => LicenseService::class,
                'metricRegistry' => MetricRegistryService::class,
                'metricsCollector' => MetricsCollectorService::class,
                'queueMonitor' => QueueMonitorService::class,
                'visitorTracking' => VisitorTrackingService::class,
                'webhooks' => WebhookService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::$app->onInit(function () {
            if (!$this->isInstalled) {
                return;
            }

            // Routes, permissions and the widget stay registered whatever the
            // license status — an unlicensed install still needs to be able to
            // reach the license screen and see why it's locked.
            $this->_registerEventHandlers();

            // Data collection is licensed functionality. Stop gathering rather
            // than quietly accruing data the install can't display.
            if (!$this->license->getIsValid()) {
                return;
            }

            $this->_registerContentEventListeners();

            if (Craft::$app->getRequest()->getIsCpRequest()) {
                $this->_trackCpActivity();
            }

            if (Craft::$app->getRequest()->getIsSiteRequest()) {
                $this->_trackSiteVisitor();
            }
        });
    }

    public function getCpNavItem(): ?array
    {
        $nav = parent::getCpNavItem();
        $nav['label'] = 'Control Tower';

        $user = Craft::$app->getUser();
        $canView = $user->checkPermission(self::PERMISSION_VIEW);
        $canManageAlerts = $user->checkPermission(self::PERMISSION_MANAGE_ALERTS);
        $canManageSettings = $user->checkPermission(self::PERMISSION_MANAGE_SETTINGS);

        // Locked: collapse to the one page that can unlock it again
        if (!$this->license->getIsValid()) {
            $nav['badgeCount'] = 1;
            $nav['subnav'] = ($canView || $canManageAlerts || $canManageSettings)
                ? ['license' => ['label' => 'License', 'url' => 'control-tower/license']]
                : [];

            return $nav;
        }

        $subnav = [];
        if ($canView) {
            $subnav['overview'] = ['label' => 'Overview', 'url' => 'control-tower'];
            $subnav['visitors'] = ['label' => 'Live Traffic', 'url' => 'control-tower/visitors'];
            $subnav['editors'] = ['label' => 'Editors', 'url' => 'control-tower/editors'];
            $subnav['content'] = ['label' => 'Content Health', 'url' => 'control-tower/content'];
            $subnav['queue'] = ['label' => 'Queue Watch', 'url' => 'control-tower/queue'];
            $subnav['system'] = ['label' => 'System Pulse', 'url' => 'control-tower/system'];
            $subnav['alerts'] = ['label' => 'Alerts', 'url' => 'control-tower/alerts'];
        }
        if ($canManageAlerts) {
            $subnav['rules'] = ['label' => 'Alert Rules', 'url' => 'control-tower/rules'];
            $subnav['webhooks'] = ['label' => 'Webhooks', 'url' => 'control-tower/webhooks'];
        }
        if ($canManageSettings) {
            $subnav['settings'] = ['label' => 'Settings', 'url' => 'control-tower/settings'];
            $subnav['license'] = ['label' => 'License', 'url' => 'control-tower/license'];
        }

        $nav['subnav'] = $subnav;

        return $nav;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    public function getSettings(): Settings
    {
        /** @var Settings $settings */
        $settings = parent::getSettings();

        return $settings;
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('control-tower/_cp/_settings_fields', [
            'settings' => $this->getSettings(),
        ]);
    }

    private function _registerEventHandlers(): void
    {
        // Register widget
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = ControlTowerWidget::class;
            }
        );

        // Register CP URL rules
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['control-tower'] = 'control-tower/dashboard/overview';
                $event->rules['control-tower/visitors'] = 'control-tower/dashboard/visitors';
                $event->rules['control-tower/editors'] = 'control-tower/dashboard/editors';
                $event->rules['control-tower/content'] = 'control-tower/dashboard/content';
                $event->rules['control-tower/queue'] = 'control-tower/dashboard/queue';
                $event->rules['control-tower/system'] = 'control-tower/dashboard/system';
                $event->rules['control-tower/alerts'] = 'control-tower/dashboard/alerts';
                $event->rules['control-tower/settings'] = 'control-tower/dashboard/plugin-settings';

                // License (never gated — this is the way back in)
                $event->rules['control-tower/license'] = 'control-tower/license/index';

                // Rules
                $event->rules['control-tower/rules'] = 'control-tower/rules/index';
                $event->rules['control-tower/rules/new'] = 'control-tower/rules/edit';
                $event->rules['control-tower/rules/<id:\d+>'] = 'control-tower/rules/edit';

                // Webhooks
                $event->rules['control-tower/webhooks'] = 'control-tower/webhooks/index';
                $event->rules['control-tower/webhooks/new'] = 'control-tower/webhooks/edit';
                $event->rules['control-tower/webhooks/<id:\d+>'] = 'control-tower/webhooks/edit';

                // JSON API endpoints
                $event->rules['control-tower/api/overview'] = 'control-tower/api/overview';
                $event->rules['control-tower/api/visitors'] = 'control-tower/api/visitors';
                $event->rules['control-tower/api/editors'] = 'control-tower/api/editors';
                $event->rules['control-tower/api/content'] = 'control-tower/api/content';
                $event->rules['control-tower/api/queue'] = 'control-tower/api/queue';
                $event->rules['control-tower/api/system'] = 'control-tower/api/system';
                $event->rules['control-tower/api/alerts'] = 'control-tower/api/alerts';
                $event->rules['control-tower/api/widget'] = 'control-tower/api/widget';
            }
        );

        // Register user permissions
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'Control Tower',
                    'permissions' => [
                        self::PERMISSION_VIEW => [
                            'label' => 'View Control Tower dashboards',
                        ],
                        self::PERMISSION_MANAGE_ALERTS => [
                            'label' => 'Manage alert rules and webhooks',
                        ],
                        self::PERMISSION_MANAGE_SETTINGS => [
                            'label' => 'Edit Control Tower settings',
                        ],
                    ],
                ];
            }
        );
    }

    private function _registerContentEventListeners(): void
    {
        // Track entry saves
        Event::on(
            \craft\elements\Entry::class,
            \craft\elements\Entry::EVENT_AFTER_SAVE,
            function (\craft\events\ModelEvent $event) {
                $this->contentHealth->recordContentEvent(
                    'entry',
                    $event->sender->id,
                    $event->isNew ? 'created' : 'saved',
                    $event->sender->section?->handle,
                );
            }
        );

        // Track entry deletes
        Event::on(
            \craft\elements\Entry::class,
            \craft\elements\Entry::EVENT_AFTER_DELETE,
            function (Event $event) {
                $this->contentHealth->recordContentEvent(
                    'entry',
                    $event->sender->id,
                    'deleted',
                    $event->sender->section?->handle,
                );
            }
        );

        // Track asset saves
        Event::on(
            \craft\elements\Asset::class,
            \craft\elements\Asset::EVENT_AFTER_SAVE,
            function (\craft\events\ModelEvent $event) {
                $this->contentHealth->recordContentEvent(
                    'asset',
                    $event->sender->id,
                    $event->isNew ? 'uploaded' : 'saved',
                );
            }
        );
    }

    private function _trackCpActivity(): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return;
        }

        $request = Craft::$app->getRequest();
        $this->editorTracking->recordActivity(
            (int) $user->id,
            $request->getPathInfo(),
            $request->getUrl(),
        );
    }

    private function _trackSiteVisitor(): void
    {
        $settings = $this->getSettings();
        if (!$settings->trackVisitors) {
            return;
        }

        $request = Craft::$app->getRequest();
        $this->visitorTracking->recordVisit(
            $request->getPathInfo(),
            $request->getUserAgent(),
            $request->getReferrer(),
            $request->getMethod(),
            Craft::$app->getUser()->getIsGuest(),
        );
    }
}
