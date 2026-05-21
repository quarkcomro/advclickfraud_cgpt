<?php
/**
 * Client-side signal collector endpoint.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdvClickFraudCollectModuleFrontController extends ModuleFrontController
{
    private const MAX_PAYLOAD_BYTES = 32768;

    public $ajax = true;
    public $ssl = true;

    public function postProcess(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        if (!$this->module instanceof AdvClickFraud || !$this->module->isEnabledForCurrentShop()) {
            $this->renderJson(['ok' => false]);
            return;
        }

        if ((string) ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            $this->renderJson(['ok' => false], 405);
            return;
        }

        $rawPayload = (string) Tools::file_get_contents('php://input');
        if ($rawPayload === '') {
            $this->renderJson(['ok' => false], 400);
            return;
        }

        if (strlen($rawPayload) > self::MAX_PAYLOAD_BYTES) {
            $this->renderJson(['ok' => false], 413);
            return;
        }

        try {
            $payload = json_decode($rawPayload, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            unset($exception);
            $this->renderJson(['ok' => false], 400);
            return;
        }

        if (!is_array($payload)) {
            $this->renderJson(['ok' => false], 400);
            return;
        }

        $this->module->storeClientEvent($payload);
        $this->renderJson(['ok' => true]);
    }

    private function renderJson(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);

        try {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            unset($exception);
            $json = '{"ok":false}';
        }

        $this->ajaxRender($json);
    }
}
