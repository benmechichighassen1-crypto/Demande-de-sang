<#
  Installation de l'application Demandes de sang sur IIS (même serveur et même méthode que l'appli Oncologie).
  Lancé par INSTALLER.bat (clic droit > Exécuter en tant qu'administrateur).

  Le paquet contient l'application et son propre PHP 8.3 (pilotes SQL Server et OPcache
  inclus) : le PHP des autres sites (Oncologie, ExtrasBloc…) n'est pas modifié.
  Le script : vérifie IIS, FastCGI, URL Rewrite et le pilote ODBC ; règle le php.ini
  de l'appli ; crée le pool et le site ; donne les droits d'écriture ; ouvre le port ;
  prépare le .env de production. Pas de tâche planifiée : les étapes de la pharmacie sont lues
  dans Clinisys à l'ouverture des pages. Rien n'est écrit dans Clinisys ; le site Oncologie n'est pas touché.
#>
param(
    [string]$Appli = 'C:\Application\demandesang-app',
    [string]$Php = 'C:\Application\php-demandesang',
    [int]$Port = 0,
    [string]$Site = 'DemandesSang'
)
$ErrorActionPreference = 'Stop'
function Etape($t) { Write-Host "`n=== $t" -ForegroundColor Cyan }
function Manque($t) { Write-Host "  A INSTALLER : $t" -ForegroundColor Yellow; $script:manques++ }
$manques = 0

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Lancez INSTALLER.bat en administrateur (clic droit > Exécuter en tant qu''administrateur).'
}
foreach ($p in "$Appli\artisan", "$Appli\vendor\autoload.php", "$Php\php-cgi.exe") {
    if (-not (Test-Path $p)) { throw "Fichier introuvable : $p (décompressez le paquet dans C:\Application)." }
}

Etape 'IIS et FastCGI'
if (Get-Command Install-WindowsFeature -ErrorAction SilentlyContinue) {
    Install-WindowsFeature Web-Server, Web-CGI, Web-Mgmt-Console | Out-Null
} else {
    Enable-WindowsOptionalFeature -Online -All -NoRestart -FeatureName IIS-WebServerRole, IIS-CGI, IIS-ManagementConsole | Out-Null
}
Write-Host '  IIS + CGI/FastCGI actifs.'

Etape 'Composants nécessaires'
if (-not (Test-Path 'HKLM:\SOFTWARE\Microsoft\IIS Extensions\URL Rewrite')) {
    Manque 'IIS URL Rewrite 2.1 : https://www.iis.net/downloads/microsoft/url-rewrite'
}
$odbc = 'ODBC Driver 18 for SQL Server', 'ODBC Driver 17 for SQL Server' | Where-Object { Test-Path "HKLM:\SOFTWARE\ODBC\ODBCINST.INI\$_" } | Select-Object -First 1
if (-not $odbc) { Manque 'Microsoft ODBC Driver 18 for SQL Server (x64)' } else { Write-Host "  $odbc présent." }

# php.ini réglé AVANT de tester PHP : le chemin des extensions doit être absolu.
$ini = "$Php\php.ini"
if (-not (Test-Path "$ini.origine")) { Copy-Item $ini "$ini.origine" }
$t = [IO.File]::ReadAllText($ini)
# Chemin absolu : sous IIS, le dossier courant n'est pas celui de PHP.
$t = $t -replace '(?m)^\s*extension_dir\s*=.*$', "extension_dir = `"$Php\ext`""
foreach ($r in @{ 'cgi.force_redirect' = '0'; 'cgi.fix_pathinfo' = '1'; 'fastcgi.impersonate' = '1'; 'fastcgi.logging' = '0';
                  'upload_max_filesize' = '50M'; 'post_max_size' = '50M'; 'max_execution_time' = '120' }.GetEnumerator()) {
    if ($t -match "(?m)^\s*;?\s*$([regex]::Escape($r.Key))\s*=") {
        $t = $t -replace "(?m)^\s*;?\s*$([regex]::Escape($r.Key))\s*=.*$", "$($r.Key) = $($r.Value)"
    } else { $t += "`r`n$($r.Key) = $($r.Value)" }
}
[IO.File]::WriteAllText($ini, $t, (New-Object Text.ASCIIEncoding))
Write-Host "  $ini réglé."

# Test de PHP : on lit toute la sortie (avertissements compris) avant de regarder le code retour.
$ErrorActionPreference = 'Continue'
$sortie = (& "$Php\php.exe" -n -v 2>&1 | Out-String).Trim()
$codePhp = $LASTEXITCODE
$modules = (& "$Php\php.exe" -m 2>&1 | Out-String)
$ErrorActionPreference = 'Stop'
$vcRegistre = (Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64' -ErrorAction SilentlyContinue).Installed -eq 1
if ($codePhp -ne 0 -or $sortie -notmatch '^PHP \d') {
    Write-Host "  PHP ne démarre pas (code $codePhp) : $sortie" -ForegroundColor Yellow
    if (-not $vcRegistre) { Manque 'Visual C++ Redistributable 2015-2022 x64 : https://aka.ms/vs/17/release/vc_redist.x64.exe' }
    else { Manque 'PHP du paquet : envoyez une capture de ce message.' }
} else {
    Write-Host "  $(($sortie -split "`n")[0])"
    if ($modules -notmatch 'pdo_sqlsrv') { Write-Host $modules; Manque 'pilote PHP pdo_sqlsrv non chargé : envoyez une capture de ce message.' }
}
if ($manques -gt 0) { throw "$manques composant(s) à installer, puis relancez INSTALLER.bat." }

Etape 'FastCGI'
Import-Module WebAdministration
$cgi = "$Php\php-cgi.exe"
$appcmd = "$env:windir\system32\inetsrv\appcmd.exe"
if (-not (Get-WebConfiguration "/system.webServer/fastCgi/application[@fullPath='$cgi']")) {
    & $appcmd set config /section:system.webServer/fastCgi "/+[fullPath='$cgi',maxInstances='8',instanceMaxRequests='10000',activityTimeout='600',requestTimeout='600']" | Out-Null
    & $appcmd set config /section:system.webServer/fastCgi "/+[fullPath='$cgi'].environmentVariables.[name='PHP_FCGI_MAX_REQUESTS',value='10000']" | Out-Null
}
Write-Host "  $cgi déclaré (8 processus PHP en parallèle)."

Etape 'Port'
$utilises = Get-WebBinding | ForEach-Object { ($_.bindingInformation -split ':')[1] }
if ($Port -eq 0) {
    $existant = Get-WebBinding -Name $Site -ErrorAction SilentlyContinue | Select-Object -First 1
    $Port = if ($existant) { [int](($existant.bindingInformation -split ':')[1]) } else { (8090..8199 | Where-Object { "$_" -notin $utilises } | Select-Object -First 1) }
}
Write-Host "  Port retenu : $Port"

Etape "Pool et site $Site"
if (-not (Test-Path "IIS:\AppPools\$Site")) { New-WebAppPool $Site | Out-Null }
Set-ItemProperty "IIS:\AppPools\$Site" managedRuntimeVersion ''
Set-ItemProperty "IIS:\AppPools\$Site" processModel.idleTimeout ([TimeSpan]::Zero)
if (-not (Test-Path "IIS:\Sites\$Site")) {
    New-Website -Name $Site -Port $Port -PhysicalPath "$Appli\public" -ApplicationPool $Site | Out-Null
} else {
    Set-ItemProperty "IIS:\Sites\$Site" physicalPath "$Appli\public"
}
# Handler au niveau du serveur (applicationHost) : il reste en place quand une mise à jour remplace publicweb.config.
& $appcmd set config $Site /section:system.webServer/handlers "/-[name='PHP_DemandesSang']" 2>$null | Out-Null
& $appcmd set config $Site /section:system.webServer/handlers "/-[name='PHP_DemandesSang']" /commit:apphost 2>$null | Out-Null
& $appcmd set config $Site /section:system.webServer/handlers "/+[name='PHP_DemandesSang',path='*.php',verb='*',modules='FastCgiModule',scriptProcessor='$cgi',resourceType='Either']" /commit:apphost | Out-Null
Write-Host "  Site $Site -> $Appli\public"

Etape 'Droits'
$compte = "IIS AppPool\$Site"
icacls $Appli /grant "${compte}:(OI)(CI)RX" /T /Q | Out-Null
icacls "$Appli\storage" /grant "${compte}:(OI)(CI)M" /T /Q | Out-Null
icacls "$Appli\bootstrap\cache" /grant "${compte}:(OI)(CI)M" /T /Q | Out-Null
icacls $Php /grant "${compte}:(OI)(CI)RX" /T /Q | Out-Null
Write-Host '  Lecture sur l''appli et son PHP, écriture sur storage et bootstrap\cache.'

Etape 'Pare-feu'
if (-not (Get-NetFirewallRule -DisplayName "DemandesSang $Port" -ErrorAction SilentlyContinue)) {
    New-NetFirewallRule -DisplayName "DemandesSang $Port" -Direction Inbound -Protocol TCP -LocalPort $Port -Action Allow -Profile Domain, Private | Out-Null
}
Write-Host "  Port $Port ouvert pour le réseau de la clinique."

Etape 'Fichier .env (production)'
$fichierEnv = "$Appli\.env"
$e = [IO.File]::ReadAllText($fichierEnv)
foreach ($r in @{ 'APP_ENV' = 'production'; 'APP_DEBUG' = 'false'; 'LOG_LEVEL' = 'warning'; 'APP_URL' = "http://$($env:COMPUTERNAME):$Port" }.GetEnumerator()) {
    if ($e -match "(?m)^$($r.Key)=") { $e = $e -replace "(?m)^$($r.Key)=.*$", "$($r.Key)=$($r.Value)" } else { $e += "`r`n$($r.Key)=$($r.Value)" }
}
[IO.File]::WriteAllText($fichierEnv, $e, (New-Object Text.UTF8Encoding $false))
Write-Host '  Mode production : les erreurs ne montrent plus de détails techniques aux utilisateurs.'

Etape 'Préparation de l''application'
# Pas de tâche planifiée (lecture de Clinisys à l'ouverture des pages, au plus une fois par minute).
$php = "$Php\php.exe"
Push-Location $Appli
foreach ($d in 'storage\framework\cache\data', 'storage\framework\sessions', 'storage\framework\views', 'storage\logs', 'storage\app') {
    New-Item -ItemType Directory -Force "$Appli\$d" | Out-Null
}
if ([IO.File]::ReadAllText($fichierEnv) -notmatch '(?m)^APP_KEY=base64:') { & $php artisan key:generate --force }
& $php artisan migrate --force
& $php artisan optimize:clear | Out-Null
& $php artisan optimize
Pop-Location
Restart-WebAppPool $Site
Start-Website $Site -ErrorAction SilentlyContinue

Etape 'Vérification'
try {
    $r = Invoke-WebRequest "http://localhost:$Port/connexion" -UseBasicParsing -TimeoutSec 60
    Write-Host "  Page de connexion : HTTP $($r.StatusCode)" -ForegroundColor Green
} catch { Write-Host "  La page ne répond pas encore : $($_.Exception.Message)" -ForegroundColor Yellow }

Write-Host "`nTerminé. Adresse pour les postes de la clinique : http://$($env:COMPUTERNAME):$Port  (ou http://192.168.1.227:$Port)" -ForegroundColor Green
