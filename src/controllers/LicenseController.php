<?php

namespace justinholtweb\controltower\controllers;

use Craft;
use craft\errors\InvalidLicenseKeyException;
use craft\web\Controller;
use justinholtweb\controltower\assets\ControlTowerCpAsset;
use justinholtweb\controltower\Plugin;
use yii\web\Response;

/**
 * The license screen. Deliberately not gated — it's how a locked install gets
 * unlocked again.
 */
class LicenseController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();

        // Any Control Tower user may see why the plugin is locked; only admins
        // may change the key, since that writes to project config.
        if (in_array($action->id, ['save', 'refresh'], true)) {
            $this->requireAdmin();
        } else {
            $this->requirePermissions();
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $this->view->registerAssetBundle(ControlTowerCpAsset::class);

        $license = Plugin::getInstance()->license;
        $generalConfig = Craft::$app->getConfig()->getGeneral();

        return $this->renderTemplate('control-tower/_cp/license', [
            'title' => 'License — Control Tower',
            'selectedTab' => 'license',
            'settings' => Plugin::getInstance()->getSettings(),
            'status' => $license->getStatus()->value,
            'statusMessage' => $license->getStatusMessage(),
            'isValid' => $license->getIsValid(),
            'isTrial' => $license->getIsTrial(),
            'isEnforced' => $license->getIsEnforced(),
            'licenseKey' => $license->getKey(),
            'pluginVersion' => Plugin::getInstance()->getVersion(),
            'issues' => $license->getIssues(),
            'canEditKey' => Craft::$app->getUser()->getIsAdmin() && $generalConfig->allowAdminChanges,
            'allowAdminChanges' => $generalConfig->allowAdminChanges,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $this->setFailFlash('License keys can’t be changed when `allowAdminChanges` is disabled.');

            return $this->redirectToPostedUrl();
        }

        $key = $this->request->getBodyParam('licenseKey');
        $key = is_string($key) ? trim($key) : null;

        $license = Plugin::getInstance()->license;

        try {
            $license->setKey($key);
        } catch (InvalidLicenseKeyException) {
            $this->setFailFlash('That doesn’t look like a valid Control Tower license key.');

            return $this->redirectToPostedUrl();
        }

        // Re-check immediately. Without this the install sits at `unknown` —
        // and therefore locked — until Craft's next scheduled update check,
        // which would make a correct key look like it did nothing.
        if ($license->refresh()) {
            $this->setSuccessFlash('License key saved.');
        } else {
            $this->setSuccessFlash('License key saved, but Craftnet couldn’t be reached to verify it. Try refreshing the status shortly.');
        }

        return $this->redirectToPostedUrl();
    }

    public function actionRefresh(): ?Response
    {
        $this->requirePostRequest();

        if (Plugin::getInstance()->license->refresh()) {
            $this->setSuccessFlash('License status refreshed.');
        } else {
            $this->setFailFlash('Couldn’t reach Craftnet to refresh the license status.');
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Passes if the user holds any Control Tower permission.
     */
    private function requirePermissions(): void
    {
        $user = Craft::$app->getUser();

        foreach ([Plugin::PERMISSION_VIEW, Plugin::PERMISSION_MANAGE_ALERTS, Plugin::PERMISSION_MANAGE_SETTINGS] as $permission) {
            if ($user->checkPermission($permission)) {
                return;
            }
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);
    }
}
