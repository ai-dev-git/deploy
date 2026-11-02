@echo off
setlocal EnableExtensions

rem Chemins fixes. CHEMIN_VERS_STORAGE est le dossier distant storage/modules, sans slash au debut.
set "USER=ai-dev"
set "BASE=G:\Dev\wamp64\www\ia"
set "FTP_MAIN_USER=sc1hapa9901@update.ai-core.fr"
set "CHEMIN_VERS_STORAGE=home/sc1hapa9901/chemin/storage/modules"

if "%~1"=="" goto :usage
if "%~2"=="" goto :usage

set "MODULE=%~1"
set "VERSION=%~2"
set "UPDATE=%BASE%\update"
set "SOURCE=%BASE%\%MODULE%"
set "ZIP=%BASE%\%MODULE%-%VERSION%.zip"
set "CHANGELOG=%SOURCE%\docs\changelogs\%VERSION%.txt"
set "KEY=C:\Users\%USER%\.ssh\id_ed25519"
set "RELEASE=%UPDATE%\storage\modules\%MODULE%\releases"
set "REMOTE=%FTP_MAIN_USER%:/%CHEMIN_VERS_STORAGE%/%MODULE%"

cd /d "%UPDATE%"
if errorlevel 1 (
    echo Dossier introuvable : %UPDATE%
    exit /b 1
)

php bin\build-zip.php "%SOURCE%" "%ZIP%"
if errorlevel 1 exit /b 1

php bin\publish-release.php "%MODULE%" "%VERSION%" "%ZIP%" --changelog-file="%CHANGELOG%"
if errorlevel 1 exit /b 1

scp -i "%KEY%" "%RELEASE%\%VERSION%.zip" "%RELEASE%\%VERSION%.json" "%REMOTE%/releases/"
if errorlevel 1 exit /b 1

scp -i "%KEY%" "%UPDATE%\storage\modules\%MODULE%\manifest.json" "%REMOTE%/manifest.json"
if errorlevel 1 exit /b 1

echo Publication envoyee : %MODULE% %VERSION%
exit /b 0

:usage
echo Usage : %~nx0 module version
exit /b 1
