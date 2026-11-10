# Traçabilité des demandes de sang (Laravel 13, phase 1 : web responsive)

Suivi de chaque demande de sang de bout en bout, avec **qui, quand, quoi** à chaque étape
(procédure qualité « Diagramme procédure demandes de sang »). Remplace le registre papier
PQ-ORG-017-002 et les échanges WhatsApp du coursier et du surveillant de garde.

- **À faire** (écran d'accueil, pensé pour le smartphone) : remises à confirmer en un bouton
  (« J'ai bien reçu » / « Je n'ai rien reçu ») et étapes que la personne connectée peut saisir.
- **Circuit A3 → C10** : chaque étape a ses rôles, ses contrôles et ses prérequis
  (`config/demandesang.php`). Le départ est bloqué tant que le major n'a pas validé (A10).
- **Remises de main à main** (A11, C2, C4, C8) : double validation. Tant que le receveur
  n'a pas confirmé, la demande reste « remise, non confirmée » et passe en alerte.
- **Vérification du stock (A2)** à la création : si une poche est disponible pour le patient,
  la demande est refusée, sauf case cochée + motif.
- **Registre** : `ds_actions` n'est jamais modifiée ni supprimée. Une erreur se corrige par une
  action « correction » ; l'ancienne saisie reste visible, barrée.
- **Stock**, **Alertes** (retards par urgence et par phase, remises non confirmées, péremption,
  poches à retourner, non-conformités), **Indicateurs** (délais par segment, taux de poches non
  consommées et motifs), **Export Excel** (CSV « ; »), **Registre imprimable**, **Bon imprimé**.
- **Paramétrage** (administrateur) : comptes et rôles, services source / destination, seuils, journal.

L'application n'écrit jamais dans Clinisys. Ses données sont dans la base dédiée `demandesang_app`.

## Menu selon le rôle
Ordre : À faire, CTS, Pharmacie, Demandes, Registre, Stock, Alertes, Indicateurs, Paramétrage ; un compte à
plusieurs rôles voit l'union. Infirmière / major : À faire, Demandes, Registre. Coursier : À faire, CTS.
Surveillant : À faire, CTS, Pharmacie, Demandes, Alertes. Pharmacie : Pharmacie, Demandes, Stock (arrive sur
Pharmacie). Direction : Demandes, Registre, Stock, Alertes, Indicateurs (arrive sur Demandes). Administrateur :
comme la direction + Paramétrage. Sur téléphone : les 3 premiers + « Plus ».
**Rechercher / inscrire** (`/demandes/nouvelle`, champ en haut de « Demandes » et du bloc « pas encore inscrites ») :
un seul champ (n° DMI → la demande ; n° de dossier → le dossier ; sinon nom / identifiant). Remplace les anciens
écrans « + Nouvelle » et « Patient » (`/patients` redirige). Consultation pour tous, inscription infirmière / major.

## Connexion et rôles
- Compte **Clinisys** : login + mot de passe Clinisys (table AccessControl, même vérification que l'appli oncologie).
- Compte **local** : login + code PIN (pour le coursier s'il n'a pas de compte Clinisys).
- Les rôles (infirmière, major, coursier, surveillant, pharmacie, direction) se donnent dans
  Paramétrage > Comptes. Un compte sans rôle ne peut pas se connecter. Les administrateurs
  sont listés dans `DS_ADMINS` (.env) ou cochés dans l'écran Comptes.

## Essai sur le poste (sans Clinisys)
```bat
cd D:\Projet\Demandedesang\demandesang-app
composer install
copy .env.example .env
```
Dans `.env` : `APP_ENV=local`, `APP_DEBUG=true`, `DB_CONNECTION=sqlite`, `CLINISYS_ACTIF=false`, puis :
```bat
type nul > database\database.sqlite
php artisan key:generate
php artisan migrate --seed
php artisan serve --host=0.0.0.0 --port=8020
```
Aucun compte de démonstration dans la vraie base : les comptes fictifs ne sont créés que pour les tests
et pour l'instance vidéo (`DS_COMPTES_DEMO=1`, base `database\demo.sqlite`, `video\outils\lancer-video.ps1`).
Pour essayer en local, créer un compte dans Paramétrage > Comptes (administrateur dans `DS_ADMINS`).
Depuis un téléphone sur le même Wi-Fi : `http://<adresse IP du poste>:8020`.

Tests automatiques : `php artisan test` (circuit complet, double validation, refus de remise,
blocage A2, correction, patient externe, connexion PIN, alertes, paramétrage, branchement Clinisys
simulé). Les tests tournent toujours sans Clinisys (`CLINISYS_ACTIF=false` dans `phpunit.xml`).

## Mise en production
1. Créer la base : `CREATE DATABASE demandesang_app;` et un compte `db_datareader` + `db_datawriter`
   + `db_ddladmin` sur cette base uniquement.
2. `.env` : comptes SQL de l'appli et de Clinisys (lecture seule), `CLINISYS_HASH` (même valeur que
   l'appli oncologie), `DS_ADMINS`.
3. `php artisan migrate --force` puis `php artisan db:seed --force` (services de départ, sans comptes de démo).
4. Rapidité : après chaque mise à jour du code ou du `.env`, lancer `php artisan optimize`
   (met en cache configuration, routes et vues ; `php artisan optimize:clear` pour annuler).
   Garder `SESSION_DRIVER=file` et `CACHE_STORE=file` (pas d'aller-retour SQL par page) et
   OPcache activé (`opcache.enable=1` dans php.ini). Installer l'appli près du serveur SQL :
   chaque requête coûte un aller-retour réseau (≈ 35 ms depuis un poste en Wi-Fi, < 1 ms sur le serveur).
5. IIS : même procédure que l'appli oncologie (`deploiement\installer-iis.ps1` de l'appli oncologie,
   avec `-Appli`, `-Site DemandesSang` et un autre port).

## Serveur 192.168.1.227 (IIS, même méthode que l'appli Oncologie)
**Installation (une fois)** : `D:\Projet\Demandedesang\livraison\demandesang-iis-AAAAMMJJ.zip` contient
`INSTALLER.bat`, `demandesang-app` (avec `vendor` sans les outils de dev, `.env` avec une clé propre) et
`php-demandesang` (même PHP 8.3 que l'Oncologie, copie séparée). Sur le serveur : décompresser dans
`C:\Application` (à côté de `oncologie-app` et `php-oncologie`), puis clic droit sur `C:\Application\INSTALLER.bat` >
Exécuter en tant qu'administrateur. Le script (`deploiement\installer-iis.ps1`) crée le pool et le site
**DemandesSang** sur le premier port libre à partir de 8090 (l'Oncologie garde le sien), donne les droits
d'écriture sur `storage` et `bootstrap\cache`, ouvre le port au pare-feu, passe le `.env` en production, lance
`migrate` et `optimize`, puis affiche l'adresse. Pas de tâche planifiée. Le site Oncologie n'est pas modifié.

**Mise à jour (comme l'Oncologie)** : sur le PC, `powershell -File D:\Projet\Demandedesang\livraison\preparer-maj.ps1`
crée `maj-demandesang-AAAAMMJJ-N.zip` avec seulement les fichiers modifiés (référence :
`livraison\paquet\demandesang-app` = état du serveur ; `.env`, `vendor`, `storage`, `tests` exclus). Sur le serveur :
décompresser le zip **dans** `C:\Application\demandesang-app` (pas de dossier racine dans le zip) en remplaçant les fichiers, puis double-cliquer
`C:\Application\demandesang-app\mise-a-jour.bat` (migrate + caches). Si `composer.json` change, refaire un paquet complet.

**Erreur IIS 403** : `public\web.config` (document par défaut `index.php` + réécriture vers Laravel) doit exister ;
`C:\Application\demandesang-app\CORRIGER-403.bat` (administrateur ; trouve seul l'appli et `php-demandesang`, n'écrit jamais un chemin inexistant) vérifie et répare chemin, web.config, handler PHP, accès anonyme,
pool et droits du seul site DemandesSang, puis teste la page.

## Branchement Clinisys (lecture seule)
Avec `CLINISYS_ACTIF=true`, l'appli lit la base `CLINISYS_BASE_ERP` (et `CLINISYS_BASE_PHARMACIE`,
la même : `Gclinique_Maroc`). Tables trouvées avec les scripts 01 / 02 (résultats dans `..\resultats\`) :

| Besoin | Table(s) Clinisys | Utilisation dans l'appli |
|---|---|---|
| Patient, service du DMI | `Client`, `chambre`, `Reception`, `Medecin` | Nom, identifiant, service, médecin |
| Demande de sang DMI (A1, A5) | `Demande_Sang_Maroc` (ID = n° de demande) | Nouvelle demande : saisie du n°, préremplissage verrouillé (dossier, diagnostic, produit, nb poches, groupe, médecin) ; liste des demandes DMI du dossier sur 48 h ; demande inconnue, supprimée (S) ou d'un autre dossier refusée |
| Panier dépôt fournisseur | `Panier_Depot_Frs` (`numDemande` = ID de la demande) | Encart « Clinisys » de la fiche ; patient d'une poche du stock BS |
| Réception pharmacie (C5) | `FactureBD_PR` (typbon `BD`, `numDemandeS`) + `MvtStoBD_PR` (`Identifiant` = n° de poche) | Bon BD proposé, poches et péremptions affichées, écarts avec les poches du contrôle C3 ; action enregistrée avec `source: clinisys` |
| Stock banque de sang (A2, écran Stock) | `DepSto` (`coddep = 'BS'`, `qte > 0`) + `Stock` | Bloc « Stock Clinisys dépôt BS » ; A2 bloque si une poche du stock BS est au même dossier |
| Prélèvement fournisseur | `FactureBD_PR` / `MvtStoBD_PR` (typbon `PR`, `NumBon_Depot` = BD d'origine) | Encart « Clinisys » de la fiche |
| Sortie patient (C8) | à trouver | Saisie dans l'appli (double validation) |

**La pharmacie ne saisit rien dans l'appli** (rôle pharmacie = consultation). Ses étapes sont lues dans
Clinisys : C5 « Reçu en pharmacie » quand le bon de dépôt BD de la demande DMI existe (heure et utilisateur
du bon, n° de poches, péremptions), C8 « Délivrée au service » quand la sortie patient PA de ces poches existe,
et le retour fournisseur quand un bon PR reprend une poche. Le coursier déclare seul C4 « Portées à la
pharmacie ». La lecture se fait à l'ouverture d'une fiche, de « À faire », de l'écran CTS et du registre, au plus
une fois par minute (cache partagé), et seulement pour les demandes qui attendent la pharmacie (après C4) :
`app/Services/SyncClinisys.php`. Pas de tâche planifiée par défaut ; `php artisan ds:sync-clinisys` la lance
à la main, et `DS_SYNC_PLANIFIEE=true` (.env) l'ajoute au planificateur Laravel toutes les 5 minutes.
Alerte « Pas encore saisi dans Clinisys » après N minutes (Paramètres, 60 par défaut).
Écran **Pharmacie** (`/pharmacie`, rôles pharmacie, surveillant, major, direction) : portées à la pharmacie sans
bon BD, nouvelles réceptions des 24 h (badge « Nouveau » < 2 h, bon, heure, utilisateur Clinisys, poches), reçues à
délivrer, délivrées aujourd'hui sans accusé C9. Lien sur « À faire » et filtre « Réceptionnées pharmacie » au registre.

**Journal** (Paramétrage > Journal) : connexions, échecs, écrans et demandes consultés, impressions et
exports, en clair, avec filtres et export CSV. Il ne garde que des identifiants (n° de demande, dossier,
n° DMI), jamais le contenu d'une demande. La fiche d'une demande indique qui l'a consultée.

`Demande_Sang_Maroc.Etat` : S = supprimée, D = non réceptionnée, V = réceptionnée (`etats_dmi`).
La fiche signale une demande supprimée dans le DMI mais en cours ici, et une demande réceptionnée (V)
sans réception pharmacie (C5) dans l'appli. Si une demande DMI porte plusieurs produits, l'appli suit
le premier (CGR, puis PFC, puis CPS) et note les autres en commentaire.

Si Clinisys ne répond pas, les écrans s'affichent avec « Clinisys indisponible » ; la création d'une
demande est alors refusée (le n° DMI ne peut pas être contrôlé). Code : `app/Clinisys/DemandesDmi.php`,
`app/Clinisys/Pharmacie.php`. Tests : `tests/Feature/ClinisysTest.php` (Clinisys simulé).

## Fichiers
| Fichier | Rôle |
|---|---|
| `config/demandesang.php` | Étapes du circuit, rôles, urgences, produits, motifs, seuils par défaut |
| `app/Services/Circuit.php` | État des étapes, enregistrement, remises, corrections, annulation |
| `app/Services/Alertes.php`, `Indicateurs.php` | Alertes et indicateurs |
| `app/Auth/*` | Connexion Clinisys ou PIN, rôles |
| `app/Clinisys/*` | Lectures Clinisys (patients, demandes DMI, pharmacie dépôt BS) |
| `database/migrations/*` | Tables `ds_*` |
