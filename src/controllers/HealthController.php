<?php

namespace justinholtweb\controltower\controllers;

use craft\web\Controller;
use justinholtweb\controltower\Plugin;
use justinholtweb\controltower\services\HealthService;
use yii\web\Response;

/**
 * `GET /control-tower/health` — the health report for uptime monitors (Oh Dear, Better Stack,
 * Uptime Kuma…). Answers 200 when healthy and 503 when an alert is firing or the checks have
 * stalled, so a plain status-code monitor works without parsing anything.
 *
 * Anonymous by necessity, so it is closed until a token of at least 32 characters is configured,
 * and every caller without that exact token gets the same short, empty-handed answer.
 */
class HealthController extends Controller
{
    /** Header monitors that can't send `Authorization` can use instead. */
    public const TOKEN_HEADER = 'X-Control-Tower-Token';

    /** Query-string fallback. Not `token` — Craft 5.9+ refuses a request whose `token` it doesn't recognise. */
    public const TOKEN_PARAM = 'key';

    protected array|bool|int $allowAnonymous = ['index' => self::ALLOW_ANONYMOUS_LIVE];

    public $enableCsrfValidation = false;

    public function actionIndex(): Response
    {
        $this->requireSiteRequest();

        $response = $this->response;
        $response->getHeaders()
            ->set('Cache-Control', 'no-store, private')
            ->set('X-Robots-Tag', 'noindex');

        $health = Plugin::getInstance()->health;

        // Off (no usable token): indistinguishable from a route that doesn't exist.
        if (!$health->getIsEnabled()) {
            $response->setStatusCode(404);

            return $this->asJson(['error' => 'Not found.']);
        }

        if (!$health->verifyToken($this->_providedToken())) {
            $response->setStatusCode(401);
            $response->getHeaders()->set('WWW-Authenticate', 'Bearer');

            return $this->asJson(['error' => 'A valid token is required.']);
        }

        $report = $health->getReport(HealthService::normalizeSeverity($this->request->getQueryParam('failOn')));
        $response->setStatusCode($report['status'] === HealthService::STATUS_OK ? 200 : 503);

        return $this->asJson($report);
    }

    private function _providedToken(): mixed
    {
        $headers = $this->request->getHeaders();

        $authorization = $headers->get('Authorization');
        if (is_string($authorization) && preg_match('/^Bearer\s+(\S+)\s*$/i', $authorization, $m)) {
            return $m[1];
        }

        $header = $headers->get(self::TOKEN_HEADER);
        if (is_string($header) && $header !== '') {
            return $header;
        }

        return $this->request->getQueryParam(self::TOKEN_PARAM);
    }
}
