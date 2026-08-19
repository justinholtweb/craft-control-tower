<?php

namespace justinholtweb\controltower\controllers;

use craft\helpers\UrlHelper;
use justinholtweb\controltower\Plugin;

/**
 * Blocks a controller's actions when the plugin isn't licensed.
 *
 * Call from `beforeAction()` and return its result. Browser requests are sent
 * to the license screen; JSON requests get a 402 with a machine-readable flag
 * so polling dashboards can stand down instead of retrying forever.
 */
trait LicenseGateTrait
{
    protected function enforceLicense(): bool
    {
        $license = Plugin::getInstance()->license;

        if ($license->getIsValid()) {
            return true;
        }

        if ($this->request->getAcceptsJson()) {
            $this->asJson([
                'licensed' => false,
                'status' => $license->getStatus()->value,
                'error' => $license->getStatusMessage(),
            ]);
            $this->response->setStatusCode(402);

            return false;
        }

        $this->response->redirect(UrlHelper::cpUrl('control-tower/license'));

        return false;
    }
}
