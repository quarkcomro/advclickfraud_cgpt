<?php
/**
 * Client-side signal collector endpoint.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdvClickFraudCollectModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $ssl = true;

    public function postProcess(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->module instanceof AdvClickFraud || !$this->module->isEnabledForCurrentShop()) {
            $this->ajaxRender(json_encode(['ok' => false]));
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('HTTP/1.1 405 Method Not Allowed');
            $this->ajaxRender(json_encode(['ok' => false]));
            return;
        }

        $payload = json_decode((string) Tools::file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            header('HTTP/1.1 400 Bad Request');
            $this->ajaxRender(json_encode(['ok' => false]));
            return;
        }

        $this->module->storeClientEvent($payload);
        $this->ajaxRender(json_encode(['ok' => true]));
    }
}
