# Mises à jour signées des modules

English version: [README.en.md](README.en.md).

Service PHP pour publier et installer les mises à jour de plusieurs modules sur un hébergement classique. Il n’y a pas de gestion de licences : chaque module a un identifiant et un secret, et une paire de clés qui lui est propre. Le même client sert à PrestaShop, à un autre CMS ou à un script PHP.

Le serveur ne fait que déposer et délivrer les fichiers. Le module installé décide seul si le paquet est authentique, parce qu’il vérifie une signature avec la clé publique qu’il contient déjà.

## Deux rôles distincts

| Rôle | Secret qu’il détient | Ce qu’il garantit |
| --- | --- | --- |
| Identifiant + secret du module | Les deux bouts, le même pour toutes les boutiques qui ont ce module | Une boutique ne peut pas lire le dossier d’un autre module, et un visiteur anonyme non plus |
| Clé privée Ed25519 | Uniquement la machine qui publie | Personne d’autre ne peut fabriquer une mise à jour acceptée |
| Clé publique Ed25519 | Chaque copie du module, dans `config/update.php` | Le module refuse un zip ou un journal modifié |

Le secret n’est pas une licence. Quiconque possède déjà le module peut en extraire le secret et télécharger les zips de ce module. C’est voulu. En revanche, ce secret ne donne accès à aucun autre module, et il ne permet pas de signer une fausse mise à jour.

La clé privée ne doit jamais être déposée sur le serveur web. Un serveur compromis peut couper le service ou laisser fuir les zips déjà publiés. Il ne peut pas faire accepter un zip modifié par les modules déjà installés.

## Arborescence

```
module-updates/
  public/                  racine web
    manifest.php           dernière version, après authentification
    package.php            zip d’une version, après authentification
  storage/                 hors de la racine web
    secrets.json           identifiant → secret
    keys/*.public.key      clés publiques, une par module
    modules/<module>/
      manifest.json        version courante
      releases/<version>.zip
      releases/<version>.json
  private/                 machine de publication seulement
    <module>.secret.key
  publish.bat              exemple Windows : construire, publier, envoyer
```

Le document root du vhost doit être `public/`. Les URL sont alors :

- `https://updates.exemple.fr/manifest.php`
- `https://updates.exemple.fr/package.php?version=1.2.0`

`storage/` et `private/` portent un `.htaccess` qui en interdit l’accès HTTP. Ce n’est qu’un filet : ces dossiers ne doivent pas être sous la racine web. Il n’y a pas d’URL pour déposer un zip. La publication se fait en ligne de commande, puis par copie des fichiers.

`config/update.php` ne vit pas sur ce serveur. Il est dans chaque module installé, sur la boutique ou le script qui reçoit les mises à jour. Les classes du panneau (`UpdatePanel`, `PrestaShopUpdater`) sont elles aussi dans le module, sous `updates/`, pas sur le serveur de dépôts.

## Le fichier de version

`manifest.json` et `releases/<version>.json` contiennent la même fiche :

- `version`
- `changelog`
- `package_url` : adresse relative du zip, toujours `package.php?version=<version>`
- `sha256` : empreinte du zip
- `signature` : signature Ed25519
- `filename`, `released_at`, `module_id`, `signature_alg`

Le journal est un texte UTF-8 libre. On peut y mêler plusieurs langues : le client l’affiche tel quel, sans en choisir une.

L’adresse est relative pour qu’un manifeste ne puisse pas envoyer le module télécharger un zip sur un autre domaine. L’URL complète est l’adresse configurée dans le module, suivie de cette adresse relative. Le client vérifie que l’hôte, le port et le chemin restent ceux qu’il connaît, et il ne suit aucune redirection.

La signature ne porte pas sur le JSON brut, ni sur l’empreinte seule. Signer seulement l’empreinte laisserait un attaquant changer la version ou le journal tout en gardant une signature valide. La charge signée est ce texte UTF-8, une ligne par champ, fin de ligne LF, y compris après la dernière ligne :

```
module-update-v1
alg=ed25519
module_id=<identifiant>
version=<version>
released_at=<date UTC>
filename=<identifiant>-<version>.zip
sha256=<empreinte du zip>
package_url=package.php?version=<version>
changelog_sha256=<empreinte SHA-256 du journal>
```

Le journal peut contenir des retours à la ligne : ils n’entrent pas dans ce texte, seule leur empreinte y entre. Le module recalcule cette charge à partir des champs reçus, puis vérifie la signature avec sa clé publique. Il recalcule aussi l’empreinte du zip après téléchargement.

## Authentification de l’appel

Chaque appel est un GET cURL. Le secret ne voyage pas. Le module envoie un jeton dans `Authorization: ModuleUpdate …` et le même jeton dans `X-Module-Token` (certains hébergements retirent `Authorization`).

Le jeton est `MU1.<identifiant>.<horodatage>.<nonce>.<hmac>`. Le HMAC-SHA256 est calculé avec le secret du module sur :

```
MU1
GET
manifest.php
<horodatage unix, 10 chiffres>
<nonce de 16 octets en hexadécimal>
<empreinte SHA-256 d’un corps vide>
<requête canonique>
```

Pour le manifeste, la requête canonique est vide. Pour le zip, elle vaut `version=<version>`. L’horodatage est refusé au-delà de cinq minutes. Chaque nonce n’est accepté qu’une fois. Le serveur choisit le dossier uniquement à partir de l’identifiant authentifié : aucun paramètre d’URL ne permet de viser le dossier d’un autre module. Identifiant inconnu et secret faux produisent la même réponse.

Soixante appels par identifiant et par adresse IP sont acceptés sur dix minutes. L’horloge de la boutique doit être à peu près juste.

Un appel sans jeton sur `manifest.php` répond 401. C’est le signe que le serveur et le certificat répondent. Le certificat lui-même n’est pas en cause.

## Administration du module

Le panneau s’affiche dans l’administration déjà authentifiée du module. Il n’existe pas de page publique d’installation. À l’ouverture, le panneau interroge `manifest.php`, vérifie la signature et affiche la version publiée et le journal. Le bouton « Check for updates » n’apparaît que si cet appel échoue.

S’il existe une version plus récente, le journal est affiché en texte (le HTML est échappé), avec la version et l’empreinte. L’installation exige une case cochée et un jeton à usage unique lié à cette version précise. La case est contrôlée par le serveur au moment de l’installation, pour ne pas bloquer l’enregistrement des autres formulaires de la page. Si une autre version a été publiée entre-temps, l’installation s’arrête.

Le module retélécharge alors la fiche, retélécharge le zip, revérifie la signature et l’empreinte, puis remplace les fichiers.

Avant le remplacement, une sauvegarde est écrite dans `var/backups/`. Le dossier `var/` et le fichier `config/update.php` déjà présents ne sont pas écrasés : une mise à jour ne peut donc pas remplacer la clé publique épinglée. Les chemins `..`, les liens symboliques et les archives trop dilatées sont refusés. Si la copie échoue, la sauvegarde est restaurée.

Les textes du client sont en anglais. Sous PrestaShop, `PrestaShopUpdater` les passe au traducteur du module (`translate($texte, 'Update')`, ou `trans()` à défaut), sur le domaine `Modules.{NomDuModule}.Update`. Sans traducteur, l’anglais est affiché.

### PrestaShop

La liste des modules affiche la version enregistrée dans la table `ps_module`, pas celle des fichiers. À l’ouverture de la configuration du module, cette version est alignée sur les fichiers et le cache du gestionnaire de modules est vidé. La classe PHP déjà chargée garde l’ancien numéro pendant la requête d’installation : rechargez la page ensuite.

`PrestaShopUpdater::newerRelease($module)` renvoie la version publiée si elle est strictement plus récente, sinon `null`. Le module peut s’en servir pour un bandeau sur le gestionnaire de modules et en haut de sa propre configuration. Un succès est conservé quinze minutes dans `var/update/notice.json`, un échec deux minutes. Pendant ces deux minutes le bandeau reste vide : supprimer ce fichier force un nouvel appel.

Le HTML du panneau contient son propre formulaire. Il faut le placer en dehors du formulaire de configuration du module.

PHP cURL vérifie le certificat HTTPS. Si `curl.cainfo` et `openssl.cafile` sont vides, l’appel échoue avant toute comparaison de version. Indiquez un fichier de certificats dans le `php.ini` qui sert l’administration, puis redémarrez le serveur web. Lorsque la boutique est servie sous le nom `localhost`, cette vérification est suspendue, comme pour les autres appels sortants du module. Sur tout autre nom d’hôte, elle reste active. La signature et l’empreinte du paquet sont contrôlées dans tous les cas.

## Mise en place du serveur

PHP 8.1 ou plus, avec `sodium`, `curl`, `zip` et `hash`. `curl` n’est requis que dans les boutiques. Le serveur a besoin de `sodium`.

Les clés se génèrent sur la machine qui publie. L’enregistrement du module se fait sur le serveur, là où `storage/secrets.json` doit vivre.

```bash
# sur la machine qui publie
php bin/generate-keys.php monmodule

# storage/keys/monmodule.public.key part sur le serveur
# private/monmodule.secret.key reste sur place

# sur le serveur
php bin/register-module.php monmodule
```

`register-module.php` affiche le secret une seule fois. Il faut le copier tout de suite dans le `config/update.php` du module, sur la boutique. Pour publier ensuite depuis la machine locale, copier le `storage/secrets.json` du serveur vers la machine qui publie. Un second `register-module.php` en local produirait un autre secret, et le serveur continuerait d’utiliser le sien.

Si `secrets.json` existe mais est vide ou illisible, le script ne peut pas enregistrer le module. Supprimer ce fichier, puis relancer l’enregistrement.

Configurer le vhost :

- document root : `.../module-updates/public`
- HTTPS
- PHP exécute `manifest.php` et `package.php`

Copier `config/server.example.php` vers `config/server.php` seulement si les limites par défaut ne conviennent pas (taille max 50 Mo, fenêtre d’horloge 300 secondes).

## Publier une version

Le journal attendu par `publish.bat` est `docs/changelogs/<version>.txt` dans les sources du module. Le script PHP accepte n’importe quel fichier UTF-8 :

```bash
php bin/build-zip.php /chemin/du/module /tmp/monmodule-1.2.0.zip
php bin/publish-release.php monmodule 1.2.0 /tmp/monmodule-1.2.0.zip --changelog-file=/chemin/du/module/docs/changelogs/1.2.0.txt
```

`build-zip.php` place le dossier du module à la racine de l’archive (`monmodule/…`). Ce nom doit être l’identifiant du module. Le script exclut `var/`, `config/update.php`, `.git/`, `tests/`, `private/` et les fichiers `*.secret.key`.

La publication locale écrit trois fichiers. Les envoyer sur le serveur, sans la clé privée et sans remplacer `secrets.json` :

1. `storage/modules/monmodule/releases/<version>.zip`
2. `storage/modules/monmodule/releases/<version>.json`
3. `storage/modules/monmodule/manifest.json`, en dernier

Ainsi le serveur ne propose pas une version dont le zip n’est pas encore arrivé. Une version plus ancienne ou déjà publiée est refusée. `--force` permet de remplacer les fichiers d’une version. Une republication plus ancienne ne devient pas la version proposée.

`publish-release.php` affiche trois lignes : `Release`, `Fingerprint` et `Package`. Les messages d’erreur de ces scripts sont en anglais.

### Envoi Windows

`publish.bat` enchaîne la construction, la publication et deux `scp`. Les quatre variables du début sont à adapter :

- `USER` : compte Windows qui possède la clé
- `BASE` : dossier parent du dépôt et des sources du module. Le dépôt doit s’appeler `update`
- `FTP_MAIN_USER` : `compte@hôte` du serveur. Ce compte doit exister et avoir un répertoire personnel : la clé publique y est lue
- `CHEMIN_VERS_STORAGE` : chemin distant de `storage/modules`, sans slash au début

```bat
publish.bat monmodule 1.2.0
```

La clé privée locale est `C:\Users\%USER%\.ssh\id_ed25519`. Dans `cmd.exe`, écrire ce chemin tel quel : `$env:USERPROFILE` n’est pas développé. La clé publique correspondante doit être une ligne de `~/.ssh/authorized_keys` sur le serveur, avec `~/.ssh` en `700` et le fichier en `600`.

Le zip et le json partent ensemble vers `releases/`. `manifest.json` part ensuite, seul.

## Brancher le module

Exporter la bibliothèque cliente, sans le serveur :

```bash
php bin/export-client.php /chemin/du/module/updates
```

Dans le fichier principal du module :

```php
require_once dirname(__FILE__) . '/updates/bootstrap.php';
```

Copier `updates/config/update.php.example` vers `config/update.php` à la racine du module, puis renseigner l’adresse HTTPS, l’identifiant, le secret et la clé publique. `allow_insecure_http` n’accepte HTTP que pour `localhost`, `127.0.0.1` et `::1`.

Dans `getContent()` de PrestaShop, après le traitement habituel du formulaire, et hors de la balise `<form>` du module :

```php
$this->_html .= \AiDev\ModuleUpdate\Client\PrestaShopUpdater::renderFromModule($this);
```

`getContent()` n’est accessible qu’à un employé connecté. Ne pas exposer d’autre entrée.

Pour un autre CMS ou un script PHP, le serveur est le même. Instancier `AiDev\ModuleUpdate\Client\UpdateClient` pour lire le manifeste et télécharger le zip, puis `Installer` pour le poser. `UpdatePanel` fournit le même panneau que sous PrestaShop : le passer au client, à l’installeur, au dossier du module et à la version installée, et n’appeler `handle()` que depuis une page d’administration déjà authentifiée.

## Vérification

```bash
php bin/self-test.php
```

Ce contrôle génère deux modules, publie des zips, démarre un serveur local et joue le parcours : refus anonyme, anti-rejeu, cloisonnement entre modules, signature, confirmation, conservation du secret local, sauvegarde et rejet d’un zip modifié. Il ne charge pas PrestaShop.

## Limites assumées

- Pas de licence par boutique, par domaine ou par durée.
- Le secret embarqué dans le module protège le cloisonnement et l’accès anonyme, pas la confidentialité vis-à-vis de quelqu’un qui a déjà le module.
- Le changement de clé publique se fait à la main dans `config/update.php`, parce que ce fichier n’est pas écrasé par les mises à jour.
- Le serveur ne vérifie pas que la boutique a le droit d’exploiter le module. Il vérifie seulement qu’elle présente le secret de ce module.
- Sous le nom d’hôte `localhost`, le client ne vérifie pas le certificat HTTPS. Ailleurs, il le vérifie.
