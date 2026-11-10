<#
  Répare l'erreur « 403 - Forbidden » du site IIS DemandesSang (serveur 192.168.1.227).
  Cause : public\web.config absent du premier paquet (pas de document par défaut index.php ni de réécriture vers
  Laravel). Le script vérifie et répare, puis affiche un diagnostic. Ne touche qu'au site DemandesSang
  (le site Oncologie n'est pas modifié). Il n'écrit jamais un chemin qui n'existe pas.
  Lancé par CORRIGER-403.bat, à la racine de l'appli (clic droit > Exécuter en tant qu'administrateur).
  Racine de l'appli : le dossier qui contient artisan et public\index.php (parent de ce script par défaut).
  PHP : C:\Application\php-demandesang, puis <racine>\..\php-demandesang, puis <racine>\php-demandesang.
#>
param(
    [string]$Appli = '',
    [string]$Php = '',
    [string]$Site = 'DemandesSang'
)
$ErrorActionPreference = 'Stop'
function Etape($t) { Write-Host "`n=== $t" -ForegroundColor Cyan }
function Ok($t) { Write-Host "  OK      $t" -ForegroundColor Green }
function Repare($t) { Write-Host "  REPARE  $t" -ForegroundColor Yellow }
function Ko($t) { Write-Host "  PROBLEME $t" -ForegroundColor Red }
function Arret($t) { Ko $t; Write-Host "`nRien n'a été modifié pour ce point. Envoyez une capture de cette fenêtre." -ForegroundColor Yellow; exit 1 }

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Lancez CORRIGER-403.bat en administrateur (clic droit > Exécuter en tant qu''administrateur).'
}
Import-Module WebAdministration
$appcmd = "$env:windir\system32\inetsrv\appcmd.exe"
if (-not (Test-Path "IIS:\Sites\$Site")) { Arret "Site IIS $Site introuvable : relancez INSTALLER.bat." }

Etape 'Dossiers de l''appli et de son PHP'
$estAppli = { param($d) $d -and (Test-Path "$d\artisan") -and (Test-Path "$d\public\index.php") -and (Test-Path "$d\vendor\autoload.php") }
if (-not $Appli) { $Appli = @((Split-Path $PSScriptRoot -Parent), 'C:\Application\demandesang-app') | Where-Object { & $estAppli $_ } | Select-Object -First 1 }
if (-not (& $estAppli $Appli)) { Arret "dossier de l'appli introuvable (artisan + public\index.php + vendor) : $Appli" }
$Appli = (Resolve-Path $Appli).Path.TrimEnd('\')
if (-not $Php) {
    $Php = @('C:\Application\php-demandesang', (Join-Path (Split-Path $Appli -Parent) 'php-demandesang'), "$Appli\php-demandesang") |
        Where-Object { Test-Path "$_\php-cgi.exe" } | Select-Object -First 1
}
if (-not $Php -or -not (Test-Path "$Php\php-cgi.exe")) { Arret 'php-demandesang\php-cgi.exe introuvable (cherché dans C:\Application\php-demandesang et à côté de l''appli). Relancez avec -Php <dossier>.' }
$Php = (Resolve-Path $Php).Path.TrimEnd('\')
$cgi = "$Php\php-cgi.exe"
$public = "$Appli\public"
Ok "appli : $Appli"
Ok "PHP   : $Php"

Etape 'Chemin physique du site'
$chemin = (Get-ItemProperty "IIS:\Sites\$Site").physicalPath
if ($chemin -ne $public) { Set-ItemProperty "IIS:\Sites\$Site" physicalPath $public; Repare "$chemin -> $public" } else { Ok $public }

Etape 'web.config (document par défaut index.php + réécriture vers Laravel)'
$wc = "$public\web.config"
$modele = "$PSScriptRoot\web.config"
$contenu = if (Test-Path $wc) { [IO.File]::ReadAllText($wc) } else { '' }
if ($contenu -notmatch 'index\.php' -or $contenu -notmatch '<rewrite>') {
    if (-not (Test-Path $modele)) { Arret "modèle $modele introuvable" }
    Copy-Item $modele $wc -Force; Repare "$wc remplacé par le modèle Laravel"
} else { Ok "$wc correct" }
if (-not (Test-Path 'HKLM:\SOFTWARE\Microsoft\IIS Extensions\URL Rewrite')) { Ko 'IIS URL Rewrite absent (nécessaire, comme pour l''Oncologie)' } else { Ok 'URL Rewrite installé' }

Etape 'Gestionnaire PHP (FastCGI) du site'
# Nettoyage : entrées FastCGI « demandesang » vers un php-cgi.exe qui n'existe pas (laissées par l'ancienne version du script).
foreach ($a in @(Get-WebConfiguration '/system.webServer/fastCgi/application')) {
    if ($a.fullPath -match 'demandesang' -and $a.fullPath -ne $cgi -and -not (Test-Path $a.fullPath)) {
        & $appcmd set config /section:system.webServer/fastCgi "/-[fullPath='$($a.fullPath)']" | Out-Null
        Repare "entrée FastCGI erronée supprimée : $($a.fullPath)"
    }
}
if (-not (Get-WebConfiguration "/system.webServer/fastCgi/application[@fullPath='$cgi']")) {
    & $appcmd set config /section:system.webServer/fastCgi "/+[fullPath='$cgi',maxInstances='8',instanceMaxRequests='10000',activityTimeout='600',requestTimeout='600']" | Out-Null
    Repare "FastCGI $cgi déclaré"
} else { Ok "FastCGI $cgi déclaré" }
# Handler au niveau du serveur (applicationHost) : il reste en place quand une mise à jour remplace publicweb.config.
& $appcmd set config $Site /section:system.webServer/handlers "/-[name='PHP_DemandesSang']" 2>$null | Out-Null
& $appcmd set config $Site /section:system.webServer/handlers "/-[name='PHP_DemandesSang']" /commit:apphost 2>$null | Out-Null
& $appcmd set config $Site /section:system.webServer/handlers "/+[name='PHP_DemandesSang',path='*.php',verb='*',modules='FastCgiModule',scriptProcessor='$cgi',resourceType='Either']" /commit:apphost | Out-Null
Ok "handler PHP_DemandesSang -> $cgi"

Etape 'Authentification anonyme (identité du pool)'
& $appcmd set config $Site /section:system.webServer/security/authentication/anonymousAuthentication /enabled:true /userName:"" /commit:apphost | Out-Null
Ok 'anonyme activée, identité = pool d''applications'

Etape "Pool $Site"
$pool = (Get-ItemProperty "IIS:\Sites\$Site").applicationPool
if ($pool -ne $Site) { Set-ItemProperty "IIS:\Sites\$Site" applicationPool $Site; Repare "pool $pool -> $Site" } else { Ok "pool $pool" }
Set-ItemProperty "IIS:\AppPools\$Site" managedRuntimeVersion ''
Ok ("identité : " + (Get-ItemProperty "IIS:\AppPools\$Site").processModel.identityType)

Etape 'Droits'
$compte = "IIS AppPool\$Site"
icacls $Appli /grant "${compte}:(OI)(CI)RX" /T /Q | Out-Null
icacls $Appli /grant "IIS_IUSRS:(OI)(CI)RX" /T /Q | Out-Null
icacls "$Appli\storage" /grant "${compte}:(OI)(CI)M" /T /Q | Out-Null
icacls "$Appli\bootstrap\cache" /grant "${compte}:(OI)(CI)M" /T /Q | Out-Null
icacls $Php /grant "${compte}:(OI)(CI)RX" /T /Q | Out-Null
icacls $Php /grant "IIS_IUSRS:(OI)(CI)RX" /T /Q | Out-Null
Ok "lecture : $compte et IIS_IUSRS sur l'appli et son PHP ; écriture : storage et bootstrap\cache"

Etape 'Caches et redémarrage'
Push-Location $Appli
& "$Php\php.exe" artisan optimize:clear | Out-Null
& "$Php\php.exe" artisan optimize | Out-Null
Pop-Location
Restart-WebAppPool $Site
Start-Website $Site -ErrorAction SilentlyContinue
$port = ((Get-WebBinding -Name $Site | Select-Object -First 1).bindingInformation -split ':')[1]

Etape 'Vérification'
try {
    $r = Invoke-WebRequest "http://localhost:$port/connexion" -UseBasicParsing -TimeoutSec 60
    Write-Host "  Page de connexion : HTTP $($r.StatusCode)  ->  http://192.168.1.227:$port" -ForegroundColor Green
} catch {
    Ko "la page répond : $($_.Exception.Message)"
    Write-Host '  Envoyez une capture de cette fenêtre.' -ForegroundColor Yellow
}
