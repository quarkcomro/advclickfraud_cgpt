<?php
/**
 * Advanced Click Fraud and Scraping Protection.
 *
 * Defensive PrestaShop module for browser fingerprint correlation,
 * anti-scraping scoring, and advertising click-fraud evidence collection.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdvClickFraud extends Module
{
    public const CFG_ENABLED = 'ADVCLICKFRAUD_ENABLED';
    public const CFG_MODE = 'ADVCLICKFRAUD_MODE';
    public const CFG_SECRET = 'ADVCLICKFRAUD_SECRET';
    public const CFG_RETENTION_DAYS = 'ADVCLICKFRAUD_RETENTION_DAYS';
    public const CFG_ENABLE_SCRAPING = 'ADVCLICKFRAUD_ENABLE_SCRAPING';
    public const CFG_ENABLE_CLICK_FRAUD = 'ADVCLICKFRAUD_ENABLE_CLICK_FRAUD';
    public const CFG_ENABLE_JA4 = 'ADVCLICKFRAUD_ENABLE_JA4';
    public const CFG_TRUSTED_PROXIES = 'ADVCLICKFRAUD_TRUSTED_PROXIES';
    public const CFG_JA4_HEADER = 'ADVCLICKFRAUD_JA4_HEADER';
    public const CFG_JA4H_HEADER = 'ADVCLICKFRAUD_JA4H_HEADER';
    public const CFG_PRODUCT_THRESHOLD = 'ADVCLICKFRAUD_PRODUCT_THRESHOLD';
    public const CFG_SEARCH_THRESHOLD = 'ADVCLICKFRAUD_SEARCH_THRESHOLD';
    public const CFG_BLOCK_MINUTES = 'ADVCLICKFRAUD_BLOCK_MINUTES';

    private const DOMAIN_ADMIN = 'Modules.Advclickfraud.Admin';
    private const DOMAIN_SHOP = 'Modules.Advclickfraud.Shop';

    public function __construct()
    {
        $this->name = 'advclickfraud';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'QuarkCom';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.2.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->trans('Advanced Click Fraud Protection', [], self::DOMAIN_ADMIN);
        $this->description = $this->trans('Collects defensive browser, click and network-fingerprint signals to detect scraping and advertising click fraud.', [], self::DOMAIN_ADMIN);
        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall this module and remove its stored fraud-risk data?', [], self::DOMAIN_ADMIN);
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install(): bool
    {
        return parent::install()
            && $this->installConfiguration()
            && $this->installDatabase()
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayFooter')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('actionDispatcher')
            && $this->registerHook('moduleRoutes');
    }

    public function uninstall(): bool
    {
        return $this->uninstallDatabase() && $this->uninstallConfiguration() && parent::uninstall();
    }

    public function getContent(): string
    {
        $output = '';
        if (Tools::isSubmit('submitAdvClickFraudConfiguration')) {
            $output .= $this->postProcessConfiguration();
        }

        $this->context->smarty->assign([
            'configuration_form' => $this->renderConfigurationForm(),
            'panel_title' => $this->trans('Configuration', [], self::DOMAIN_ADMIN),
            'manual_title' => $this->trans('User manual', [], self::DOMAIN_ADMIN),
            'manual_help' => $this->trans('The manual is included in the module package under docs/.', [], self::DOMAIN_ADMIN),
        ]);

        return $output . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    public function hookActionFrontControllerSetMedia(array $params): void
    {
        unset($params);
        if (!$this->isEnabledForCurrentShop()) {
            return;
        }
        $this->context->controller->registerJavascript(
            'module-advclickfraud-front',
            'modules/' . $this->name . '/views/js/front.js',
            ['position' => 'bottom', 'priority' => 250]
        );
    }

    public function hookDisplayHeader(array $params): string
    {
        unset($params);
        if (!$this->isEnabledForCurrentShop()) {
            return '';
        }

        Media::addJsDef([
            'advClickFraud' => [
                'requestId' => $this->getRequestId(),
                'collectUrl' => $this->context->link->getModuleLink($this->name, 'collect', [], true),
                'pageType' => $this->resolvePageType(),
                'shopId' => (int) $this->context->shop->id,
            ],
        ]);

        return '';
    }

    public function hookDisplayFooter(array $params): string
    {
        unset($params);
        if (!$this->isEnabledForCurrentShop() || !$this->hasAdClickIdentifier()) {
            return '';
        }

        $this->context->smarty->assign([
            'pixel_url' => $this->context->link->getModuleLink($this->name, 'pixel', ['rid' => $this->getRequestId()], true),
            'pixel_alt' => $this->trans('Fraud protection pixel', [], self::DOMAIN_SHOP),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/pixel.tpl');
    }

    public function hookActionDispatcher(array $params): void
    {
        unset($params);
        if (!$this->isEnabledForCurrentShop()) {
            return;
        }

        $decision = $this->evaluateServerRequest();
        if ($decision['action'] === 'block') {
            header('HTTP/1.1 429 Too Many Requests');
            header('Retry-After: ' . ((int) $this->getConfig(self::CFG_BLOCK_MINUTES) * 60));
            exit($this->trans('Too many requests. Please try again later.', [], self::DOMAIN_SHOP));
        }
    }

    public function hookModuleRoutes(array $params): array
    {
        unset($params);
        return [
            'module-advclickfraud-collect' => [
                'controller' => 'collect',
                'rule' => 'advclickfraud/collect',
                'keywords' => [],
                'params' => ['fc' => 'module', 'module' => $this->name],
            ],
            'module-advclickfraud-pixel' => [
                'controller' => 'pixel',
                'rule' => 'advclickfraud/pixel',
                'keywords' => [],
                'params' => ['fc' => 'module', 'module' => $this->name],
            ],
        ];
    }

    public function storeClientEvent(array $payload): bool
    {
        $clientFingerprint = $this->fingerprintPayload($payload);
        $networkFingerprint = $this->networkFingerprint();
        $decision = $this->evaluateClientPayload($payload);

        return $this->insertEvent('client_collect', $clientFingerprint, $networkFingerprint, $decision['risk'], $decision['decision'], $decision['reasons'], [
            'page_type' => isset($payload['pageType']) && is_scalar($payload['pageType']) ? (string) $payload['pageType'] : 'page',
            'click_ids' => $this->extractClickIdentifiers($payload),
        ]);
    }

    public function storePixelEvent(string $requestId): bool
    {
        return $this->insertEvent('pixel', null, $this->networkFingerprint(), 0, 'observe', [], [
            'request_id' => $requestId,
            'referer_present' => isset($_SERVER['HTTP_REFERER']),
        ]);
    }

    public function isEnabledForCurrentShop(): bool
    {
        return (bool) (int) $this->getConfig(self::CFG_ENABLED);
    }

    public function getRequestId(): string
    {
        if (!isset($this->context->cookie->advclickfraud_request_id)) {
            $this->context->cookie->advclickfraud_request_id = bin2hex(random_bytes(16));
        }

        return (string) $this->context->cookie->advclickfraud_request_id;
    }

    public function hmac(string $value): string
    {
        $secret = $this->getConfig(self::CFG_SECRET);
        if ($secret === '') {
            $secret = _COOKIE_KEY_;
        }

        return hash_hmac('sha256', $value, $secret);
    }

    private function renderConfigurationForm(): string
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->table = $this->table;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitAdvClickFraudConfiguration';
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->fields_value = $this->configurationValues();

        return $helper->generateForm([$this->configurationFormDefinition()]);
    }

    private function configurationFormDefinition(): array
    {
        return ['form' => [
            'legend' => ['title' => $this->trans('Advanced Click Fraud Protection', [], self::DOMAIN_ADMIN), 'icon' => 'icon-shield'],
            'input' => [
                $this->switchField(self::CFG_ENABLED, 'Enable module', 'Enables signal collection and risk evaluation for the current shop context.'),
                ['type' => 'select', 'label' => $this->trans('Operating mode', [], self::DOMAIN_ADMIN), 'name' => self::CFG_MODE, 'desc' => $this->trans('Observe records decisions only. Enable blocking after reviewing logs.', [], self::DOMAIN_ADMIN), 'options' => ['query' => [['id' => 'observe', 'name' => $this->trans('Observe only', [], self::DOMAIN_ADMIN)], ['id' => 'rate_limit', 'name' => $this->trans('Rate limit', [], self::DOMAIN_ADMIN)], ['id' => 'block', 'name' => $this->trans('Block high-risk traffic', [], self::DOMAIN_ADMIN)]], 'id' => 'id', 'name' => 'name']],
                $this->textField(self::CFG_RETENTION_DAYS, 'Log retention days', 'Number of days to keep detailed risk events before cleanup.', '30'),
                $this->switchField(self::CFG_ENABLE_JA4, 'Enable JA4 correlation', 'Correlates browser fingerprints with TLS/network fingerprints received from a trusted edge proxy.'),
                $this->textareaField(self::CFG_TRUSTED_PROXIES, 'Trusted proxy IP addresses', 'One proxy IP address per line. JA4 headers are ignored unless the request comes from this list.', "203.0.113.10\n198.51.100.10"),
                $this->textField(self::CFG_JA4_HEADER, 'JA4 header name', 'Internal header set by your CDN, WAF, HAProxy, NGINX or edge worker.', 'X-AdvCF-JA4'),
                $this->textField(self::CFG_JA4H_HEADER, 'JA4H header name', 'Optional internal HTTP fingerprint header set only by trusted infrastructure.', 'X-AdvCF-JA4H'),
                $this->switchField(self::CFG_ENABLE_SCRAPING, 'Enable anti-scraping scoring', 'Evaluates catalog, search and API request patterns for scraping abuse.'),
                $this->textField(self::CFG_PRODUCT_THRESHOLD, 'Product page threshold', 'Maximum product-like page requests per fingerprint window before risk increases.', '120'),
                $this->textField(self::CFG_SEARCH_THRESHOLD, 'Search threshold', 'Maximum search-like requests per fingerprint window before risk increases.', '50'),
                $this->switchField(self::CFG_ENABLE_CLICK_FRAUD, 'Enable ad click fraud scoring', 'Tracks advertising click identifiers and post-click behavior to flag suspicious paid traffic.'),
                $this->textField(self::CFG_BLOCK_MINUTES, 'Temporary block duration in minutes', 'Used only when block mode is enabled and the risk score reaches the blocking threshold.', '15'),
            ],
            'submit' => ['title' => $this->trans('Save settings', [], self::DOMAIN_ADMIN)],
        ]];
    }

    private function postProcessConfiguration(): string
    {
        $mode = (string) Tools::getValue(self::CFG_MODE, 'observe');
        if (!in_array($mode, ['observe', 'rate_limit', 'block'], true)) {
            return $this->displayError($this->trans('Invalid operating mode.', [], self::DOMAIN_ADMIN));
        }

        $values = [
            self::CFG_ENABLED => (string) (int) Tools::getValue(self::CFG_ENABLED),
            self::CFG_MODE => $mode,
            self::CFG_RETENTION_DAYS => (string) max(1, (int) Tools::getValue(self::CFG_RETENTION_DAYS, 30)),
            self::CFG_ENABLE_JA4 => (string) (int) Tools::getValue(self::CFG_ENABLE_JA4),
            self::CFG_TRUSTED_PROXIES => trim((string) Tools::getValue(self::CFG_TRUSTED_PROXIES)),
            self::CFG_JA4_HEADER => trim((string) Tools::getValue(self::CFG_JA4_HEADER, 'X-AdvCF-JA4')),
            self::CFG_JA4H_HEADER => trim((string) Tools::getValue(self::CFG_JA4H_HEADER, 'X-AdvCF-JA4H')),
            self::CFG_ENABLE_SCRAPING => (string) (int) Tools::getValue(self::CFG_ENABLE_SCRAPING),
            self::CFG_PRODUCT_THRESHOLD => (string) max(1, (int) Tools::getValue(self::CFG_PRODUCT_THRESHOLD, 120)),
            self::CFG_SEARCH_THRESHOLD => (string) max(1, (int) Tools::getValue(self::CFG_SEARCH_THRESHOLD, 50)),
            self::CFG_ENABLE_CLICK_FRAUD => (string) (int) Tools::getValue(self::CFG_ENABLE_CLICK_FRAUD),
            self::CFG_BLOCK_MINUTES => (string) max(1, (int) Tools::getValue(self::CFG_BLOCK_MINUTES, 15)),
        ];

        foreach ($values as $key => $value) {
            $this->setConfig($key, $value);
        }

        return $this->displayConfirmation($this->trans('Settings updated.', [], self::DOMAIN_ADMIN));
    }

    private function configurationValues(): array
    {
        $keys = [self::CFG_ENABLED, self::CFG_MODE, self::CFG_RETENTION_DAYS, self::CFG_ENABLE_JA4, self::CFG_TRUSTED_PROXIES, self::CFG_JA4_HEADER, self::CFG_JA4H_HEADER, self::CFG_ENABLE_SCRAPING, self::CFG_PRODUCT_THRESHOLD, self::CFG_SEARCH_THRESHOLD, self::CFG_ENABLE_CLICK_FRAUD, self::CFG_BLOCK_MINUTES];
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->getConfig($key);
        }
        return $values;
    }

    private function switchField(string $name, string $label, string $description): array
    {
        return ['type' => 'switch', 'label' => $this->trans($label, [], self::DOMAIN_ADMIN), 'name' => $name, 'desc' => $this->trans($description, [], self::DOMAIN_ADMIN), 'values' => [['id' => $name . '_on', 'value' => 1, 'label' => $this->trans('Yes', [], self::DOMAIN_ADMIN)], ['id' => $name . '_off', 'value' => 0, 'label' => $this->trans('No', [], self::DOMAIN_ADMIN)]]];
    }

    private function textField(string $name, string $label, string $description, string $placeholder): array
    {
        return ['type' => 'text', 'label' => $this->trans($label, [], self::DOMAIN_ADMIN), 'name' => $name, 'desc' => $this->trans($description, [], self::DOMAIN_ADMIN), 'placeholder' => $placeholder];
    }

    private function textareaField(string $name, string $label, string $description, string $placeholder): array
    {
        return ['type' => 'textarea', 'label' => $this->trans($label, [], self::DOMAIN_ADMIN), 'name' => $name, 'desc' => $this->trans($description, [], self::DOMAIN_ADMIN), 'placeholder' => $placeholder, 'cols' => 60, 'rows' => 4];
    }

    private function installConfiguration(): bool
    {
        $defaults = [
            self::CFG_ENABLED => '0', self::CFG_MODE => 'observe', self::CFG_SECRET => bin2hex(random_bytes(32)), self::CFG_RETENTION_DAYS => '30',
            self::CFG_ENABLE_SCRAPING => '1', self::CFG_ENABLE_CLICK_FRAUD => '1', self::CFG_ENABLE_JA4 => '0', self::CFG_TRUSTED_PROXIES => '', self::CFG_JA4_HEADER => 'X-AdvCF-JA4', self::CFG_JA4H_HEADER => 'X-AdvCF-JA4H',
            self::CFG_PRODUCT_THRESHOLD => '120', self::CFG_SEARCH_THRESHOLD => '50', self::CFG_BLOCK_MINUTES => '15',
        ];
        foreach ($defaults as $key => $value) {
            if (!$this->setConfig($key, $value)) {
                return false;
            }
        }
        return true;
    }

    private function uninstallConfiguration(): bool
    {
        foreach ([self::CFG_ENABLED, self::CFG_MODE, self::CFG_SECRET, self::CFG_RETENTION_DAYS, self::CFG_ENABLE_SCRAPING, self::CFG_ENABLE_CLICK_FRAUD, self::CFG_ENABLE_JA4, self::CFG_TRUSTED_PROXIES, self::CFG_JA4_HEADER, self::CFG_JA4H_HEADER, self::CFG_PRODUCT_THRESHOLD, self::CFG_SEARCH_THRESHOLD, self::CFG_BLOCK_MINUTES] as $key) {
            Configuration::deleteByName($key);
        }
        return true;
    }

    private function installDatabase(): bool
    {
        $eventSql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'advclickfraud_event` (`id_advclickfraud_event` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_shop` INT UNSIGNED NOT NULL DEFAULT 0, `event_type` VARCHAR(64) NOT NULL, `request_id` VARCHAR(64) NOT NULL, `client_fingerprint` CHAR(64) NULL, `network_fingerprint` CHAR(64) NULL, `risk_score` TINYINT UNSIGNED NOT NULL DEFAULT 0, `decision` VARCHAR(32) NOT NULL DEFAULT "observe", `reason_codes` TEXT NULL, `payload` TEXT NULL, `ip_hash` CHAR(64) NULL, `user_agent_hash` CHAR(64) NULL, `date_add` DATETIME NOT NULL, PRIMARY KEY (`id_advclickfraud_event`), KEY `idx_shop_date` (`id_shop`, `date_add`), KEY `idx_client` (`client_fingerprint`), KEY `idx_network` (`network_fingerprint`)) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';
        $rateSql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'advclickfraud_rate_limit` (`id_advclickfraud_rate_limit` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_shop` INT UNSIGNED NOT NULL DEFAULT 0, `scope_hash` CHAR(64) NOT NULL, `route_type` VARCHAR(32) NOT NULL, `hits` INT UNSIGNED NOT NULL DEFAULT 0, `window_start` DATETIME NOT NULL, `date_upd` DATETIME NOT NULL, PRIMARY KEY (`id_advclickfraud_rate_limit`), UNIQUE KEY `uniq_scope_route_window` (`id_shop`, `scope_hash`, `route_type`, `window_start`)) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';
        return Db::getInstance()->execute($eventSql) && Db::getInstance()->execute($rateSql);
    }

    private function uninstallDatabase(): bool
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'advclickfraud_event`') && Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'advclickfraud_rate_limit`');
    }

    private function evaluateServerRequest(): array
    {
        $score = 0;
        $reasons = [];
        $route = $this->resolvePageType();
        if ((bool) (int) $this->getConfig(self::CFG_ENABLE_SCRAPING)) {
            $hits = $this->incrementRateLimit($this->hmac((string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), $route);
            $threshold = $route === 'search' ? (int) $this->getConfig(self::CFG_SEARCH_THRESHOLD) : (int) $this->getConfig(self::CFG_PRODUCT_THRESHOLD);
            if (($route === 'product' || $route === 'category' || $route === 'search') && $hits > $threshold) {
                $score += 60;
                $reasons[] = 'route_velocity_threshold_exceeded';
            }
        }
        if ($this->hasAutomationUserAgent()) {
            $score += 25;
            $reasons[] = 'suspicious_automation_user_agent';
        }
        $mode = $this->getConfig(self::CFG_MODE);
        $action = 'observe';
        if ($mode === 'rate_limit' && $score >= 70) {
            $action = 'block';
        }
        if ($mode === 'block' && $score >= 85) {
            $action = 'block';
        }
        $this->insertEvent('server_request', null, $this->networkFingerprint(), min(100, $score), $action, $reasons, ['route' => $route]);
        return ['action' => $action, 'risk' => min(100, $score), 'reasons' => $reasons];
    }

    private function evaluateClientPayload(array $payload): array
    {
        $score = 0;
        $reasons = [];
        $signals = isset($payload['signals']) && is_array($payload['signals']) ? $payload['signals'] : [];
        $behavior = isset($payload['behavior']) && is_array($payload['behavior']) ? $payload['behavior'] : [];
        if (!empty($signals['webdriver'])) {
            $score += 35;
            $reasons[] = 'webdriver_flag_present';
        }
        if (empty($signals['cookieRoundtrip'])) {
            $score += 10;
            $reasons[] = 'cookie_roundtrip_missing';
        }
        if (isset($behavior['timeToFirstInteractionMs']) && (int) $behavior['timeToFirstInteractionMs'] > 0 && (int) $behavior['timeToFirstInteractionMs'] < 100) {
            $score += 15;
            $reasons[] = 'very_fast_first_interaction';
        }
        return ['risk' => min(100, $score), 'decision' => 'observe', 'reasons' => $reasons];
    }

    private function insertEvent(string $type, ?string $clientFingerprint, ?string $networkFingerprint, int $risk, string $decision, array $reasons, array $payload): bool
    {
        return Db::getInstance()->insert('advclickfraud_event', [
            'id_shop' => (int) $this->context->shop->id,
            'event_type' => pSQL($type),
            'request_id' => pSQL($this->getRequestId()),
            'client_fingerprint' => $clientFingerprint !== null ? pSQL($clientFingerprint) : null,
            'network_fingerprint' => $networkFingerprint !== null ? pSQL($networkFingerprint) : null,
            'risk_score' => max(0, min(100, $risk)),
            'decision' => pSQL($decision),
            'reason_codes' => pSQL(json_encode($reasons), true),
            'payload' => pSQL(json_encode($payload), true),
            'ip_hash' => pSQL($this->hmac($this->ipPrefix())),
            'user_agent_hash' => pSQL($this->hmac((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))),
            'date_add' => pSQL(date('Y-m-d H:i:s')),
        ]);
    }

    private function incrementRateLimit(string $scopeHash, string $route): int
    {
        $windowStart = date('Y-m-d H:i:00', (int) floor(time() / 300) * 300);
        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'advclickfraud_rate_limit` (`id_shop`, `scope_hash`, `route_type`, `hits`, `window_start`, `date_upd`) VALUES (' . (int) $this->context->shop->id . ', "' . pSQL($scopeHash) . '", "' . pSQL($route) . '", 1, "' . pSQL($windowStart) . '", "' . pSQL(date('Y-m-d H:i:s')) . '") ON DUPLICATE KEY UPDATE `hits` = `hits` + 1, `date_upd` = VALUES(`date_upd`)';
        Db::getInstance()->execute($sql);
        return (int) Db::getInstance()->getValue('SELECT `hits` FROM `' . _DB_PREFIX_ . 'advclickfraud_rate_limit` WHERE `id_shop` = ' . (int) $this->context->shop->id . ' AND `scope_hash` = "' . pSQL($scopeHash) . '" AND `route_type` = "' . pSQL($route) . '" AND `window_start` = "' . pSQL($windowStart) . '"');
    }

    private function fingerprintPayload(array $payload): ?string
    {
        if (!isset($payload['signals']) || !is_array($payload['signals'])) {
            return null;
        }
        $signals = $payload['signals'];
        $normalized = [];
        foreach (['uaJs', 'platform', 'timezone', 'locale', 'screenBucket', 'dprBucket', 'canvas', 'webgl'] as $key) {
            $normalized[$key] = isset($signals[$key]) && is_scalar($signals[$key]) ? substr((string) $signals[$key], 0, 255) : '';
        }
        $normalized['touch'] = !empty($signals['touch']);
        $normalized['webdriver'] = !empty($signals['webdriver']);
        return $this->hmac(json_encode($normalized));
    }

    private function networkFingerprint(): ?string
    {
        if (!(bool) (int) $this->getConfig(self::CFG_ENABLE_JA4) || !$this->isTrustedProxyRequest()) {
            return null;
        }
        $ja4 = $this->trustedHeader($this->getConfig(self::CFG_JA4_HEADER));
        $ja4h = $this->trustedHeader($this->getConfig(self::CFG_JA4H_HEADER));
        return ($ja4 || $ja4h) ? $this->hmac((string) $ja4 . '|' . (string) $ja4h) : null;
    }

    private function trustedHeader(string $headerName): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', trim($headerName)));
        return isset($_SERVER[$key]) && is_scalar($_SERVER[$key]) ? substr((string) $_SERVER[$key], 0, 255) : null;
    }

    private function isTrustedProxyRequest(): bool
    {
        $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        foreach (preg_split('/\R+/', $this->getConfig(self::CFG_TRUSTED_PROXIES)) ?: [] as $proxy) {
            if (trim($proxy) === $remoteAddress) {
                return true;
            }
        }
        return false;
    }

    private function extractClickIdentifiers(array $payload): array
    {
        $result = [];
        $query = isset($payload['query']) && is_array($payload['query']) ? $payload['query'] : [];
        foreach (['gclid', 'fbclid', 'ttclid', 'msclkid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content'] as $key) {
            if (isset($query[$key]) && is_scalar($query[$key])) {
                $result[$key] = substr((string) $query[$key], 0, 255);
            }
        }
        return $result;
    }

    private function resolvePageType(): string
    {
        return match ((string) Tools::getValue('controller', '')) {
            'product' => 'product',
            'category' => 'category',
            'search' => 'search',
            'cart' => 'cart',
            'order' => 'checkout',
            'authentication' => 'login',
            default => 'page',
        };
    }

    private function hasAdClickIdentifier(): bool
    {
        foreach (['gclid', 'fbclid', 'ttclid', 'msclkid'] as $key) {
            if (Tools::getValue($key) !== false) {
                return true;
            }
        }
        return false;
    }

    private function hasAutomationUserAgent(): bool
    {
        $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        foreach (['headlesschrome', 'python-requests', 'curl/', 'wget/', 'go-http-client'] as $needle) {
            if (str_contains($ua, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function ipPrefix(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return implode('.', array_slice($parts, 0, 3)) . '.0';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode(':', array_slice(explode(':', $ip), 0, 4));
        }
        return 'unknown';
    }

    private function getConfig(string $key): string
    {
        return (string) Configuration::get($key, null, (int) $this->context->shop->id_shop_group, (int) $this->context->shop->id);
    }

    private function setConfig(string $key, string $value): bool
    {
        return Configuration::updateValue($key, $value, false, (int) $this->context->shop->id_shop_group, (int) $this->context->shop->id);
    }
}
