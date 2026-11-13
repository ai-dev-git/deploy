<?php

declare(strict_types=1);

namespace AiDev\ModuleUpdate\Client;

use AiDev\ModuleUpdate\Manifest;
use AiDev\ModuleUpdate\ModuleUpdateException;
use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Branchement PrestaShop : à appeler depuis getContent(), qui n'est joignable
 * que par un employé déjà authentifié.
 */
final class PrestaShopUpdater
{
    public static function renderFromModule(object $module, string $formAction = ''): string
    {
        if (!defined('_PS_VERSION_') || !isset($module->name, $module->version)) {
            return '';
        }

        AdminTranslator::set(self::translator($module));
        $name = (string) $module->name;
        $version = (string) $module->version;
        if (!defined('_PS_MODULE_DIR_')) {
            return '';
        }

        $path = rtrim((string) _PS_MODULE_DIR_, '/\\') . DIRECTORY_SEPARATOR . $name;
        $configFile = $path . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'update.php';
        if (!is_file($configFile)) {
            return '<section class="mu-update"><h2>' . self::text('Updates') . '</h2><p class="mu-error">' . self::text('Update configuration is missing.') . '</p></section>';
        }

        if (!defined('MODULE_UPDATE_INTERNAL')) {
            define('MODULE_UPDATE_INTERNAL', true);
        }
        $config = require $configFile;
        if (!is_array($config)) {
            throw new ModuleUpdateException(AdminTranslator::line('Update configuration is unreadable.'));
        }

        $preserve = ['var', 'config/update.php'];
        if (isset($config['preserve_paths']) && is_array($config['preserve_paths'])) {
            $preserve = [];
            foreach ($config['preserve_paths'] as $item) {
                if (is_string($item) && $item !== '') {
                    $preserve[] = $item;
                }
            }
        }

        $installed = self::newerVersion($version, self::versionOnDisk($path, $name));
        $notice = '';
        try {
            self::registerVersion($name, $installed, $path);
        } catch (ModuleUpdateException $exception) {
            $notice = '<p class="mu-error">' . htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        $panel = new UpdatePanel(
            UpdateClient::fromConfig($config),
            new Installer(),
            $path,
            $installed,
            static function (Manifest $manifest) use ($name, $path): void {
                self::registerVersion($name, $manifest->version, $path);
            },
            $preserve,
        );

        return $notice . $panel->handle(self::post(), $formAction);
    }

    private static function text(string $message): string
    {
        return htmlspecialchars(AdminTranslator::line($message), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @return Closure(string): string
     */
    private static function translator(object $module): Closure
    {
        return static function (string $message) use ($module): string {
            if (method_exists($module, 'translate')) {
                $translated = $module->translate($message, 'Update');

                return is_string($translated) ? $translated : $message;
            }
            if (method_exists($module, 'trans')) {
                $domain = 'Modules.' . ucfirst((string) $module->name) . '.Update';
                $translated = $module->trans($message, [], $domain);

                return is_string($translated) ? $translated : $message;
            }

            return $message;
        };
    }

    /**
     * Version distante plus récente que les fichiers, ou null.
     * Le résultat est conservé quelques minutes : ce contrôle est appelé depuis l'en-tête d'administration.
     */
    public static function newerRelease(object $module): ?string
    {
        $loaded = self::moduleContext($module);
        if ($loaded === null) {
            return null;
        }
        [$path, $installed] = $loaded;
        $cached = self::readNotice($path);
        if (is_string($cached)) {
            return self::isNewer($cached, $installed) ? $cached : null;
        }

        $status = self::remoteVersion($module);

        return self::isNewer((string) ($status['version'] ?? ''), $installed) ? $status['version'] : null;
    }

    /**
     * Version publiée, ou le message qui explique pourquoi elle est absente.
     * Le contrôle réussi est conservé quelques minutes.
     *
     * @return array{version: ?string, error: ?string}
     */
    public static function remoteVersion(object $module): array
    {
        AdminTranslator::set(self::translator($module));
        $loaded = self::moduleContext($module);
        if ($loaded === null) {
            return ['version' => null, 'error' => AdminTranslator::line('Update configuration is missing.')];
        }
        [$path, $config] = [$loaded[0], $loaded[2]];
        $cached = self::readNoticeRecord($path);
        if ($cached !== null && ($cached['version'] !== '' || $cached['error'] !== '')) {
            return [
                'version' => $cached['version'] !== '' ? $cached['version'] : null,
                'error' => $cached['error'] !== '' ? $cached['error'] : null,
            ];
        }

        try {
            $config['timeout'] = 8;
            $remote = UpdateClient::fromConfig($config)->check()->version;
            self::writeNotice($path, $remote, 900);

            return ['version' => $remote, 'error' => null];
        } catch (Throwable $exception) {
            $message = $exception instanceof ModuleUpdateException
                ? $exception->getMessage()
                : AdminTranslator::line('The remote release is unavailable.');
            if (stripos($message, 'incomplete') === false) {
                self::writeNotice($path, '', 120, $message);
            }

            return ['version' => null, 'error' => $message];
        }
    }

    /**
     * La liste des modules affiche ps_module.version, pas le numéro écrit dans les fichiers.
     * On aligne la base sur les fichiers, puis on invalide le cache du gestionnaire.
     */
    private static function registerVersion(string $name, string $version, string $path): void
    {
        if (!self::isVersionLabel($version) || !class_exists(\Module::class) || !method_exists(\Module::class, 'upgradeModuleVersion')) {
            return;
        }

        $stored = self::databaseVersion($name);
        if ($stored === null) {
            return;
        }

        $changed = false;
        if (version_compare($version, $stored, '>')) {
            if (\Module::upgradeModuleVersion($name, $version) !== true || self::databaseVersion($name) !== $version) {
                throw new ModuleUpdateException(AdminTranslator::line('PrestaShop could not update the registered version.'));
            }
            $changed = true;
        }

        $registered = $changed ? $version : $stored;
        if ($changed || self::readStamp($path) !== $registered) {
            self::forgetModuleCache($name, $path);
            self::writeStamp($path, $registered);
        }
        if ($changed) {
            self::forgetNotice($path);
        }
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>}|null
     */
    private static function moduleContext(object $module): ?array
    {
        if (!defined('_PS_VERSION_') || !isset($module->name, $module->version) || !defined('_PS_MODULE_DIR_')) {
            return null;
        }

        $name = (string) $module->name;
        $path = rtrim((string) _PS_MODULE_DIR_, '/\\') . DIRECTORY_SEPARATOR . $name;
        $configFile = $path . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'update.php';
        if (!is_file($configFile)) {
            return null;
        }
        if (!defined('MODULE_UPDATE_INTERNAL')) {
            define('MODULE_UPDATE_INTERNAL', true);
        }
        $config = require $configFile;
        if (!is_array($config)) {
            return null;
        }

        return [$path, self::newerVersion((string) $module->version, self::versionOnDisk($path, $name)), $config];
    }

    private static function isNewer(string $remote, string $installed): bool
    {
        return $remote !== '' && self::isVersionLabel($remote) && version_compare($remote, $installed, '>');
    }

    private static function readNotice(string $path): ?string
    {
        $record = self::readNoticeRecord($path);
        if ($record === null) {
            return null;
        }

        return $record['version'];
    }

    /**
     * @return array{version: string, error: string}|null
     */
    private static function readNoticeRecord(string $path): ?array
    {
        $raw = is_file(self::noticePath($path)) ? file_get_contents(self::noticePath($path)) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || (int) ($data['expires'] ?? 0) < time() || !is_string($data['version'] ?? null)) {
            return null;
        }

        return [
            'version' => $data['version'],
            'error' => is_string($data['error'] ?? null) ? $data['error'] : '',
        ];
    }

    private static function writeNotice(string $path, string $version, int $ttl, string $error = ''): void
    {
        if (!self::ensureStateDirectory($path)) {
            return;
        }
        $json = json_encode(['version' => $version, 'error' => $error, 'expires' => time() + $ttl], JSON_THROW_ON_ERROR);
        file_put_contents(self::noticePath($path), $json, LOCK_EX);
    }

    private static function forgetNotice(string $path): void
    {
        $file = self::noticePath($path);
        if (is_file($file)) {
            unlink($file);
        }
    }

    private static function noticePath(string $path): string
    {
        return $path . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'update' . DIRECTORY_SEPARATOR . 'notice.json';
    }

    private static function ensureStateDirectory(string $path): bool
    {
        $directory = $path . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'update';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return false;
        }
        $htaccess = $directory . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($htaccess) && function_exists('module_update_deny_htaccess')) {
            file_put_contents($htaccess, module_update_deny_htaccess());
        }
        $index = $directory . DIRECTORY_SEPARATOR . 'index.php';
        if (!is_file($index)) {
            file_put_contents($index, "<?php\nhttp_response_code(403);\nexit;\n");
        }

        return true;
    }

    private static function databaseVersion(string $name): ?string
    {
        if (!class_exists(\Db::class) || !defined('_DB_PREFIX_') || !function_exists('pSQL')) {
            return null;
        }

        $value = \Db::getInstance()->getValue(
            'SELECT `version` FROM `' . _DB_PREFIX_ . 'module` WHERE `name` = \'' . pSQL($name) . '\''
        );

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function versionOnDisk(string $path, string $name): ?string
    {
        $file = $path . DIRECTORY_SEPARATOR . $name . '.php';
        $source = is_file($file) ? file_get_contents($file) : false;
        if (!is_string($source) || preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $match) !== 1) {
            return null;
        }

        return self::isVersionLabel($match[1]) ? $match[1] : null;
    }

    private static function newerVersion(string $left, ?string $right): string
    {
        if ($right === null) {
            return $left;
        }

        return version_compare($right, $left, '>') ? $right : $left;
    }

    private static function isVersionLabel(string $version): bool
    {
        return strlen($version) <= 32 && preg_match('/^[0-9A-Za-z][0-9A-Za-z.+\-]*$/', $version) === 1;
    }

    private static function forgetModuleCache(string $name, string $path): void
    {
        if (class_exists(\Cache::class) && function_exists('pSQL')) {
            \Cache::clean('Module::isInstalled' . $name);
            \Cache::clean('Module::getModuleIdByName_' . pSQL($name));
        }
        if (is_dir($path)) {
            @touch($path);
        }

        try {
            if (class_exists(\PrestaShop\PrestaShop\Adapter\SymfonyContainer::class)) {
                $container = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
                if (is_object($container)) {
                    foreach (['prestashop.core.admin.module.repository', \PrestaShop\PrestaShop\Core\Module\ModuleRepository::class] as $service) {
                        if (!$container->has($service)) {
                            continue;
                        }
                        $repository = $container->get($service);
                        if (is_object($repository) && method_exists($repository, 'clearCache')) {
                            $repository->clearCache($name, true);
                        }
                        break;
                    }
                }
            }
        } catch (Throwable) {
        }

        self::invalidateOpcache($path);
    }

    private static function invalidateOpcache(string $path): void
    {
        if (!function_exists('opcache_invalidate') || !is_dir($path)) {
            return;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with(strtolower($file->getFilename()), '.php')) {
                    opcache_invalidate($file->getPathname(), true);
                }
            }
        } catch (Throwable) {
        }
    }

    private static function readStamp(string $path): ?string
    {
        $file = $path . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'update' . DIRECTORY_SEPARATOR . 'registered-version.txt';
        $value = is_file($file) ? file_get_contents($file) : false;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return self::isVersionLabel($value) ? $value : null;
    }

    private static function writeStamp(string $path, string $version): void
    {
        if (!self::ensureStateDirectory($path)) {
            return;
        }
        $file = $path . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'update' . DIRECTORY_SEPARATOR . 'registered-version.txt';
        file_put_contents($file, $version . "\n", LOCK_EX);
    }

    /**
     * @return array<string, mixed>
     */
    private static function post(): array
    {
        return is_array($_POST ?? null) ? $_POST : [];
    }
}
