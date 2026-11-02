<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/lib.php';

use AiDev\ModuleUpdate\Client\Installer;
use AiDev\ModuleUpdate\Client\UpdateClient;
use AiDev\ModuleUpdate\Client\UpdatePanel;
use AiDev\ModuleUpdate\Hmac;
use AiDev\ModuleUpdate\ModuleUpdateException;

$server = null;
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'module-update-' . bin2hex(random_bytes(4));
register_shutdown_function(static function () use (&$server, $temporaryRoot): void {
    if (is_array($server)) {
        stop_server($server);
        $server = null;
    }
    delete_tree($temporaryRoot);
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    run_self_test($temporaryRoot, $server);
    fwrite(STDOUT, "Tous les contrôles sont passés.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'ECHEC ' . $error->getMessage() . PHP_EOL);
    if (is_array($server) && is_file($server['log'])) {
        fwrite(STDERR, "Journal serveur :\n" . file_get_contents($server['log']) . PHP_EOL);
    }
    exit(1);
}

/**
 * @param array<string, mixed>|null $server
 */
function run_self_test(string $root, ?array &$server): void
{
    mkdir($root);
    $project = dirname(__DIR__);
    $shop = $root . DIRECTORY_SEPARATOR . 'shop' . DIRECTORY_SEPARATOR . 'monmodule';
    mkdir($shop . DIRECTORY_SEPARATOR . 'config', 0755, true);
    mkdir($shop . DIRECTORY_SEPARATOR . 'var', 0755, true);
    file_put_contents($shop . DIRECTORY_SEPARATOR . 'monmodule.php', "<?php\n// version 1.0.0\n");
    file_put_contents($shop . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'update.php', 'SECRET_LOCAL');
    file_put_contents($shop . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'local.txt', 'keep');

    $fixture = $root . DIRECTORY_SEPARATOR . 'fixture';
    mkdir($fixture . DIRECTORY_SEPARATOR . 'config', 0755, true);
    mkdir($fixture . DIRECTORY_SEPARATOR . 'var', 0755, true);
    file_put_contents($fixture . DIRECTORY_SEPARATOR . 'ok.php', "<?php\n");
    file_put_contents($fixture . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'update.php', 'SECRET');
    file_put_contents($fixture . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'secret.txt', 'local');
    $fixtureZip = $root . DIRECTORY_SEPARATOR . 'fixture.zip';
    $built = run_php([$project . '/bin/build-zip.php', $fixture, $fixtureZip]);
    expect_code($built, 0, 'construction de l’archive');
    $names = zip_names($fixtureZip);
    expect(in_array('fixture/ok.php', $names, true) && !in_array('ok.php', $names, true), 'le dossier du module est la racine de l’archive');
    expect(!in_array('fixture/config/update.php', $names, true) && !in_array('fixture/var/secret.txt', $names, true), 'le secret local est exclu de l’archive');

    $generated = run_php([$project . '/bin/generate-keys.php', 'monmodule', '--root=' . $root]);
    expect_code($generated, 0, 'génération des clés');
    $privateKey = trim((string) file_get_contents($root . '/private/monmodule.secret.key'));
    $publicKey = trim((string) file_get_contents($root . '/storage/keys/monmodule.public.key'));
    expect($privateKey !== '' && !str_contains($generated['out'], $privateKey), 'la clé privée n’est pas affichée');
    expect(str_contains($generated['out'], $publicKey), 'la clé publique est affichée');

    $registered = run_php([$project . '/bin/register-module.php', 'monmodule', '--root=' . $root]);
    expect_code($registered, 0, 'enregistrement du module');
    $secret = secret_b64($root, 'monmodule');
    expect(str_contains($registered['out'], $secret), 'le secret est affiché une fois');
    expect(!str_contains($registered['out'], $privateKey), 'l’enregistrement n’affiche pas la clé privée');

    $badZip = $root . DIRECTORY_SEPARATOR . 'bad.zip';
    write_zip($badZip, ['../evil.php' => '<?php']);
    $rejected = run_php([
        $project . '/bin/publish-release.php',
        'monmodule',
        '1.0.1',
        $badZip,
        '--changelog=test',
        '--root=' . $root,
    ]);
    expect($rejected['code'] !== 0, 'une archive avec un chemin traversant est refusée');
    expect(!is_file($root . '/evil.php'), 'aucun fichier n’est écrit hors du dépôt');

    $goodZip = $root . DIRECTORY_SEPARATOR . 'good.zip';
    write_zip($goodZip, [
        'monmodule/monmodule.php' => "<?php\n// version 1.1.0\n",
        'monmodule/nouveau.txt' => 'nouveau',
        'monmodule/config/update.php' => 'SECRET_REMOTE',
        'monmodule/var/evil.txt' => 'evil',
    ]);
    $changelog = $root . DIRECTORY_SEPARATOR . 'changelog.txt';
    file_put_contents($changelog, "Correctif <script>alert(1)</script>\n");
    $published = run_php([
        $project . '/bin/publish-release.php',
        'monmodule',
        '1.1.0',
        $goodZip,
        '--changelog-file=' . $changelog,
        '--root=' . $root,
    ]);
    expect_code($published, 0, 'publication 1.1.0');

    $otherZip = $root . DIRECTORY_SEPARATOR . 'other.zip';
    write_zip($otherZip, ['othermodule/marker.txt' => 'UNIQUE_OTHER_MODULE_9f3a']);
    expect_code(run_php([$project . '/bin/generate-keys.php', 'othermodule', '--root=' . $root]), 0, 'clés du second module');
    expect_code(run_php([$project . '/bin/register-module.php', 'othermodule', '--root=' . $root]), 0, 'enregistrement du second module');
    expect_code(run_php([
        $project . '/bin/publish-release.php',
        'othermodule',
        '2.0.0',
        $otherZip,
        '--changelog=autre module',
        '--root=' . $root,
    ]), 0, 'publication du second module');

    $export = $root . DIRECTORY_SEPARATOR . 'client-export';
    expect_code(run_php([$project . '/bin/export-client.php', $export]), 0, 'export du client');
    expect(is_file($export . '/src/Client/UpdateClient.php'), 'le client exporté contient UpdateClient');
    expect(!is_dir($export . '/src/Server') && !is_dir($export . '/public'), 'l’export ne contient ni le serveur ni le dossier public');

    if (!is_dir($root . '/config') && !mkdir($root . '/config', 0755, true) && !is_dir($root . '/config')) {
        throw new RuntimeException('Dossier de configuration de test introuvable.');
    }
    file_put_contents($root . '/config/server.php', "<?php\ndeclare(strict_types=1);\nreturn ['rate_limit' => 500];\n");
    $server = start_server($root, $project . DIRECTORY_SEPARATOR . 'public');
    $base = 'http://127.0.0.1:' . $server['port'];

    $anonymous = http_request($base . '/package.php?version=1.1.0');
    expect($anonymous['status'] === 401 && !str_contains($anonymous['body'], 'nouveau'), 'un appel sans jeton ne reçoit pas le zip');

    $manifest = http_request($base . '/manifest.php', signed_headers('monmodule', secret_raw($root, 'monmodule'), 'manifest.php', ''));
    expect($manifest['status'] === 200 && str_contains($manifest['body'], '1.1.0'), 'le manifeste authentifié est servi');

    $replayHeaders = signed_headers('monmodule', secret_raw($root, 'monmodule'), 'manifest.php', '');
    expect(http_request($base . '/manifest.php', $replayHeaders)['status'] === 200, 'premier appel avec ce nonce');
    expect(http_request($base . '/manifest.php', $replayHeaders)['status'] === 401, 'le nonce ne peut pas être rejoué');

    $stale = signed_headers('monmodule', secret_raw($root, 'monmodule'), 'manifest.php', '', bin2hex(random_bytes(16)), (string) (time() - 1000));
    expect(http_request($base . '/manifest.php', $stale)['status'] === 401, 'un horodatage périmé est refusé');

    $crossed = signed_headers('othermodule', secret_raw($root, 'monmodule'), 'package.php', 'version=2.0.0');
    $crossedResponse = http_request($base . '/package.php?version=2.0.0', $crossed);
    expect($crossedResponse['status'] === 401 && !str_contains($crossedResponse['body'], 'UNIQUE_OTHER_MODULE_9f3a'), 'le secret d’un module n’ouvre pas le dépôt d’un autre');

    $foreign = http_request(
        $base . '/package.php?version=2.0.0',
        signed_headers('monmodule', secret_raw($root, 'monmodule'), 'package.php', 'version=2.0.0'),
    );
    expect($foreign['status'] === 404 && !str_contains($foreign['body'], 'UNIQUE_OTHER_MODULE_9f3a'), 'un module ne peut pas lire la version d’un autre');

    $escape = http_request($base . '/../storage/secrets.json', [], true);
    expect(!str_contains($escape['body'], '"secret"'), 'le registre des secrets n’est pas accessible par URL');

    $client = UpdateClient::fromConfig([
        'base_url' => $base,
        'module_id' => 'monmodule',
        'module_secret' => $secret,
        'public_key' => $publicKey,
        'allow_insecure_http' => true,
    ]);
    $tampered = json_decode((string) file_get_contents($root . '/storage/modules/monmodule/manifest.json'), true);
    expect(is_array($tampered), 'manifeste lisible');
    $tampered['changelog'] = 'pirate';
    $rejectedSignature = false;
    try {
        $client->inspect(json_encode($tampered, JSON_THROW_ON_ERROR));
    } catch (ModuleUpdateException $error) {
        $rejectedSignature = str_contains($error->getMessage(), 'signature');
    }
    expect($rejectedSignature, 'une modification du journal casse la signature');

    $remote = $client->check();
    expect($remote->version === '1.1.0', 'le client reconnaît la version distante');

    $panel = new UpdatePanel($client, new Installer(), $shop, '1.0.0');
    $checked = $panel->handle([]);
    $repeated = $panel->handle([]);
    expect($repeated === $checked, 'un second affichage du même envoi conserve le résultat');
    expect(str_contains($checked, '&lt;script&gt;') && !str_contains($checked, '<script>'), 'le journal est affiché sans HTML');
    expect(str_contains($checked, '1.1.0') && str_contains($checked, 'Changelog'), 'la version et le journal sont affichés sans vérification manuelle');

    $installToken = csrf($shop);
    UpdatePanel::resetRequestCache();
    $kept = $panel->handle([]);
    expect(csrf($shop) === $installToken && str_contains($kept, 'Install version'), 'un affichage parallèle conserve la confirmation');
    $refused = $panel->handle([
        'mu_action' => 'install',
        'mu_token' => $installToken,
        'mu_version' => '1.1.0',
        'mu_confirm' => 'no',
    ]);
    expect(str_contains($refused, 'incomplete'), 'l’installation sans confirmation est refusée');
    expect(str_contains((string) file_get_contents($shop . '/monmodule.php'), '1.0.0'), 'les fichiers n’ont pas changé');

    UpdatePanel::resetRequestCache();
    $panel->handle([]);
    $installToken = csrf($shop);
    $done = $panel->handle([
        'mu_action' => 'install',
        'mu_token' => $installToken,
        'mu_version' => '1.1.0',
        'mu_confirm' => 'yes',
    ]);
    if (!str_contains($done, '1.1.0 installed')) {
        throw new RuntimeException("l’installation confirmée se termine\n" . $done);
    }
    fwrite(STDOUT, "OK l’installation confirmée se termine\n");
    expect(str_contains((string) file_get_contents($shop . '/monmodule.php'), '1.1.0'), 'les fichiers du module sont remplacés');
    expect(is_file($shop . '/nouveau.txt'), 'le nouveau fichier est installé');
    expect(file_get_contents($shop . '/config/update.php') === 'SECRET_LOCAL', 'le secret local est conservé');
    expect(file_get_contents($shop . '/var/local.txt') === 'keep', 'les fichiers locaux de var sont conservés');
    expect(!is_file($shop . '/var/evil.txt'), 'le zip ne peut pas écrire dans var');
    expect(count(glob($shop . '/var/backups/*.zip') ?: []) === 1, 'une sauvegarde a été créée');

    UpdatePanel::resetRequestCache();
    $replayed = $panel->handle([
        'mu_action' => 'install',
        'mu_token' => $installToken,
        'mu_version' => '1.1.0',
        'mu_confirm' => 'yes',
    ]);
    expect(str_contains($replayed, 'invalid') || str_contains($replayed, 'expired'), 'la confirmation ne peut pas être rejouée');

    $current = new UpdatePanel($client, new Installer(), $shop, '1.1.0');
    $upToDate = $current->handle([]);
    expect(str_contains($upToDate, 'up to date') && str_contains($upToDate, 'Changelog'), 'aucune mise à jour n’est proposée si la version est identique');

    $package = $root . '/storage/modules/monmodule/releases/1.1.0.zip';
    $copy = $package . '.copy';
    copy($package, $copy);
    $handle = fopen($package, 'c+');
    expect(is_resource($handle), 'paquet ouvrable');
    fseek($handle, 0);
    fwrite($handle, 'XXXX');
    fclose($handle);
    $broken = false;
    try {
        $client->download($remote);
    } catch (ModuleUpdateException) {
        $broken = true;
    }
    expect($broken, 'un zip modifié est refusé');
    copy($copy, $package);

    fwrite(STDOUT, "Contrôles terminés.\n");
}

/**
 * @param list<string> $args
 * @return array{code: int, out: string}
 */
function run_php(array $args): array
{
    $command = escapeshellarg(PHP_BINARY);
    foreach ($args as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        return ['code' => 1, 'out' => 'Impossible de lancer PHP.'];
    }
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'out' => (string) $output . (string) $errors];
}

/**
 * @param array{code: int, out: string} $result
 */
function expect_code(array $result, int $code, string $label): void
{
    if ($result['code'] !== $code) {
        throw new RuntimeException($label . " (code {$result['code']})\n" . $result['out']);
    }
    fwrite(STDOUT, "OK {$label}\n");
}

function expect(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    fwrite(STDOUT, "OK {$label}\n");
}

/**
 * @param array<string, string> $files
 */
function write_zip(string $path, array $files): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Archive de test impossible.');
    }
    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
}

/**
 * @return list<string>
 */
function zip_names(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return [];
    }
    $names = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = $zip->getNameIndex($index);
        if (is_string($name)) {
            $names[] = $name;
        }
    }
    $zip->close();

    return $names;
}

function secret_b64(string $root, string $moduleId): string
{
    $data = json_decode((string) file_get_contents($root . '/storage/secrets.json'), true);
    if (!is_array($data) || !is_string($data['modules'][$moduleId]['secret'] ?? null)) {
        throw new RuntimeException('Secret introuvable.');
    }

    return $data['modules'][$moduleId]['secret'];
}

function secret_raw(string $root, string $moduleId): string
{
    return sodium_base642bin(secret_b64($root, $moduleId), SODIUM_BASE64_VARIANT_ORIGINAL);
}

/**
 * @return list<string>
 */
function signed_headers(
    string $moduleId,
    string $secret,
    string $script,
    string $query,
    ?string $nonce = null,
    ?string $timestamp = null,
): array {
    $timestamp ??= (string) time();
    $nonce ??= bin2hex(random_bytes(16));
    $signature = Hmac::sign($secret, 'GET', $script, $timestamp, $nonce, $query);
    $token = Hmac::token($moduleId, $timestamp, $nonce, $signature);

    return [
        'Authorization: ModuleUpdate ' . $token,
        'X-Module-Token: ' . $token,
    ];
}

/**
 * @param list<string> $headers
 * @return array{status: int, body: string}
 */
function http_request(string $url, array $headers = [], bool $rawPath = false): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        return ['status' => 0, 'body' => ''];
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($rawPath && defined('CURLOPT_PATH_AS_IS')) {
        $options[CURLOPT_PATH_AS_IS] = true;
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    return ['status' => $status, 'body' => is_string($body) ? $body : ''];
}

function csrf(string $shop): string
{
    $data = json_decode((string) file_get_contents($shop . '/var/update/csrf.json'), true);
    if (!is_array($data) || !is_string($data['token'] ?? null)) {
        throw new RuntimeException('Jeton de confirmation introuvable.');
    }

    return $data['token'];
}

/**
 * @return array{proc: resource, port: int, log: string}
 */
function start_server(string $root, string $public): array
{
    $log = $root . DIRECTORY_SEPARATOR . 'php-server.log';
    $env = ['MODULE_UPDATE_ROOT' => $root];
    foreach (['PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'PATHEXT', 'COMSPEC', 'PHPRC'] as $name) {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            $env[$name] = $value;
        }
    }

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $port = random_int(20000, 45000);
        $command = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($public);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $public, $env);
        if (!is_resource($process)) {
            continue;
        }
        for ($wait = 0; $wait < 40; $wait++) {
            $probe = http_request('http://127.0.0.1:' . $port . '/index.php');
            if ($probe['status'] === 404) {
                return ['proc' => $process, 'port' => $port, 'log' => $log, 'pipes' => $pipes];
            }
            usleep(100000);
        }
        stop_server(['proc' => $process, 'port' => $port, 'log' => $log, 'pipes' => $pipes]);
    }

    throw new RuntimeException('Serveur de test indisponible.');
}

/**
 * @param array{proc: resource, port: int, log: string} $server
 */
function stop_server(array $server): void
{
    if (isset($server['pipes'][0]) && is_resource($server['pipes'][0])) {
        fclose($server['pipes'][0]);
    }
    $status = proc_get_status($server['proc']);
    $pid = (int) ($status['pid'] ?? 0);
    proc_terminate($server['proc']);
    if (PHP_OS_FAMILY === 'Windows' && $pid > 0) {
        exec('taskkill /F /T /PID ' . $pid . ' 2>NUL');
    }
    proc_close($server['proc']);
}

function delete_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $items = scandir($directory);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            delete_tree($path);
            continue;
        }
        @unlink($path);
    }
    @rmdir($directory);
}
