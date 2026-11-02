# Signed module updates

PHP service that publishes and installs updates for several modules on ordinary hosting. There is no license management: each module has an identifier, a secret, and its own key pair. The same client works with PrestaShop, another CMS, or a plain PHP script.

The server only stores and delivers files. The installed module alone decides whether a package is authentic, because it checks a signature with the public key it already contains.

## Two separate roles

| Role | Secret it holds | What it guarantees |
| --- | --- | --- |
| Module identifier + secret | Both ends, the same value for every shop that has this module | A shop cannot read another module’s directory, and neither can an anonymous visitor |
| Ed25519 private key | Only the machine that publishes | Nobody else can produce an update that will be accepted |
| Ed25519 public key | Every copy of the module, in `config/update.php` | The module rejects a modified zip or changelog |

The secret is not a license. Anyone who already has the module can extract the secret and download that module’s zips. That is intentional. The secret does not open any other module, and it cannot sign a fake update.

The private key must never be placed on the web server. A compromised server can stop the service or leak zips that were already published. It cannot make installed modules accept a modified zip.

## Layout

```
module-updates/
  public/                  web root
    manifest.php           latest version, after authentication
    package.php            zip of one version, after authentication
  storage/                 outside the web root
    secrets.json           identifier → secret
    keys/*.public.key      public keys, one per module
    modules/<module>/
      manifest.json        current version
      releases/<version>.zip
      releases/<version>.json
  private/                 publishing machine only
    <module>.secret.key
  publish.bat              Windows example: build, publish, upload
```

The vhost document root must be `public/`. The URLs are then:

- `https://updates.example.com/manifest.php`
- `https://updates.example.com/package.php?version=1.2.0`

`storage/` and `private/` contain an `.htaccess` that denies HTTP access. That is only a safety net: those directories must not be under the web root. There is no URL for uploading a zip. Publication is done from the command line, then by copying files.

`config/update.php` does not live on this server. It belongs in each installed module, on the shop or in the script that receives updates. The panel classes (`UpdatePanel`, `PrestaShopUpdater`) also belong in the module, under `updates/`, not on the update server.

## The version file

`manifest.json` and `releases/<version>.json` contain the same record:

- `version`
- `changelog`
- `package_url`: relative zip address, always `package.php?version=<version>`
- `sha256`: zip fingerprint
- `signature`: Ed25519 signature
- `filename`, `released_at`, `module_id`, `signature_alg`

The changelog is free UTF-8 text. Several languages can share the same file: the client shows it as it was published.

The address is relative so a manifest cannot send the module to download a zip from another domain. The full URL is the address configured in the module, followed by this relative address. The client checks that the host, port, and path stay the ones it knows, and it does not follow redirects.

The signature does not cover the raw JSON, nor the fingerprint alone. Signing only the fingerprint would let an attacker change the version or the changelog while keeping a valid signature. The signed payload is this UTF-8 text, one field per line, LF line endings, including after the last line:

```
module-update-v1
alg=ed25519
module_id=<identifier>
version=<version>
released_at=<UTC date>
filename=<identifier>-<version>.zip
sha256=<zip fingerprint>
package_url=package.php?version=<version>
changelog_sha256=<SHA-256 of the changelog>
```

The changelog may contain line breaks: they are not part of this text, only their fingerprint is. The module rebuilds this payload from the fields it received, then checks the signature with its public key. It also recomputes the zip fingerprint after download.

## Call authentication

Every call is a cURL GET. The secret is not sent. The module sends a token in `Authorization: ModuleUpdate …` and the same token in `X-Module-Token` (some hosts strip `Authorization`).

The token is `MU1.<identifier>.<timestamp>.<nonce>.<hmac>`. The HMAC-SHA256 is computed with the module secret over:

```
MU1
GET
manifest.php
<unix timestamp, 10 digits>
<16-byte nonce, hexadecimal>
<SHA-256 of an empty body>
<canonical query>
```

For the manifest, the canonical query is empty. For the zip, it is `version=<version>`. A timestamp older or newer than five minutes is refused. Each nonce is accepted once. The server chooses the directory only from the authenticated identifier: no URL parameter can target another module’s directory. An unknown identifier and a wrong secret produce the same response.

Sixty calls per identifier and per IP address are accepted over ten minutes. The shop clock must be roughly correct.

A call to `manifest.php` without a token returns 401. That shows the server and the certificate are answering. The certificate itself is not the problem.

## Module administration

The panel is shown inside the module’s already authenticated administration. There is no public install page. When the panel opens, it calls `manifest.php`, checks the signature, and shows the published version and the changelog. The “Check for updates” button appears only when that call fails.

If a newer version exists, the changelog is shown as text (HTML is escaped), with the version and the fingerprint. Installation requires a checked box and a one-time token bound to that exact version. The box is checked by the server at install time, so it does not block saving the other forms on the page. If another version was published in the meantime, installation stops.

The module then downloads the record again, downloads the zip, checks the signature and the fingerprint again, and replaces the files.

Before replacement, a backup is written to `var/backups/`. The existing `var/` directory and `config/update.php` file are not overwritten: an update cannot replace the pinned public key. `..` paths, symbolic links, and overly expanded archives are refused. If the copy fails, the backup is restored.

Client messages are in English. Under PrestaShop, `PrestaShopUpdater` passes them to the module translator (`translate($text, 'Update')`, or `trans()` otherwise), on the domain `Modules.{ModuleName}.Update`. Without a translator, English is shown.

### PrestaShop

The module list shows the version stored in `ps_module`, not the version in the files. When the module configuration opens, that version is aligned with the files and the module manager cache is cleared. The PHP class already loaded keeps the old number during the install request: reload the page afterwards.

`PrestaShopUpdater::newerRelease($module)` returns the published version when it is strictly newer, otherwise `null`. The module can use it for a banner on the module manager and at the top of its own configuration. A success is kept for fifteen minutes in `var/update/notice.json`, a failure for two minutes. During those two minutes the banner stays empty: delete that file to force a new call.

The panel HTML contains its own form. Place it outside the module’s configuration `<form>`.

PHP cURL verifies the HTTPS certificate. If `curl.cainfo` and `openssl.cafile` are empty, the call fails before any version is compared. Point both directives at a CA bundle in the `php.ini` that serves the back office, then restart the web server. When the shop is served under the host name `localhost`, this check is skipped, as for the module’s other outbound calls. On any other host name, it stays active. The package signature and fingerprint are checked in every case.

## Setting up the server

PHP 8.1 or newer, with `sodium`, `curl`, `zip`, and `hash`. `curl` is required only in the shops. The server needs `sodium`.

Keys are generated on the publishing machine. The module is registered on the server, where `storage/secrets.json` must live.

```bash
# on the publishing machine
php bin/generate-keys.php mymodule

# storage/keys/mymodule.public.key goes to the server
# private/mymodule.secret.key stays where it is

# on the server
php bin/register-module.php mymodule
```

`register-module.php` prints the secret once. Copy it immediately into the module’s `config/update.php` on the shop. To publish later from the local machine, copy the server’s `storage/secrets.json` onto the publishing machine. A second local `register-module.php` would create another secret, while the server would keep using its own.

If `secrets.json` exists but is empty or unreadable, the script cannot register the module. Delete that file, then run registration again.

Configure the vhost:

- document root: `.../module-updates/public`
- HTTPS
- PHP runs `manifest.php` and `package.php`

Copy `config/server.example.php` to `config/server.php` only when the default limits are not suitable (50 MB maximum size, 300-second clock window).

## Publishing a version

`publish.bat` expects the changelog at `docs/changelogs/<version>.txt` in the module sources. The PHP script accepts any UTF-8 file:

```bash
php bin/build-zip.php /path/to/module /tmp/mymodule-1.2.0.zip
php bin/publish-release.php mymodule 1.2.0 /tmp/mymodule-1.2.0.zip --changelog-file=/path/to/module/docs/changelogs/1.2.0.txt
```

`build-zip.php` puts the module directory at the root of the archive (`mymodule/…`). That name must be the module identifier. The script excludes `var/`, `config/update.php`, `.git/`, `tests/`, `private/`, and `*.secret.key` files.

Local publication writes three files. Send them to the server, without the private key and without replacing `secrets.json`:

1. `storage/modules/mymodule/releases/<version>.zip`
2. `storage/modules/mymodule/releases/<version>.json`
3. `storage/modules/mymodule/manifest.json`, last

The server then does not advertise a version whose zip has not arrived yet. An older or already published version is refused. `--force` replaces the files of one version. Republishing an older version does not make it the offered version.

`publish-release.php` prints three lines: `Release`, `Fingerprint`, and `Package`. Error messages from these scripts are in English.

### Windows upload

`publish.bat` runs the build, the publication, and two `scp` calls. Edit the four variables at the top:

- `USER`: Windows account that owns the key
- `BASE`: parent directory of the repository and of the module sources. The repository directory must be named `update`
- `FTP_MAIN_USER`: `account@host` of the server. That account must exist and have a home directory: its public key is read from there
- `CHEMIN_VERS_STORAGE`: remote path of `storage/modules`, without a leading slash

```bat
publish.bat mymodule 1.2.0
```

The local private key is `C:\Users\%USER%\.ssh\id_ed25519`. In `cmd.exe`, write that path as it is: `$env:USERPROFILE` is not expanded. The matching public key must be one line of `~/.ssh/authorized_keys` on the server, with `~/.ssh` mode `700` and the file mode `600`.

The zip and the json are sent together to `releases/`. `manifest.json` is sent afterwards, on its own.

## Wiring the module

Export the client library, without the server:

```bash
php bin/export-client.php /path/to/module/updates
```

In the module’s main file:

```php
require_once dirname(__FILE__) . '/updates/bootstrap.php';
```

Copy `updates/config/update.php.example` to `config/update.php` at the module root, then fill in the HTTPS address, the identifier, the secret, and the public key. `allow_insecure_http` allows HTTP only for `localhost`, `127.0.0.1`, and `::1`.

In PrestaShop `getContent()`, after the usual form handling, and outside the module’s `<form>`:

```php
$this->_html .= \AiDev\ModuleUpdate\Client\PrestaShopUpdater::renderFromModule($this);
```

`getContent()` is available only to a logged-in employee. Do not expose another entry point.

For another CMS or a PHP script, the server is the same. Use `AiDev\ModuleUpdate\Client\UpdateClient` to read the manifest and download the zip, then `Installer` to apply it. `UpdatePanel` provides the same panel as under PrestaShop: pass it the client, the installer, the module directory, and the installed version, and call `handle()` only from an administration page that is already authenticated.

## Verification

```bash
php bin/self-test.php
```

This check creates two modules, publishes zips, starts a local server, and runs the flow: anonymous refusal, replay protection, isolation between modules, signature, confirmation, preservation of the local secret, backup, and rejection of a modified zip. It does not load PrestaShop.

## Accepted limits

- No license per shop, per domain, or per duration.
- The secret embedded in the module protects isolation and anonymous access, not confidentiality from someone who already has the module.
- The public key is changed by hand in `config/update.php`, because updates do not overwrite that file.
- The server does not check that the shop is allowed to use the module. It only checks that the shop presents that module’s secret.
- Under the host name `localhost`, the client does not verify the HTTPS certificate. Everywhere else, it does.
