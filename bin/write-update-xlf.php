<?php
declare(strict_types=1);
$pairs = [
    'Administration lock is unavailable.' => 'Verrou d’administration indisponible.',
    'The update could not be completed.' => 'La mise à jour n’a pas pu aboutir.',
    'Version %s is ready to be installed.' => 'La version %s est prête à être installée.',
    'Version %s is available.' => 'La version %s est disponible.',
    'The module is up to date (version %s).' => 'Le module est à jour (version %s).',
    'Installation confirmation is incomplete.' => 'Confirmation d’installation incomplète.',
    'The published version has changed. Check again.' => 'La version publiée a changé. Vérifiez à nouveau.',
    'This version is not newer than the installed version.' => 'Cette version n’est pas plus récente que la version installée.',
    'The files are in place, but the CMS could not update the registered version.' => 'Les fichiers sont en place, mais la version enregistrée par le CMS n’a pas pu être mise à jour.',
    'Version %s installed. Backup: %s. Reload the administration page.' => 'Version %s installée. Sauvegarde : %s. Rechargez la page d’administration.',
    'Not checked' => 'Non vérifiée',
    'Installed version:' => 'Version installée :',
    'Published version:' => 'Version publiée :',
    'No changelog.' => 'Aucun journal.',
    'Published on' => 'Publiée le',
    'SHA-256 fingerprint:' => 'Empreinte SHA-256 :',
    'Package:' => 'Paquet :',
    'Changelog' => 'Journal des modifications',
    'Check for updates' => 'Vérifier les mises à jour',
    'The module files will be replaced after a backup in var/backups. The existing config/update.php file and the var directory are kept.' => 'Les fichiers du module seront remplacés après une sauvegarde dans var/backups. Le fichier config/update.php et le dossier var déjà présents sont conservés.',
    'I confirm replacing the files with version %s' => 'Je confirme le remplacement des fichiers par la version %s',
    'Install version %s' => 'Installer la version %s',
    'Updates' => 'Mises à jour',
    'Confirmation could not be prepared.' => 'Confirmation impossible à préparer.',
    'The confirmation has expired or is invalid. Reload the page.' => 'Confirmation expirée ou invalide. Rechargez la page.',
    'Module directory not found.' => 'Dossier du module introuvable.',
    'State directory not found.' => 'Dossier d’état introuvable.',
    'Update configuration is missing.' => 'Configuration de mise à jour absente.',
    'Update configuration is unreadable.' => 'Configuration de mise à jour illisible.',
    'Update configuration is incomplete.' => 'La configuration de mise à jour est incomplète.',
    'PrestaShop could not update the registered version.' => 'La version enregistrée par PrestaShop n’a pas pu être mise à jour.',
    'Update public key is missing.' => 'Clé publique de mise à jour absente.',
    'Download failed.' => 'Téléchargement impossible.',
    'Manifest is too large.' => 'Manifeste trop volumineux.',
    'Manifest is unreadable.' => 'Manifeste illisible.',
    'Server clock is unreadable.' => 'Horloge du serveur illisible.',
    'The update server refused access.' => 'Accès refusé par le serveur de mises à jour.',
    'No release for this module.' => 'Aucune publication pour ce module.',
    'Too many checks. Try again in a few minutes.' => 'Trop de vérifications. Réessayez dans quelques minutes.',
    'The remote release is unavailable.' => 'La publication distante est indisponible.',
    'The manifest does not match this module.' => 'Le manifeste ne correspond pas à ce module.',
    'Invalid package address.' => 'Adresse du paquet invalide.',
    'The update signature is invalid.' => 'La signature de la mise à jour est invalide.',
    'The package fingerprint does not match.' => 'L’empreinte du paquet ne correspond pas.',
    'Update server address refused.' => 'Adresse du serveur de mises à jour refusée.',
    'Module secret is unreadable.' => 'Secret de module illisible.',
    'cURL is unavailable.' => 'cURL indisponible.',
    'Response is too large.' => 'Réponse trop volumineuse.',
    'cURL call failed.' => 'Appel cURL impossible.',
    'Redirect refused.' => 'Redirection refusée.',
    'Update address refused.' => 'Adresse de mise à jour refusée.',
    'The update address must use HTTPS.' => 'L’adresse de mise à jour doit utiliser HTTPS.',
    'Package not found.' => 'Paquet introuvable.',
    'The module directory does not match the manifest.' => 'Le dossier du module ne correspond pas au manifeste.',
    'Installation could not be prepared.' => 'Préparation de l’installation impossible.',
    'Installation stopped. The backup was restored (%s).' => 'Installation interrompue. La sauvegarde a été restaurée (%s).',
    'Backup failed.' => 'Sauvegarde impossible.',
    'Package is unreadable.' => 'Paquet illisible.',
    'Extraction failed.' => 'Extraction impossible.',
    'The package must contain the module directory or its files.' => 'Le paquet doit contenir le dossier du module ou ses fichiers.',
    'Symbolic link refused in the archive.' => 'Lien symbolique refusé dans l’archive.',
    'Copy refused.' => 'Copie refusée.',
    'Copy failed.' => 'Copie impossible.',
];
$xml = [];
$xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
$xml[] = '<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">';
$xml[] = '  <file source-language="en-US" target-language="fr-FR" datatype="plaintext" original="ModulesAiapigenerationpsUpdate">';
$xml[] = '    <body>';
foreach ($pairs as $source => $target) {
    $xml[] = '      <trans-unit id="' . sha1($source) . '" xml:space="preserve">';
    $xml[] = '        <source>' . htmlspecialchars($source, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</source>';
    $xml[] = '        <target>' . htmlspecialchars($target, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</target>';
    $xml[] = '      </trans-unit>';
}
$xml[] = '    </body>';
$xml[] = '  </file>';
$xml[] = '</xliff>';
$xml[] = '';
$xlf = dirname(__DIR__, 2) . '/aiapigenerationps-prod/translations/fr-FR/ModulesAiapigenerationpsUpdate.fr-FR.xlf';
file_put_contents($xlf, implode("\n", $xml));
$strings = dirname(__DIR__, 2) . '/aiapigenerationps-prod/includes/strings.php';
$current = (string) file_get_contents($strings);
$extra = '';
foreach (array_keys($pairs) as $source) {
    $line = 'Context::getContext()->getTranslator()->trans("' . $source . '", [], "Modules.Aiapigenerationps.Update");' . "\n";
    if (!str_contains($current, $line) && !str_contains($extra, $line)) {
        $extra .= $line;
    }
}
if ($extra !== '') {
    file_put_contents($strings, rtrim($current) . "\n" . $extra);
}
echo 'units=' . count($pairs) . ' sha1-check=' . (sha1('Available credits') === '18001da8d15dc257d0536ba486f2b5d8401f3591' ? 'ok' : 'mismatch') . PHP_EOL;