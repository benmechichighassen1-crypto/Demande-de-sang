@echo off
rem ============================================================
rem  Reparation de l erreur 403 du site DemandesSang (IIS).
rem  A la racine de l appli (C:\Application\demandesang-app).
rem  Clic droit > Executer en tant qu administrateur.
rem  Le site Oncologie n est pas modifie.
rem ============================================================
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0deploiement\corriger-403.ps1"
echo.
pause
