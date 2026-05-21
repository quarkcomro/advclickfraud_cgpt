<?php
/**
 * Lightweight landing pixel endpoint for ad-click evidence.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdvClickFraudPixelModuleFrontController extends ModuleFrontController
{
    private const TRANSPARENT_GIF_BASE64 = 'R0lGODlhAQABAPAAAP///wAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==';

    public $ajax = true;
    public $ssl = true;

    public function initContent(): void
    {
        parent::initContent();

        if ($this->module instanceof AdvClickFraud && $this->module->isEnabledForCurrentShop()) {
            $requestId = $this->sanitizeRequestId((string) Tools::getValue('rid', ''));
            if ($requestId === '') {
                $requestId = $this->module->getRequestId();
            }

            $this->module->storePixelEvent($requestId);
        }

        $pixel = base64_decode(self::TRANSPARENT_GIF_BASE64, true);
        if ($pixel === false) {
            $pixel = '';
        }

        header('Content-Type: image/gif');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo $pixel;
        exit;
    }

    private function sanitizeRequestId(string $requestId): string
    {
        $requestId = trim($requestId);
        if ($requestId === '' || strlen($requestId) > 64) {
            return '';
        }

        return preg_match('/\A[A-Za-z0-9_-]+\z/', $requestId) === 1 ? $requestId : '';
    }
}
