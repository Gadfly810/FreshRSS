<?php
require_once('lib/Logger.php');
require_once('lib/TextUtil.php');
require_once('lib/TranslateController.php');
require_once('lib/TranslationService.php');
require_once('lib/SummarizationService.php');
require_once('lib/providers/ProviderFactory.php');

class TranslateDigestExtension extends Minz_Extension {

    /** 需要验证的服务：服务键 => [显示名, 是否需要 endpoint, 是否需要 model] */
    private static $providersToValidate = [
        'deepseek' => ['DeepSeek', false, true],
        'gemini'   => ['Gemini', true, true],
        'deepl'    => ['DeepL', true, false],
        'openai'   => ['OpenAI 兼容', true, true],
    ];

    public function init() {
        Logger::info('Plugin initializing...');

        if (!extension_loaded('mbstring')) {
            Logger::error('Plugin requires PHP mbstring extension');
        }

        // 处理 CLI 模式下的用户上下文
        if (php_sapi_name() === 'cli' && !FreshRSS_Context::$user_conf) {
            $this->initializeCliContext();
        }

        $this->registerHook('entry_before_insert', [$this, 'handleEntry']);

        $defaultService = strtoupper(FreshRSS_Context::$user_conf->TranslateDefaultService ?? 'google');
        $targetLang = FreshRSS_Context::$user_conf->TranslateTargetLang ?? 'zh';
        Logger::info('Plugin configuration - Service: ' . $defaultService . ' | Target Language: ' . $targetLang);
    }

    /**
     * 初始化 CLI 模式下的用户上下文
     */
    private function initializeCliContext() {
        $usernames = $this->listUsers();
        if (!empty($usernames)) {
            $username = $usernames[0];
            FreshRSS_Context::$user_conf = new FreshRSS_UserConfiguration($username);
            FreshRSS_Context::$user_conf->load();
            Logger::debug("CLI mode: initialized context for user '$username'");
        }
    }

    public function handleConfigureAction() {
        if (Minz_Request::param('_action') === 'list_models') {
            $this->handleListModelsAction();
            return;
        }

        if (Minz_Request::isPost()) {
            $this->saveConfiguration();
            return;
        }

        Logger::debug('Configure action: GET request (loading page)');
    }

    /**
     * 保存配置表单
     */
    private function saveConfiguration() {
        Logger::info('Configure action: POST request received');

        $translateService = Minz_Request::param('TranslateDefaultService', 'google');
        $translateTitles = Minz_Request::param('TranslateTitles', []);
        $summarizeContents = Minz_Request::param('SummarizeContents', []);
        $translateContents = Minz_Request::param('TranslateContents', []);
        $translateSkipIfSame = Minz_Request::param('TranslateSkipIfSame', 0) ? '1' : '0';
        $targetLang = Minz_Request::param('TranslateTargetLang', 'zh');
        $tokenMaxChars = Minz_Request::param('TokenMaxChars', '4000');

        $keys = [
            'deepseek' => Minz_Request::param('DeepSeekApiKey', ''),
            'gemini'   => Minz_Request::param('GeminiApiKey', ''),
            'deepl'    => Minz_Request::param('DeepLApiKey', ''),
            'openai'   => Minz_Request::param('OpenAIApiKey', ''),
        ];
        $endpoints = [
            'deepseek' => '',
            'gemini'   => trim(Minz_Request::param('GeminiEndpoint', '')),
            'deepl'    => trim(Minz_Request::param('DeepLEndpoint', '')),
            'openai'   => trim(Minz_Request::param('OpenAIEndpoint', '')),
        ];
        $models = [
            'deepseek' => trim(Minz_Request::param('DeepSeekModel', '')),
            'gemini'   => trim(Minz_Request::param('GeminiModel', '')),
            'deepl'    => '',
            'openai'   => trim(Minz_Request::param('OpenAIModel', '')),
        ];

        Logger::info('POST params - Service: ' . $translateService . ', TargetLang: ' . $targetLang
            . ', TranslateTitles: ' . count($translateTitles) . ', SummarizeContents: ' . count($summarizeContents)
            . ', TranslateContents: ' . count($translateContents));

        // 逐个验证已填写的服务（只记录日志，不阻止保存）
        $validationErrors = [];
        foreach (self::$providersToValidate as $service => $meta) {
            $this->validateProviderConfig(
                $service,
                $meta[0],
                $keys[$service],
                $meta[1] ? $endpoints[$service] : '',
                $meta[2] ? $models[$service] : '',
                $validationErrors
            );
        }
        $this->view['validationErrors'] = $validationErrors;

        $conf = FreshRSS_Context::$user_conf;
        $conf->TranslateDefaultService = $translateService;
        $conf->TranslateTitles = json_encode($translateTitles);
        $conf->SummarizeContents = json_encode($summarizeContents);
        $conf->TranslateContents = json_encode($translateContents);
        $conf->TranslateSkipIfSame = $translateSkipIfSame;
        $conf->TranslateTargetLang = $targetLang;
        $conf->TokenMaxChars = $tokenMaxChars;
        $conf->DeepSeekApiKey = $keys['deepseek'];
        $conf->DeepSeekModel = $models['deepseek'];
        $conf->GeminiApiKey = $keys['gemini'];
        $conf->GeminiModel = $models['gemini'];
        $conf->GeminiEndpoint = $endpoints['gemini'];
        $conf->DeepLApiKey = $keys['deepl'];
        $conf->DeepLEndpoint = $endpoints['deepl'];
        $conf->OpenAIApiKey = $keys['openai'];
        $conf->OpenAIEndpoint = $endpoints['openai'];
        $conf->OpenAIModel = $models['openai'];

        Logger::info('Keys saved - DeepSeek: ' . $this->describeKey($keys['deepseek']));
        Logger::info('Keys saved - Gemini: ' . $this->describeKey($keys['gemini']) . ' | Endpoint: ' . ($endpoints['gemini'] === '' ? 'DEFAULT' : $endpoints['gemini']));
        Logger::info('Keys saved - DeepL: ' . $this->describeKey($keys['deepl']) . ' | Endpoint: ' . ($endpoints['deepl'] === '' ? 'AUTO' : $endpoints['deepl']));
        Logger::info('Keys saved - OpenAI: ' . $this->describeKey($keys['openai']) . ' | Endpoint: ' . ($endpoints['openai'] === '' ? 'DEFAULT' : $endpoints['openai']));

        $saved = $conf->save();
        Logger::info('Config save() called - Result: ' . ($saved ? 'TRUE' : 'FALSE'));

        if ($saved) {
            header('Location: ' . _url('extension', 'configure', 'e', 'TranslateDigest'));
            exit();
        }

        Logger::error('Failed to save configuration - save() returned false');
        $this->view['error'] = 'Configuration save failed';
    }

    /**
     * 验证单个服务的配置，结果写入 $validationErrors
     */
    private function validateProviderConfig($service, $label, $apiKey, $endpoint, $model, array &$validationErrors) {
        if ($apiKey === '' && $endpoint === '') {
            Logger::info($label . ' validation: SKIPPED (empty)');
            return;
        }

        try {
            $provider = ProviderFactory::create($service);
            if ($apiKey !== '' && method_exists($provider, 'setApiKey')) {
                $provider->setApiKey($apiKey);
            }
            if ($endpoint !== '' && method_exists($provider, 'setEndpoint')) {
                $provider->setEndpoint($endpoint);
            }
            if ($model !== '' && method_exists($provider, 'setModel')) {
                $provider->setModel($model);
            }

            $result = $provider->validateKey();
            if (empty($result['ok'])) {
                $message = $label . ' 验证失败: ' . ($result['message'] ?? '未知错误');
                Logger::warning($message);
                $validationErrors[$service] = $message;
                return;
            }
            Logger::info($label . ' validation: ✓ VALID');
        } catch (Throwable $e) {
            $message = $label . ' 验证异常: ' . $e->getMessage();
            Logger::warning($message);
            $validationErrors[$service] = $message;
        }
    }

    /**
     * 生成用于日志的 API Key 描述（掩码显示，避免泄露）
     */
    private function describeKey($apiKey) {
        if ($apiKey === '' || $apiKey === null) {
            return 'EMPTY';
        }
        return 'SET (' . substr($apiKey, 0, 4) . '...' . substr($apiKey, -4) . ')';
    }

    public function handleUninstallAction() {
        // 清除插件配置项（从 FreshRSS_Context 中）
        $configKeys = [
            'TranslateDefaultService', 'TranslateTitles',
            'TranslateTargetLang', 'TranslateSkipIfSame',
            'SummarizeContents', 'TokenMaxChars', 'TranslateContents',
            'DeepSeekApiKey', 'DeepSeekModel',
            'GeminiApiKey', 'GeminiModel', 'GeminiEndpoint', 'DeepLApiKey', 'DeepLEndpoint',
            'OpenAIApiKey', 'OpenAIEndpoint', 'OpenAIModel',
            'TranslateTitlesCN_TokenStats'
        ];

        foreach ($configKeys as $key) {
            if (isset(FreshRSS_Context::$user_conf->$key)) {
                unset(FreshRSS_Context::$user_conf->$key);
            }
        }

        FreshRSS_Context::$user_conf->save();
        Logger::info('Configuration cleared on uninstall');
    }

    public function handleEntry($entry) {
        try {
            $controller = new TranslateController();
            return $controller->processEntry($entry);
        } catch (Throwable $e) {
            Logger::exception($e, 'Entry processing');
            return $entry;
        }
    }

    /**
     * 获取所有用户列表
     */
    private function listUsers(): array {
        $users = [];
        $path = DATA_PATH . '/users';

        if (!is_dir($path)) {
            Logger::error("Users directory not found: $path");
            return $users;
        }

        $handle = opendir($path);
        if ($handle === false) {
            Logger::error("Failed to open users directory: $path");
            return $users;
        }

        while (($entry = readdir($handle)) !== false) {
            if ($entry !== '.' && $entry !== '..' && is_dir($path . '/' . $entry)) {
                $users[] = $entry;
            }
        }
        closedir($handle);

        return $users;
    }

    /**
     * 获取指定服务可用的模型列表（供配置页下拉框使用）
     * GET 参数：service, api-key, endpoint, model
     */
    public function handleListModelsAction() {
        header('Content-Type: application/json; charset=utf-8');

        $service = Minz_Request::param('service', '');
        $apiKey = Minz_Request::param('api-key', '');
        $endpoint = trim(Minz_Request::param('endpoint', ''));
        $model = trim(Minz_Request::param('model', ''));

        if ($service === '') {
            echo json_encode(['success' => false, 'models' => [], 'message' => '缺少 service 参数'], JSON_UNESCAPED_UNICODE);
            return;
        }

        Logger::info('ListModelsAction received: service=' . $service . ', apiKey=' . $this->describeKey($apiKey));

        try {
            $provider = ProviderFactory::create($service);
            if ($apiKey !== '' && method_exists($provider, 'setApiKey')) {
                $provider->setApiKey($apiKey);
            }
            if ($endpoint !== '' && method_exists($provider, 'setEndpoint')) {
                $provider->setEndpoint($endpoint);
            }
            if ($model !== '' && method_exists($provider, 'setModel')) {
                $provider->setModel($model);
            }

            $result = $provider->listModels();
            $ok = !empty($result['ok']);
            $models = $result['models'] ?? [];
            $message = $result['message'] ?? ($ok ? ('共 ' . count($models) . ' 个模型') : '获取模型列表失败');

            Logger::info('ListModelsAction result: ok=' . ($ok ? 'true' : 'false') . ', count=' . count($models));

            echo json_encode([
                'success' => $ok,
                'models' => $models,
                'message' => $message
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            Logger::exception($e, 'List models action');
            echo json_encode(['success' => false, 'models' => [], 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}
