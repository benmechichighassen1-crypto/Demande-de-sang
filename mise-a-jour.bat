@echo off
rem ============================================================
rem  Mise a jour de l application Demandes de sang
rem  A lancer (double-clic) apres chaque livraison de code
rem  (zip maj-demandesang-*.zip decompresse DANS le dossier de l appli,
rem   C:\Application\demandesang-app, en remplacant les fichiers) :
rem   1. ajoute les nouvelles tables / colonnes dans demandesang_app
rem   2. reconstruit les caches de l application (plus rapide)
rem  Rien n est jamais ecrit dans Clinisys.
rem ============================================================
cd /d "%~dp0"
set PHP=php
if exist "D:\Outils\php\php.exe" set PHP=D:\Outils\php\php.exe
rem Serveur IIS : PHP de l appli (C:\Application\php-demandesang ou a cote du dossier)
if exist "%~dp0..\php-demandesang\php.exe" set PHP=%~dp0..\php-demandesang\php.exe
if exist "C:\Application\php-demandesang\php.exe" set PHP=C:\Application\php-demandesang\php.exe

echo.
echo [1/2] Base demandesang_app : nouvelles tables et colonnes...
"%PHP%" artisan migrate --force || goto erreur
echo N CIN des patients repris de Clinisys (lecture seule)...
"%PHP%" artisan ds:cin

echo.
echo [2/2] Caches de l application...
"%PHP%" artisan optimize:clear >nul
"%PHP%" artisan optimize || goto erreur

echo.
echo Mise a jour terminee. Rechargez les pages dans le navigateur (Ctrl+F5).
pause
exit /b 0

:erreur
echo.
echo ERREUR pendant la mise a jour : envoyez une capture de cette fenetre.
pause
exit /b 1
