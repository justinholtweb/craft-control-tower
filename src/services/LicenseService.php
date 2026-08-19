<?php

namespace justinholtweb\controltower\services;

use Craft;
use craft\base\Component;
use craft\enums\LicenseKeyStatus;
use craft\errors\InvalidLicenseKeyException;
use craft\errors\InvalidPluginException;
use justinholtweb\controltower\Plugin;
use Throwable;

/**
 * Wraps Craft's Craftnet license validation for Control Tower.
 *
 * Craft refreshes plugin license statuses out-of-band (during its own update
 * checks) and stores the result with the plugin's project config info, so
 * reading a status here is a cheap in-memory lookup — no HTTP request.
 *
 * Enforcement is strict: only `valid` and `trial` unlock the plugin. `unknown`
 * — which is what Craft reports before it has ever reached Craftnet — is
 * treated as unlicensed.
 *
 * Note what this does *not* gate. An expired license keeps reporting `valid`
 * for as long as the install stays on a version the license covers, because
 * Craft licenses are perpetual for versions released before expiry. Craftnet
 * compares the installed version against the license's renewal-stopped version
 * itself and only reports `astray` once the install has moved past it. So
 * lapsing a renewal costs nothing until the customer updates — which is the
 * behavior Craft users expect, and it falls out of the status alone. Never
 * reimplement that comparison here.
 *
 * Two escape hatches keep strictness from being a footgun:
 *  - `canTestEditions` (Craft's local/dev domain flag) always unlocks.
 *  - The `disableLicenseEnforcement` config setting always unlocks.
 */
class LicenseService extends Component
{
    /**
     * Statuses that grant access. Everything else is gated.
     */
    private const ALLOWED_STATUSES = [
        LicenseKeyStatus::Valid,
        LicenseKeyStatus::Trial,
    ];

    /**
     * Cache key for the dev-domain bypass, mirrored from web requests so that
     * console requests (queue workers, cron) reach the same verdict.
     *
     * @see self::_canTestEditions()
     */
    private const CACHE_KEY_TESTABLE = 'controltower.editionTestable';

    private const CACHE_DURATION_TESTABLE = 86400;

    private ?bool $_isValid = null;

    /**
     * The plugin's license key status, straight from Craft.
     */
    public function getStatus(): LicenseKeyStatus
    {
        return Craft::$app->getPlugins()->getPluginLicenseKeyStatus(Plugin::HANDLE);
    }

    /**
     * The stored license key, if one has been entered. May be an environment
     * variable reference (e.g. `$CONTROL_TOWER_LICENSE_KEY`).
     */
    public function getKey(): ?string
    {
        return Craft::$app->getPlugins()->getPluginLicenseKey(Plugin::HANDLE);
    }

    /**
     * Craft's own list of license issues for this plugin (`required`,
     * `no_trials`, `invalid`, `mismatched`, `astray`, `wrong_edition`).
     *
     * @return string[]
     */
    public function getIssues(): array
    {
        return Craft::$app->getPlugins()->getLicenseIssues(Plugin::HANDLE);
    }

    /**
     * Whether the plugin's functionality is unlocked on this install.
     *
     * Memoized — this is consulted on every CP and site request.
     */
    public function getIsValid(): bool
    {
        return $this->_isValid ??= $this->_resolveIsValid();
    }

    /**
     * Whether the install is running on an unexpired trial. Callers use this to
     * nag rather than to gate.
     */
    public function getIsTrial(): bool
    {
        return $this->getStatus() === LicenseKeyStatus::Trial;
    }

    /**
     * Whether enforcement is switched off, either by config or because Craft
     * considers this a testable (local/dev) domain.
     */
    public function getIsEnforced(): bool
    {
        return !Plugin::getInstance()->getSettings()->disableLicenseEnforcement
            && !$this->_canTestEditions();
    }

    /**
     * A short, user-facing explanation of the current status.
     */
    public function getStatusMessage(): string
    {
        if (!$this->getIsEnforced()) {
            return 'License enforcement is disabled on this environment. Control Tower is fully unlocked.';
        }

        return match ($this->getStatus()) {
            LicenseKeyStatus::Valid => 'Control Tower is licensed for this domain.',
            LicenseKeyStatus::Trial => 'Control Tower is running on a trial. Buy a license before this site goes live.',
            LicenseKeyStatus::Invalid => 'This license key isn’t valid. Check it for typos, or buy a license.',
            LicenseKeyStatus::Mismatched => 'This license key belongs to a different domain. Transfer it to this domain from your Craft Console account.',
            LicenseKeyStatus::Astray => sprintf(
                'This license doesn’t cover Control Tower %s. Licenses cover every version released before they expire, so renewing unlocks this version — or you can downgrade to the last version your license covers and keep running without renewing.',
                Plugin::getInstance()->getVersion(),
            ),
            LicenseKeyStatus::Unknown => 'Control Tower hasn’t been licensed on this install yet. Enter a license key to unlock it.',
        };
    }

    /**
     * Asks Craftnet for fresh license info.
     *
     * Any API request refreshes the status as a side effect — Craft reads it off
     * the response headers — so this doubles as "re-check now" after a key is
     * entered, instead of leaving the install locked at `unknown` until Craft's
     * next scheduled update check.
     *
     * @return bool Whether the refresh reached Craftnet
     */
    public function refresh(): bool
    {
        try {
            Craft::$app->getApi()->getLicenseInfo();
        } catch (Throwable $e) {
            Craft::warning("Couldn’t refresh Control Tower license info: {$e->getMessage()}", __METHOD__);

            return false;
        }

        $this->_isValid = null;

        return true;
    }

    /**
     * Stores a license key against the plugin and clears Craft's license cache
     * so the next status read reflects it.
     *
     * @throws InvalidLicenseKeyException if the key isn't a well-formed key
     * @throws InvalidPluginException if the plugin isn't installed
     */
    public function setKey(?string $key): bool
    {
        $saved = Craft::$app->getPlugins()->setPluginLicenseKey(Plugin::HANDLE, $key ?: null);

        // Force the next getIsValid() call to re-derive from the new key
        $this->_isValid = null;

        return $saved;
    }

    private function _resolveIsValid(): bool
    {
        if (!$this->getIsEnforced()) {
            return true;
        }

        return in_array($this->getStatus(), self::ALLOWED_STATUSES, true);
    }

    /**
     * Craft only reports `canTestEditions` for web requests, so a console
     * request (queue worker, cron) on a dev machine would otherwise disagree
     * with the browser sitting next to it. Mirror the web verdict into the
     * cache and read it back on the console side.
     */
    private function _canTestEditions(): bool
    {
        $cache = Craft::$app->getCache();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return (bool) $cache->get(self::CACHE_KEY_TESTABLE);
        }

        $canTest = Craft::$app->getCanTestEditions();
        $cache->set(self::CACHE_KEY_TESTABLE, $canTest, self::CACHE_DURATION_TESTABLE);

        return $canTest;
    }
}
