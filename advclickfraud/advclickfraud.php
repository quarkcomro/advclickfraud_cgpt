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
    public const CFG_ADMIN_REFRESH_INTERVAL = 'ADVCLICKFRAUD_ADMIN_REFRESH_INTERVAL';
    public const CFG_PURGE_DATA_ON_UNINSTALL = 'ADVCLICKFRAUD_PURGE_DATA_ON_UNINSTALL';

    private const DOMAIN_ADMIN = 'Modules.Advclickfraud.Admin';
    private const DOMAIN_SHOP = 'Modules.Advclickfraud.Shop';
    private const ADMIN_REFRESH_INTERVALS = [0, 15, 30, 60, 120];

    public function __construct()
    {
        $this->name = 'advclickfraud';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'QuarkCom';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.2.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->trans('Advanced Click Fraud Protection', [], self::DOMAIN_ADMIN);
        $this->description = $this->trans('Collects defensive browser, click and network-fingerprint signals to detect scraping and advertising click fraud.', [], self::DOMAIN_ADMIN);
        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall this module? Stored fraud data will be deleted only if the Back Office uninstall data purge setting is enabled.', [], self::DOMAIN_ADMIN);
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
            && $this->registerHook('displayBackOfficeHeader')
            && $this->registerHook('actionDispatcher')
            && $this->registerHook('moduleRoutes');
    }

    public function uninstall(): bool
    {
        $purgeData = (bool) (int) $this->getConfig(self::CFG_PURGE_DATA_ON_UNINSTALL);

        if ($purgeData && !$this->uninstallDatabase()) {
            return false;
        }

        return $this->uninstallConfiguration() && parent::uninstall();
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
            'stats' => $this->dashboardStats(),
            'recent_events' => $this->recentEvents(),
            'admin_refresh_interval' => (int) $this->getConfig(self::CFG_ADMIN_REFRESH_INTERVAL),
            'admin_refresh_disabled_label' => $this->trans('Disabled', [], self::DOMAIN_ADMIN),
        ]);

        return $output . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    public function hookActionFrontControllerSetMedia(array $params): void
    {
        unset($params);
        if ($this->isEnabledForCurrentShop()) {
            $this->context->controller->registerJavascript(
                'module-advclickfraud-front',
                'modules/' . $this->name . '/views/js/front.js',
                ['position' => 'bottom', 'priority' => 250]
            );
        }
    }

    public function hookDisplayBackOfficeHeader(array $params): void
    {
        unset($params);

        if ((string) Tools::getValue('configure') !== $this->name) {
            return;
        }

        if (!isset($this->context->controller)) {
            return;
        }

        $this->context->controller->addJS($this->_path . 'views/js/admin.js');
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
        $decision = $this->evaluateClientPayload($payload);

        return $this->insertEvent(
            'client_collect',
            $this->fingerprintPayload($payload),
            $this->networkFingerprint(),
            $decision['risk'],
            'observe',
            $decision['reasons'],
            [
                'page_type' => isset($payload['pageType']) && is_scalar($payload['pageType']) ? (string) $payload['pageType'] : 'page',
                'attribution' => $this->extractAttribution($payload),
                'click_ids' => $this->extractClickIdentifiers($payload),
            ]
        );
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
        $secret = $this->getConfig(self::CFG_SECRET) ?: _COOKIE_KEY_;

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
                $this->switchField(self::CFG_ENABLED, $this->trans('Enable module', [], self::DOMAIN_ADMIN), $this->trans('Enables signal collection and risk evaluation for the current shop context.', [], self::DOMAIN_ADMIN)),
                ['type' => 'select', 'label' => $this->trans('Operating mode', [], self::DOMAIN_ADMIN), 'name' => self::CFG_MODE, 'desc' => $this->trans('Observe records decisions only. Enable blocking after reviewing logs.', [], self::DOMAIN_ADMIN), 'options' => ['query' => [['id' => 'observe', 'name' => $this->trans('Observe only', [], self::DOMAIN_ADMIN)], ['id' => 'rate_limit', 'name' => $this->trans('Rate limit', [], self::DOMAIN_ADMIN)], ['id' => 'block', 'name' => $this->trans('Block high-risk traffic', [], self::DOMAIN_ADMIN)]], 'id' => 'id', 'name' => 'name']],
                $this->textField(self::CFG_RETENTION_DAYS, $this->trans('Log retention days', [], self::DOMAIN_ADMIN), $this->trans('Number of days to keep detailed risk events before cleanup.', [], self::DOMAIN_ADMIN), '30'),
                ['type' => 'select', 'label' => $this->trans('Admin table refresh interval', [], self::DOMAIN_ADMIN), 'name' => self::CFG_ADMIN_REFRESH_INTERVAL, 'desc' => $this->trans('Controls the Back Office risk events table refresh countdown.', [], self::DOMAIN_ADMIN), 'options' => ['query' => $this->adminRefreshIntervalOptions(), 'id' => 'id', 'name' => 'name']],
                $this->switchField(self::CFG_PURGE_DATA_ON_UNINSTALL, $this->trans('Delete stored fraud data on uninstall', [], self::DOMAIN_ADMIN), $this->trans('When enabled, uninstalling the module permanently removes fraud events and rate-limit counters. Keep disabled if you want to reinstall or audit historical events later.', [], self::DOMAIN_ADMIN)),
                $this->switchField(self::CFG_ENABLE_JA4, $this->trans('Enable JA4 correlation', [], self::DOMAIN_ADMIN), $this->trans('Correlates browser fingerprints with TLS/network fingerprints received from a trusted edge proxy.', [], self::DOMAIN_ADMIN)),
                $this->textareaField(self::CFG_TRUSTED_PROXIES, $this->trans('Trusted proxy IP addresses', [], self::DOMAIN_ADMIN), $this->trans('Use one proxy IP address per line. Add an optional note after a hash sign, for example: 203.0.113.10 # Cloudflare edge node.', [], self::DOMAIN_ADMIN), "203.0.113.10 # Cloudflare edge\n198.51.100.10 # HAProxy node 1"),
                $this->textField(self::CFG_JA4_HEADER, $this->trans('JA4 header name', [], self::DOMAIN_ADMIN), $this->trans('Internal header set by your CDN, WAF, HAProxy, NGINX or edge worker.', [], self::DOMAIN_ADMIN), 'X-AdvCF-JA4'),
                $this->textField(self::CFG_JA4H_HEADER, $this->trans('JA4H header name', [], self::DOMAIN_ADMIN), $this->trans('Optional internal HTTP fingerprint header set only by trusted infrastructure.', [], self::DOMAIN_ADMIN), 'X-AdvCF-JA4H'),
                $this->switchField(self::CFG_ENABLE_SCRAPING, $this->trans('Enable anti-scraping scoring', [], self::DOMAIN_ADMIN), $this->trans('Evaluates catalog, search and API request patterns for scraping abuse.', [], self::DOMAIN_ADMIN)),
                $this->textField(self::CFG_PRODUCT_THRESHOLD, $this->trans('Product page threshold', [], self::DOMAIN_ADMIN), $this->trans('Maximum product-like page requests per fingerprint window before risk increases.', [], self::DOMAIN_ADMIN), '120'),
                $this->textField(self::CFG_SEARCH_THRESHOLD, $this->trans('Search threshold', [], self::DOMAIN_ADMIN), $this->trans('Maximum search-like requests per fingerprint window before risk increases.', [], self::DOMAIN_ADMIN), '50'),
                $this->switchField(self::CFG_ENABLE_CLICK_FRAUD, $this->trans('Enable ad click fraud scoring', [], self::DOMAIN_ADMIN), $this->trans('Tracks advertising click identifiers and post-click behavior to flag suspicious paid traffic.', [], self::DOMAIN_ADMIN)),
                $this->textField(self::CFG_BLOCK_MINUTES, $this->trans('Temporary block duration in minutes', [], self::DOMAIN_ADMIN), $this->trans('Used only when block mode is enabled and the risk score reaches the blocking threshold.', [], self::DOMAIN_ADMIN), '15'),
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

        foreach ($this->submittedConfigurationValues($mode) as $key => $value) {
            $this->setConfig($key, $value);
        }

        return $this->displayConfirmation($this->trans('Settings updated.', [], self::DOMAIN_ADMIN));
    }

    private function submittedConfigurationValues(string $mode): array
    {
        return [
            self::CFG_ENABLED => (string) (int) Tools::getValue(self::CFG_ENABLED),
            self::CFG_MODE => $mode,
            self::CFG_RETENTION_DAYS => (string) max(1, (int) Tools::getValue(self::CFG_RETENTION_DAYS, 30)),
            self::CFG_ADMIN_REFRESH_INTERVAL => (string) $this->sanitizedAdminRefreshInterval(),
            self::CFG_PURGE_DATA_ON_UNINSTALL => (string) (int) Tools::getValue(self::CFG_PURGE_DATA_ON_UNINSTALL),
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
    }

    private function configurationValues(): array
    {
        $values = [];
        foreach ([self::CFG_ENABLED, self::CFG_MODE, self::CFG_RETENTION_DAYS, self::CFG_ADMIN_REFRESH_INTERVAL, self::CFG_PURGE_DATA_ON_UNINSTALL, self::CFG_ENABLE_JA4, self::CFG_TRUSTED_PROXIES, self::CFG_JA4_HEADER, self::CFG_JA4H_HEADER, self::CFG_ENABLE_SCRAPING, self::CFG_PRODUCT_THRESHOLD, self::CFG_SEARCH_THRESHOLD, self::CFG_ENABLE_CLICK_FRAUD, self::CFG_BLOCK_MINUTES] as $key) {
            $values[$key] = $this->getConfig($key);
        }

        return $values;
    }

    private function adminRefreshIntervalOptions(): array
    {
        return [
            ['id' => 0, 'name' => $this->trans('Disabled', [], self::DOMAIN_ADMIN)],
            ['id' => 15, 'name' => '15s'],
            ['id' => 30, 'name' => '30s'],
            ['id' => 60, 'name' => '60s'],
            ['id' => 120, 'name' => '120s'],
        ];
    }

    private function sanitizedAdminRefreshInterval(): int
    {
        $interval = (int) Tools::getValue(self::CFG_ADMIN_REFRESH_INTERVAL, 0);

        return in_array($interval, self::ADMIN_REFRESH_INTERVALS, true) ? $interval : 0;
    }

    private function switchField(string $name, string $label, string $description): array
    {
        return ['type' => 'switch', 'label' => $label, 'name' => $name, 'desc' => $description, 'values' => [['id' => $name . '_on', 'value' => 1, 'label' => $this->trans('Yes', [], self::DOMAIN_ADMIN)], ['id' => $name . '_off', 'value' => 0, 'label' => $this->trans('No', [], self::DOMAIN_ADMIN)]]];
    }

    private function textField(string $name, string $label, string $description, string $placeholder): array
    {
        return ['type' => 'text', 'label' => $label, 'name' => $name, 'desc' => $description, 'placeholder' => $placeholder];
    }

    private function textareaField(string $name, string $label, string $description, string $placeholder): array
    {
        return ['type' => 'textarea', 'label' => $label, 'name' => $name, 'desc' => $description, 'placeholder' => $placeholder, 'cols' => 70, 'rows' => 5];
    }

    private function installConfiguration(): bool
    {
        $defaults = [
            self::CFG_ENABLED => '0', self::CFG_MODE => 'observe', self::CFG_SECRET => bin2hex(random_bytes(32)), self::CFG_RETENTION_DAYS => '30', self::CFG_ADMIN_REFRESH_INTERVAL => '0', self::CFG_PURGE_DATA_ON_UNINSTALL => '0',
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
        foreach ([self::CFG_ENABLED, self::CFG_MODE, self::CFG_SECRET, self::CFG_RETENTION_DAYS, self::CFG_ADMIN_REFRESH_INTERVAL, self::CFG_PURGE_DATA_ON_UNINSTALL, self::CFG_ENABLE_SCRAPING, self::CFG_ENABLE_CLICK_FRAUD, self::CFG_ENABLE_JA4, self::CFG_TRUSTED_PROXIES, self::CFG_JA4_HEADER, self::CFG_JA4H_HEADER, self::CFG_PRODUCT_THRESHOLD, self::CFG_SEARCH_THRESHOLD, self::CFG_BLOCK_MINUTES] as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    private function installDatabase(): bool
    {
        $tables = [
            _DB_PREFIX_ . 'advclickfraud_event' => 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'advclickfraud_event` (`id_advclickfraud_event` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_shop` INT UNSIGNED NOT NULL DEFAULT 0, `event_type` VARCHAR(64) NOT NULL, `request_id` VARCHAR(64) NOT NULL, `client_fingerprint` CHAR(64) NULL, `network_fingerprint` CHAR(64) NULL, `risk_score` TINYINT UNSIGNED NOT NULL DEFAULT 0, `decision` VARCHAR(32) NOT NULL DEFAULT "observe", `reason_codes` TEXT NULL, `payload` TEXT NULL, `ip_hash` CHAR(64) NULL, `user_agent_hash` CHAR(64) NULL, `date_add` DATETIME NOT NULL, PRIMARY KEY (`id_advclickfraud_event`), KEY `idx_shop_date` (`id_shop`, `date_add`), KEY `idx_risk_date` (`risk_score`, `date_add`), KEY `idx_event_type_date` (`event_type`, `date_add`), KEY `idx_client` (`client_fingerprint`), KEY `idx_network` (`network_fingerprint`)) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;',
            _DB_PREFIX_ . 'advclickfraud_rate_limit' => 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'advclickfraud_rate_limit` (`id_advclickfraud_rate_limit` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `id_shop` INT UNSIGNED NOT NULL DEFAULT 0, `scope_hash` CHAR(64) NOT NULL, `route_type` VARCHAR(32) NOT NULL, `hits` INT UNSIGNED NOT NULL DEFAULT 0, `window_start` DATETIME NOT NULL, `date_upd` DATETIME NOT NULL, PRIMARY KEY (`id_advclickfraud_rate_limit`), UNIQUE KEY `uniq_scope_route_window` (`id_shop`, `scope_hash`, `route_type`, `window_start`), KEY `idx_window` (`window_start`), KEY `idx_date_upd` (`date_upd`)) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;',
        ];

        $createdTables = [];

        try {
            foreach ($tables as $tableName => $sql) {
                $existedBefore = $this->tableExists($tableName);
                $this->executeInstallSqlOrFail($tableName, $sql);

                if (!$existedBefore && $this->tableExists($tableName)) {
                    $createdTables[] = $tableName;
                }
            }

            return true;
        } catch (Throwable $exception) {
            $this->rollbackInstallDatabase($createdTables);
            $this->_errors[] = $this->trans('Database installation failed. Temporary tables created during this installation attempt were rolled back.', [], self::DOMAIN_ADMIN);
            $this->_errors[] = $exception->getMessage();

            return false;
        }
    }

    private function uninstallDatabase(): bool
    {
        foreach ($this->databaseTableNames() as $tableName) {
            if (!Db::getInstance()->execute('DROP TABLE IF EXISTS ' . $this->quoteTableName($tableName))) {
                return false;
            }
        }

        return true;
    }

    private function databaseTableNames(): array
    {
        return [
            _DB_PREFIX_ . 'advclickfraud_event',
            _DB_PREFIX_ . 'advclickfraud_rate_limit',
        ];
    }

    private function tableExists(string $tableName): bool
    {
        return (bool) Db::getInstance()->getValue('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "' . pSQL($tableName) . '"');
    }

    private function executeInstallSqlOrFail(string $step, string $sql): void
    {
        if (Db::getInstance()->execute($sql)) {
            return;
        }

        throw new RuntimeException('Failed SQL step: ' . $step . '. Database error: ' . $this->databaseErrorMessage());
    }

    private function rollbackInstallDatabase(array $createdTables): void
    {
        foreach (array_reverse($createdTables) as $tableName) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS ' . $this->quoteTableName((string) $tableName));
        }
    }

    private function quoteTableName(string $tableName): string
    {
        return '`' . str_replace('`', '', $tableName) . '`';
    }

    private function databaseErrorMessage(): string
    {
        $db = Db::getInstance();

        if (method_exists($db, 'getMsgError')) {
            $message = (string) $db->getMsgError();
            if ($message !== '') {
                return $message;
            }
        }

        return 'No database error message was returned.';
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

        $riskScore = min(100, $score);
        $action = 'observe';
        $mode = $this->getConfig(self::CFG_MODE);
        if (($mode === 'rate_limit' && $riskScore >= 70) || ($mode === 'block' && $riskScore >= 85)) {
            $action = 'block';
        }

        if ($riskScore > 0 || $action !== 'observe') {
            $this->insertEvent('server_request', null, $this->networkFingerprint(), $riskScore, $action, $reasons, ['route' => $route]);
        }

        return ['action' => $action, 'risk' => $riskScore, 'reasons' => $reasons];
    }

    private function evaluateClientPayload(array $payload): array
    {
        $score = 0;
        $reasons = [];
        $signals = isset($payload['signals']) && is_array($payload['signals']) ? $payload['signals'] : [];
        $behavior = isset($payload['behavior']) && is_array($payload['behavior']) ? $payload['behavior'] : [];
        $attribution = $this->extractAttribution($payload);

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
        if (!empty($attribution['is_paid_click']) && empty($behavior['hadInteraction'])) {
            $score += 15;
            $reasons[] = 'paid_click_without_observed_interaction';
        }

        return ['risk' => min(100, $score), 'reasons' => $reasons];
    }

    private function insertEvent(string $type, ?string $clientFingerprint, ?string $networkFingerprint, int $risk, string $decision, array $reasons, array $payload): bool
    {
        $now = date('Y-m-d H:i:s');

        return Db::getInstance()->insert('advclickfraud_event', [
            'id_shop' => (int) $this->context->shop->id,
            'event_type' => pSQL(substr($type, 0, 64)),
            'request_id' => pSQL(substr($this->getRequestId(), 0, 64)),
            'client_fingerprint' => $clientFingerprint !== null ? pSQL(substr($clientFingerprint, 0, 64)) : null,
            'network_fingerprint' => $networkFingerprint !== null ? pSQL(substr($networkFingerprint, 0, 64)) : null,
            'risk_score' => max(0, min(100, $risk)),
            'decision' => pSQL(substr($decision, 0, 32)),
            'reason_codes' => pSQL($this->safeJson($reasons), true),
            'payload' => pSQL($this->safeJson($payload), true),
            'ip_hash' => pSQL($this->hmac($this->ipPrefix())),
            'user_agent_hash' => pSQL($this->hmac((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))),
            'date_add' => pSQL($now),
        ]);
    }

    private function incrementRateLimit(string $scopeHash, string $route): int
    {
        $now = date('Y-m-d H:i:s');
        $windowStart = date('Y-m-d H:i:00', (int) floor(time() / 300) * 300);
        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'advclickfraud_rate_limit` (`id_shop`, `scope_hash`, `route_type`, `hits`, `window_start`, `date_upd`) VALUES (' . (int) $this->context->shop->id . ', "' . pSQL($scopeHash) . '", "' . pSQL(substr($route, 0, 32)) . '", 1, "' . pSQL($windowStart) . '", "' . pSQL($now) . '") ON DUPLICATE KEY UPDATE `hits` = `hits` + 1, `date_upd` = "' . pSQL($now) . '"';
        Db::getInstance()->execute($sql);

        return (int) Db::getInstance()->getValue('SELECT `hits` FROM `' . _DB_PREFIX_ . 'advclickfraud_rate_limit` WHERE `id_shop` = ' . (int) $this->context->shop->id . ' AND `scope_hash` = "' . pSQL($scopeHash) . '" AND `route_type` = "' . pSQL(substr($route, 0, 32)) . '" AND `window_start` = "' . pSQL($windowStart) . '"');
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
        foreach (preg_split('/\R+/', $this->getConfig(self::CFG_TRUSTED_PROXIES)) ?: [] as $line) {
            $trustedAddress = $this->trustedProxyAddressFromLine((string) $line);
            if ($trustedAddress !== '' && hash_equals($trustedAddress, $remoteAddress)) {
                return true;
            }
        }

        return false;
    }

    private function trustedProxyAddressFromLine(string $line): string
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            return '';
        }

        $address = trim(explode('#', $line, 2)[0]);

        return filter_var($address, FILTER_VALIDATE_IP) ? $address : '';
    }

    private function extractAttribution(array $payload): array
    {
        $attribution = isset($payload['attribution']) && is_array($payload['attribution']) ? $payload['attribution'] : [];
        $query = isset($payload['query']) && is_array($payload['query']) ? $payload['query'] : [];
        $channel = isset($attribution['channel']) && is_scalar($attribution['channel']) ? (string) $attribution['channel'] : $this->classifyChannelFromQuery($query);

        return [
            'is_paid_click' => !empty($attribution['isPaidClick']) || $this->hasPaidClickInQuery($query),
            'channel' => substr($channel, 0, 64),
            'click_id_type' => isset($attribution['clickIdType']) && is_scalar($attribution['clickIdType']) ? substr((string) $attribution['clickIdType'], 0, 32) : '',
            'campaign' => isset($attribution['campaign']) && is_scalar($attribution['campaign']) ? substr((string) $attribution['campaign'], 0, 128) : '',
            'medium' => isset($attribution['medium']) && is_scalar($attribution['medium']) ? substr((string) $attribution['medium'], 0, 64) : '',
            'content' => isset($attribution['content']) && is_scalar($attribution['content']) ? substr((string) $attribution['content'], 0, 128) : '',
            'term' => isset($attribution['term']) && is_scalar($attribution['term']) ? substr((string) $attribution['term'], 0, 128) : '',
        ];
    }

    private function extractClickIdentifiers(array $payload): array
    {
        $result = [];
        $query = isset($payload['query']) && is_array($payload['query']) ? $payload['query'] : [];
        foreach (['gclid', 'wbraid', 'gbraid', 'fbclid', 'ttclid', 'msclkid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $key) {
            if (isset($query[$key]) && is_scalar($query[$key])) {
                $result[$key] = substr((string) $query[$key], 0, 255);
            }
        }

        return $result;
    }

    private function classifyChannelFromQuery(array $query): string
    {
        if (!empty($query['gclid']) || !empty($query['wbraid']) || !empty($query['gbraid'])) {
            return 'google_ads';
        }
        if (!empty($query['fbclid'])) {
            return 'meta_ads';
        }
        if (!empty($query['ttclid'])) {
            return 'tiktok_ads';
        }
        if (!empty($query['msclkid'])) {
            return 'microsoft_ads';
        }
        if (!empty($query['utm_source']) && is_scalar($query['utm_source'])) {
            return substr((string) $query['utm_source'], 0, 64);
        }

        return 'organic_or_direct';
    }

    private function hasPaidClickInQuery(array $query): bool
    {
        foreach (['gclid', 'wbraid', 'gbraid', 'fbclid', 'ttclid', 'msclkid', 'utm_source'] as $key) {
            if (!empty($query[$key])) {
                return true;
            }
        }

        return false;
    }

    private function dashboardStats(): array
    {
        $table = _DB_PREFIX_ . 'advclickfraud_event';
        $shopId = (int) $this->context->shop->id;

        return [
            'total_events' => (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . pSQL($table) . '` WHERE `id_shop` = ' . $shopId),
            'high_risk_events' => (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . pSQL($table) . '` WHERE `id_shop` = ' . $shopId . ' AND `risk_score` >= 70'),
            'client_fingerprints' => (int) Db::getInstance()->getValue('SELECT COUNT(DISTINCT `client_fingerprint`) FROM `' . pSQL($table) . '` WHERE `id_shop` = ' . $shopId . ' AND `client_fingerprint` IS NOT NULL'),
            'network_fingerprints' => (int) Db::getInstance()->getValue('SELECT COUNT(DISTINCT `network_fingerprint`) FROM `' . pSQL($table) . '` WHERE `id_shop` = ' . $shopId . ' AND `network_fingerprint` IS NOT NULL'),
        ];
    }

    private function recentEvents(): array
    {
        $rows = Db::getInstance()->executeS('SELECT `event_type`, `risk_score`, `decision`, `reason_codes`, `payload`, `date_add` FROM `' . _DB_PREFIX_ . 'advclickfraud_event` WHERE `id_shop` = ' . (int) $this->context->shop->id . ' ORDER BY `date_add` DESC LIMIT 10');
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $payload = $this->safeJsonDecode((string) ($row['payload'] ?? ''));
            $reasons = $this->safeJsonDecode((string) ($row['reason_codes'] ?? ''));

            $row['event_type'] = substr((string) ($row['event_type'] ?? ''), 0, 64);
            $row['decision'] = substr((string) ($row['decision'] ?? ''), 0, 32);
            $row['channel'] = $this->nestedScalar($payload, ['attribution', 'channel'], 64);
            $row['campaign'] = $this->nestedScalar($payload, ['attribution', 'campaign'], 128);
            $row['reason_list'] = $this->reasonList($reasons);
        }
        unset($row);

        return $rows;
    }

    private function safeJson(array $data, int $maxBytes = 60000): string
    {
        try {
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            unset($exception);

            return '[]';
        }

        if (strlen($json) <= $maxBytes) {
            return $json;
        }

        try {
            return json_encode([
                'truncated' => true,
                'sha256' => hash('sha256', $json),
                'original_bytes' => strlen($json),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            unset($exception);

            return '{"truncated":true}';
        }
    }

    private function safeJsonDecode(string $json): array
    {
        if ($json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            unset($exception);

            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function nestedScalar(array $source, array $path, int $maxLength): string
    {
        $value = $source;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return '';
            }
            $value = $value[$key];
        }

        return is_scalar($value) ? substr((string) $value, 0, $maxLength) : '';
    }

    private function reasonList(array $reasons): string
    {
        $cleanReasons = [];
        foreach ($reasons as $reason) {
            if (is_scalar($reason)) {
                $cleanReasons[] = substr((string) $reason, 0, 96);
            }
        }

        return substr(implode(', ', $cleanReasons), 0, 512);
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
        foreach (['gclid', 'wbraid', 'gbraid', 'fbclid', 'ttclid', 'msclkid'] as $key) {
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
