<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate\Client;

use AiDev\ModuleUpdate\Manifest;
use AiDev\ModuleUpdate\ModuleUpdateException;
use AiDev\ModuleUpdate\Version;
use Closure;
use Throwable;

final class UpdatePanel
{
    private static ?string $requestSignature = null;

    private static ?string $requestHtml = null;

    /**
     * PrestaShop appelle getContent() deux fois pour un même envoi.
     * Le second passage doit revoir la même réponse, sans reconsommer le jeton.
     */
    public static function resetRequestCache(): void
    {
        self::$requestSignature = null;
        self::$requestHtml = null;
    }

    /**
     * @param list<string> $preserve
     */
    public function __construct(
        private readonly UpdateClient $client,
        private readonly Installer $installer,
        private readonly string $modulePath,
        private readonly string $installedVersion,
        private readonly ?Closure $onInstalled = null,
        private readonly array $preserve = ['var', 'config/update.php'],
    ) {
    }

    /**
     * @param array<string, mixed> $post
     */
    public function handle(array $post, string $formAction = ''): string
    {
        $signature = $this->requestSignature($post);
        if (self::$requestSignature === $signature && is_string(self::$requestHtml)) {
            return self::$requestHtml;
        }

        $directory = $this->stateDirectory();
        $lock = fopen($directory . DIRECTORY_SEPARATOR . 'panel.lock', 'c');
        if ($lock === false) {
            return $this->page($this->notice(AdminTranslator::line('Administration lock is unavailable.'), true));
        }

        flock($lock, LOCK_EX);
        try {
            $html = $this->dispatch($post, $this->safeAction($formAction));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        self::$requestSignature = $signature;
        self::$requestHtml = $html;

        return $html;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function requestSignature(array $post): string
    {
        return hash('sha256', implode("\0", [
            $this->modulePath,
            $this->installedVersion,
            $this->field($post, 'mu_action'),
            $this->field($post, 'mu_token'),
            $this->field($post, 'mu_version'),
            $this->field($post, 'mu_confirm'),
        ]));
    }

    /**
     * @param array<string, mixed> $post
     */
    private function dispatch(array $post, string $action): string
    {
        $command = $this->field($post, 'mu_action');
        try {
            if ($command === 'install') {
                return $this->install($post, $action);
            }
            if ($command === 'check') {
                return $this->check($post, $action);
            }
        } catch (ModuleUpdateException $exception) {
            $token = $this->issue('check', '', true);

            return $this->page(
                $this->notice($exception->getMessage(), true)
                . $this->summary()
                . $this->checkForm($action, $token)
            );
        } catch (Throwable) {
            $token = $this->issue('check', '', true);

            return $this->page(
                $this->notice(AdminTranslator::line('The update could not be completed.'), true)
                . $this->summary()
                . $this->checkForm($action, $token)
            );
        }

        return $this->preview($action);
    }

    /**
     * @param array<string, mixed> $post
     */
    private function check(array $post, string $action): string
    {
        $this->consume($this->field($post, 'mu_token'), 'check', '');

        return $this->renderRelease($action, $this->client->check());
    }

    private function preview(string $action): string
    {
        $pending = $this->readCsrf();
        if (is_array($pending) && ($pending['action'] ?? '') === 'install' && Version::isValid((string) ($pending['version'] ?? ''))) {
            return $this->pendingInstall($action, $pending);
        }

        try {
            return $this->renderRelease($action, $this->client->check());
        } catch (ModuleUpdateException $exception) {
            $token = $this->issue('check', '');

            return $this->page(
                $this->notice($exception->getMessage(), true)
                . $this->summary()
                . $this->checkForm($action, $token)
            );
        }
    }

    /**
     * @param array{token: string, expires: int, action: string, version: string} $pending
     */
    private function pendingInstall(string $action, array $pending): string
    {
        $version = $pending['version'];
        try {
            $manifest = $this->client->check();
        } catch (ModuleUpdateException) {
            return $this->page(
                $this->notice(AdminTranslator::line('Version %s is ready to be installed.', $version), false)
                . $this->installForm($action, $pending['token'], $version)
            );
        }
        if ($manifest->version !== $version) {
            return $this->renderRelease($action, $manifest);
        }

        return $this->page(
            $this->notice(AdminTranslator::line('Version %s is available.', $version), false)
            . $this->summary($manifest)
            . $this->release($manifest)
            . $this->installForm($action, $pending['token'], $version)
        );
    }

    private function renderRelease(string $action, Manifest $manifest): string
    {
        if (version_compare($this->installedVersion, $manifest->version, '>=')) {
            return $this->page(
                $this->notice(AdminTranslator::line('The module is up to date (version %s).', $manifest->version), false)
                . $this->summary($manifest)
                . $this->release($manifest)
            );
        }

        $token = $this->issue('install', $manifest->version);

        return $this->page(
            $this->notice(AdminTranslator::line('Version %s is available.', $manifest->version), false)
            . $this->summary($manifest)
            . $this->release($manifest)
            . $this->installForm($action, $token, $manifest->version)
        );
    }

    /**
     * @param array<string, mixed> $post
     */
    private function install(array $post, string $action): string
    {
        $version = $this->field($post, 'mu_version');
        if (!Version::isValid($version) || $this->field($post, 'mu_confirm') !== 'yes') {
            throw new ModuleUpdateException(AdminTranslator::line('Installation confirmation is incomplete.'));
        }
        $this->consume($this->field($post, 'mu_token'), 'install', $version);

        $manifest = $this->client->check();
        if ($manifest->version !== $version) {
            throw new ModuleUpdateException(AdminTranslator::line('The published version has changed. Check again.'));
        }
        if (!version_compare($this->installedVersion, $manifest->version, '<')) {
            throw new ModuleUpdateException(AdminTranslator::line('This version is not newer than the installed version.'));
        }

        $zip = $this->client->download($manifest);
        try {
            $backup = $this->installer->install(
                $zip,
                $this->modulePath,
                $manifest,
                $this->client->publicKey(),
                $this->installedVersion,
                $this->preserve,
            );
        } finally {
            if (is_file($zip)) {
                unlink($zip);
            }
        }

        $extra = '';
        if ($this->onInstalled !== null) {
            try {
                ($this->onInstalled)($manifest);
            } catch (Throwable) {
                $extra = ' ' . AdminTranslator::line('The files are in place, but the CMS could not update the registered version.');
            }
        }

        $token = $this->issue('check', '');

        return $this->page(
            $this->notice(AdminTranslator::line('Version %s installed. Backup: %s. Reload the administration page.', $manifest->version, $backup) . $extra, false)
            . $this->release($manifest)
            . $this->checkForm($action, $token)
        );
    }

    private function summary(?Manifest $manifest = null): string
    {
        $remote = $manifest === null ? AdminTranslator::line('Not checked') : $manifest->version;

        return '<p>' . $this->e(AdminTranslator::line('Installed version:')) . ' <strong>' . $this->e($this->installedVersion) . '</strong><br>'
            . $this->e(AdminTranslator::line('Published version:')) . ' <strong>' . $this->e($remote) . '</strong></p>';
    }

    private function release(Manifest $manifest): string
    {
        $changelog = $manifest->changelog === '' ? AdminTranslator::line('No changelog.') : $manifest->changelog;

        return '<p>' . $this->e(AdminTranslator::line('Published on')) . ' ' . $this->e($manifest->releasedAt) . '<br>'
            . $this->e(AdminTranslator::line('SHA-256 fingerprint:')) . ' <code>' . $this->e($manifest->sha256) . '</code><br>'
            . $this->e(AdminTranslator::line('Package:')) . ' <code>' . $this->e($manifest->filename) . '</code></p>'
            . '<h3>' . $this->e(AdminTranslator::line('Changelog')) . '</h3><pre class="mu-changelog">' . $this->e($changelog) . '</pre>';
    }

    private function checkForm(string $action, string $token): string
    {
        return '<form method="post" action="' . $this->e($action) . '" autocomplete="off">'
            . '<input type="hidden" name="mu_token" value="' . $this->e($token) . '">'
            . '<button type="submit" name="mu_action" value="check">' . $this->e(AdminTranslator::line('Check for updates')) . '</button>'
            . '</form>';
    }

    private function installForm(string $action, string $token, string $version): string
    {
        return '<form method="post" action="' . $this->e($action) . '" autocomplete="off">'
            . '<input type="hidden" name="mu_token" value="' . $this->e($token) . '">'
            . '<input type="hidden" name="mu_version" value="' . $this->e($version) . '">'
            . '<p>' . $this->e(AdminTranslator::line('The module files will be replaced after a backup in var/backups. The existing config/update.php file and the var directory are kept.')) . '</p>'
            . '<label><input type="checkbox" name="mu_confirm" value="yes"> '
            . $this->e(AdminTranslator::line('I confirm replacing the files with version %s', $version)) . '</label><br>'
            . '<button type="submit" name="mu_action" value="install">' . $this->e(AdminTranslator::line('Install version %s', $version)) . '</button>'
            . '</form>';
    }

    private function notice(string $message, bool $error): string
    {
        return '<p class="' . ($error ? 'mu-error' : 'mu-ok') . '">' . $this->e($message) . '</p>';
    }

    private function page(string $body): string
    {
        return '<section class="mu-update">' . $body . '</section>'
            . '<style>.mu-update{max-width:860px;margin:1rem 0;padding:1rem 1.25rem;border:1px solid #d6d6d6;background:#fff}'
            . '.mu-update h2,.mu-update h3{margin:0 0 .75rem}.mu-changelog{white-space:pre-wrap;background:#f7f7f7;padding:.75rem;border:1px solid #e5e5e5}'
            . '.mu-error{color:#8a1f11;background:#fdecea;padding:.5rem .75rem}.mu-ok{color:#146c43;background:#e8f6ee;padding:.5rem .75rem}'
            . '.mu-update button{margin-top:.75rem}'
			. '.mu-update h3 {margin-top: 20px !important}</style>';
    }

    private function issue(string $action, string $version, bool $force = false): string
    {
        if (!$force && $action === 'check' && $version === '') {
            $existing = $this->currentToken('check', '');
            if ($existing !== null) {
                return $existing;
            }
            if ($this->pendingInstallToken() !== null) {
                return bin2hex(random_bytes(32));
            }
        }

        $token = bin2hex(random_bytes(32));
        $payload = [
            'token' => $token,
            'expires' => time() + 1200,
            'action' => $action,
            'version' => $version,
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        if (file_put_contents($this->csrfPath(), $json, LOCK_EX) === false) {
            throw new ModuleUpdateException(AdminTranslator::line('Confirmation could not be prepared.'));
        }
        if ($action === 'install') {
            $this->remember($payload);
        }
        if ($force) {
            $this->forgetConfirmation();
        }

        return $token;
    }

    /**
     * @return array{token: string, expires: int, action: string, version: string}|null
     */
    private function readCsrf(): ?array
    {
        $path = $this->csrfPath();
        $raw = is_file($path) ? file_get_contents($path) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !is_string($data['token'] ?? null) || $data['token'] === '') {
            return null;
        }
        if ((int) ($data['expires'] ?? 0) < time()) {
            return null;
        }

        return [
            'token' => $data['token'],
            'expires' => (int) $data['expires'],
            'action' => (string) ($data['action'] ?? ''),
            'version' => (string) ($data['version'] ?? ''),
        ];
    }

    private function currentToken(string $action, string $version): ?string
    {
        $data = $this->readCsrf();
        if ($data === null || $data['action'] !== $action || $data['version'] !== $version) {
            return null;
        }

        return $data['token'];
    }

    private function pendingInstallToken(): ?string
    {
        $data = $this->readCsrf();
        if ($data === null || $data['action'] !== 'install' || !Version::isValid($data['version'])) {
            return null;
        }

        return $data['token'];
    }

    private function consume(string $token, string $action, string $version): void
    {
        if (!$this->matches($this->readCsrf(), $token, $action, $version) && !$this->matches($this->readConfirmation(), $token, $action, $version)) {
            throw new ModuleUpdateException(AdminTranslator::line('The confirmation has expired or is invalid. Reload the page.'));
        }

        $path = $this->csrfPath();
        if (is_file($path)) {
            unlink($path);
        }
        $this->forgetConfirmation();
    }

    /**
     * @param array{token: string, expires: int, action: string, version: string}|null $data
     */
    private function matches(?array $data, string $token, string $action, string $version): bool
    {
        if ($data === null || $token === '' || strlen($token) !== strlen($data['token'])) {
            return false;
        }

        return hash_equals($data['token'], $token)
            && $data['action'] === $action
            && $data['version'] === $version
            && $data['expires'] >= time();
    }

    /**
     * @param array{token: string, expires: int, action: string, version: string} $payload
     */
    private function remember(array $payload): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        setcookie($this->confirmationCookie(), base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), [
            'expires' => $payload['expires'],
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /**
     * @return array{token: string, expires: int, action: string, version: string}|null
     */
    private function readConfirmation(): ?array
    {
        $raw = $_COOKIE[$this->confirmationCookie()] ?? '';
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $json = base64_decode($raw, true);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data) || !is_string($data['token'] ?? null) || $data['token'] === '') {
            return null;
        }
        if ((int) ($data['expires'] ?? 0) < time()) {
            return null;
        }

        return [
            'token' => $data['token'],
            'expires' => (int) $data['expires'],
            'action' => (string) ($data['action'] ?? ''),
            'version' => (string) ($data['version'] ?? ''),
        ];
    }

    private function forgetConfirmation(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        setcookie($this->confirmationCookie(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private function confirmationCookie(): string
    {
        $name = strtolower(basename($this->modulePath));
        $name = preg_replace('/[^a-z0-9_-]/', '', $name) ?: 'module';

        return 'mu_update_' . $name;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function field(array $post, string $key): string
    {
        $value = $post[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private function safeAction(string $action): string
    {
        if ($action === '') {
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            $action = is_string($uri) ? $uri : '';
        }
        if ($action === '' || !str_starts_with($action, '/') || str_starts_with($action, '//') || preg_match('/[\r\n\\\\]/', $action) === 1) {
            return '';
        }

        return $action;
    }

    private function stateDirectory(): string
    {
        $module = realpath($this->modulePath);
        if ($module === false || !is_dir($module)) {
            throw new ModuleUpdateException(AdminTranslator::line('Module directory not found.'));
        }
        $directory = $module . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'update';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new ModuleUpdateException(AdminTranslator::line('State directory not found.'));
        }
        $htaccess = $directory . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($htaccess)) {
            file_put_contents($htaccess, module_update_deny_htaccess());
        }
        $index = $directory . DIRECTORY_SEPARATOR . 'index.php';
        if (!is_file($index)) {
            file_put_contents($index, "<?php\nhttp_response_code(403);\nexit;\n");
        }

        return $directory;
    }

    private function csrfPath(): string
    {
        return $this->stateDirectory() . DIRECTORY_SEPARATOR . 'csrf.json';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
