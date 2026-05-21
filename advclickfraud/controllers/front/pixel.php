<?php
/**
 * Lightweight landing pixel endpoint for ad-click evidence.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdvClickFraudPixelModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $ssl = true;

    public function initContent(): void
    {
        parent::initContent();

        if ($this->module instanceof AdvClickFraud && $this->module->isEnabledForCurrentShop()) {
            $this->module->storePixelEvent((string) Tools::getValue('rid', $this->module->getRequestId()));
        }

        $pixel = base64_decode('R0lGODlhAQABAPAAAP///wAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==');
        header('Content-Type: image/gif');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo $pixel;
        exit;
    }
}
