@echo off
rem ============================================================
rem  Installation de l'application Demandes de sang sur IIS.
rem  Clic droit > Executer en tant qu'administrateur.
rem  Ce fichier doit etre a cote des dossiers demandesang-app et
rem  php-demandesang (meme methode que l'appli Oncologie).
rem  Le site Oncologie n'est pas modifie.
rem ============================================================
set ICI=%~dp0
powershell -NoProfile -ExecutionPolicy Bypass -File "%ICI%demandesang-app\deploiement\installer-iis.ps1" -Appli "%ICI%demandesang-app" -Php "%ICI%php-demandesang"
echo.
pause
