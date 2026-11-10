# Demandes de sang — traçabilité de bout en bout

Application web (Laravel 13, responsive, pensée pour le smartphone) qui suit chaque demande de
sang de la clinique, de la prescription à la transfusion, avec **qui, quand et quoi** à chaque
étape. Elle applique la procédure qualité « Diagramme de procédure des demandes de sang » et
remplace le registre papier **PQ-ORG-017-002** ainsi que les échanges WhatsApp entre le service,
le coursier, le surveillant de garde et la pharmacie.

> L'application **n'écrit jamais dans Clinisys**. Elle le lit en lecture seule (patients,
> comptes, et plus tard pharmacie) et range ses propres données dans une base dédiée
> (`demandesang_app`, tables `ds_*`).

## Sommaire
1. [Objectif](#1-objectif)
2. [Fonctionnalités](#2-fonctionnalités)
3. [Stack technique](#3-stack-technique)
4. [Installation et lancement](#4-installation-et-lancement)
5. [Rôles et connexion](#5-rôles-et-connexion)
6. [La procédure : circuit d'une demande](#6-la-procédure--circuit-dune-demande)
7. [Base de données de l'application (tables `ds_*`)](#7-base-de-données-de-lapplication-tables-ds_)
8. [Tables Clinisys lues (lecture seule)](#8-tables-clinisys-lues-lecture-seule)
9. [Organisation du code](#9-organisation-du-code)
10. [Documentation (`docs/`)](#10-documentation-docs)

---

## 1. Objectif

Aujourd'hui, une demande de sang passe par plusieurs mains (service, coursier, centre de
transfusion sanguine, surveillant de garde, pharmacie) sans preuve de passage. Quand le major dit
avoir remis la demande et que le coursier dit ne pas l'avoir reçue, personne ne peut trancher.

L'application répond à trois besoins :
- **Tracer** chaque étape, horodatée et signée par la personne connectée, sans rien effacer.
- **Prouver les remises de main à main** par une double validation (le donneur remet, le receveur confirme).
- **Voir à tout moment** l'état de chaque demande, les poches en stock et les retards (alertes, indicateurs).

Phase 1 : application web responsive. Phase 2 (plus tard) : application Android native.

## 2. Fonctionnalités

- **À faire** (page d'accueil, pensée smartphone) : remises à confirmer en un bouton (« J'ai bien
  reçu » / « Je n'ai rien reçu »), étapes à saisir, demandes DMI pas encore inscrites, mes remises
  en attente. Badge « À faire par : rôle ».
- **Rechercher / inscrire** : un seul champ (n° de demande DMI, n° de dossier, nom, identifiant,
  CIN). La demande est préremplie depuis le DMI Clinisys. Une demande DMI supprimée, inconnue,
  d'un autre dossier ou déjà inscrite est refusée.
- **Cas du patient** : hospitalisé, externe (consultation / urgence) ou hospitalisé avec
  transfusion hors séjour (second dossier). Externe et hors séjour déclenchent la vérification du paiement (A3).
- **Vérification du stock (A2)** sur tous les dossiers du patient : une poche disponible bloque une
  nouvelle demande, sauf motif (non compatible, quantité insuffisante, réservée, autre).
- **Demandes = tableau de suivi** : frise en 6 phases (Service → Coursier → CTS → Clinique →
  Pharmacie → Service), « Bloquée à / À faire par / depuis », couleur selon le retard, filtres d'un clic.
- **Fiche demande** : chronologie complète, encart Clinisys (DMI, panier, bons BD / PA / PR),
  « Qui a consulté cette demande », bon imprimable, commentaires, corrections, annulation.
- **Relances et livraisons partielles** : une demande peut être marquée comme relance d'une autre
  (le CTS livre souvent 1 poche sur 3) ; ratio de poches arrivées.
- **Régularisation** : une demande reçue en pharmacie sans avoir été inscrite apparaît « hors
  registre » et s'inscrit a posteriori avec un motif obligatoire.
- **CTS** (coursier) : à récupérer, à déposer, en attente au CTS, à ramener, en instance.
- **Pharmacie** (consultation) : hors registre, bon BD à saisir, nouvelles réceptions, à délivrer, délivrées.
  C5 et C8 sont déduites automatiquement des bons Clinisys.
- **Stock** : état Clinisys de la banque de sang enrichi (dossier, patient, demande), périmés à part,
  filtre « périment dans X jours », impression A4.
- **Registre** (remplace le papier) : remis / reçu avec heure et nom, paiement, ratio, impression, export CSV.
- **Alertes** : retards par urgence et par phase, remises non confirmées, non-conformités, DMI non
  inscrites, Clinisys pas encore saisi, péremption, retours, instance au CTS.
- **Indicateurs** : délais par segment, taux de poches non consommées et motifs, demandes servies partiellement, export.
- **Paramétrage** (administrateur) : comptes (liste Clinisys, rôles, plusieurs services), services
  (synchronisés depuis Clinisys), seuils, téléphones, journal filtrable et exportable.
- **Français et arabe** (RTL), choix sur la page de connexion, mémorisé sur le compte.

## 3. Stack technique

| Élément | Choix |
|---|---|
| Langage | PHP 8.3+ |
| Framework | Laravel 13 (Blade, sessions et cache en base) |
| Base de l'application | SQL Server (`sqlsrv`) en production, SQLite pour l'essai sur poste |
| Clinisys | SQL Server, connexion `clinisys` en lecture seule (compte `db_datareader`), serveur lié optionnel |
| Authentification | Fournisseur maison : mot de passe Clinisys (table `AccessControl`, PBKDF2) ou code PIN local |
| Front | Blade + CSS / JS dans `public/` (pas de build) |
| Langues | Français (`lang/fr`) et arabe (`lang/ar.json`, outils `lang/fusionner.php` et `lang/verifier.php`) |
| Tests | PHPUnit : `tests/Feature/CircuitTest.php` et `tests/Feature/ClinisysTest.php` (Clinisys simulé), SQLite en mémoire |
| Hébergement | IIS + PHP dédié (`deploiement/installer-iis.ps1`, `INSTALLER.bat`, `web.config`) |

## 4. Installation et lancement

### Prérequis
- PHP 8.3 ou plus, avec `pdo_sqlite` (essai) ou `pdo_sqlsrv` / `sqlsrv` (production)
- Composer 2

### Essai sur le poste (sans Clinisys)
```bat
git clone https://github.com/benmechichighassen1-crypto/Demande-de-sang.git
cd Demande-de-sang
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
Depuis un téléphone sur le même Wi-Fi : `http://<adresse IP du poste>:8020`.

Créer ensuite un compte local avec code PIN dans Paramétrage > Comptes (le login mis dans
`DS_ADMINS` est administrateur). Tests automatiques : `php artisan test`.

Mode démonstration : `DS_DEMO=true` remplace Clinisys par des données fictives (`app/Demo/ClinisysDemo.php`).

### Mise en production
1. Créer la base : `CREATE DATABASE demandesang_app;` et un compte SQL `db_datareader` +
   `db_datawriter` + `db_ddladmin` sur cette base uniquement.
2. Remplir `.env` : comptes SQL de l'application et de Clinisys (lecture seule), `CLINISYS_HASH`
   (même valeur que l'application oncologie), `DS_ADMINS`.
   Le script [`docs/sql/03_creer_base_demandesang_app.sql`](docs/sql/03_creer_base_demandesang_app.sql) crée la base.
3. `php artisan migrate --force` puis `php artisan db:seed --force` (services de départ).
4. IIS : `deploiement/INSTALLER.bat` en administrateur (appelle `installer-iis.ps1`) ;
   en cas d'erreur 403 : `CORRIGER-403.bat`.
5. Après chaque mise à jour du code : `mise-a-jour.bat` (migrations, `ds:cin`, `optimize`).

### Commandes artisan
| Commande | Rôle |
|---|---|
| `php artisan ds:services-clinisys` | Synchronise `ds_services` avec les services de soin Clinisys (`prmEtage`) |
| `php artisan ds:cin` | Complète le CIN des demandes depuis Clinisys |
| `php artisan ds:sync-clinisys` | Relit Clinisys pour les étapes pharmacie (C5, C8, retours) ; planifiée toutes les 5 min seulement si `DS_SYNC_PLANIFIEE=true` |

### Variables d'environnement propres à l'application
| Variable | Rôle |
|---|---|
| `DB_*` | Base dédiée de l'application (tables `ds_*`, sessions, cache) |
| `CLINISYS_ACTIF` | `false` = aucune lecture Clinisys (poste de développement) |
| `CLINISYS_HOST`, `CLINISYS_PORT`, `CLINISYS_DATABASE`, `CLINISYS_USERNAME`, `CLINISYS_PASSWORD` | Connexion Clinisys en lecture seule |
| `CLINISYS_BASE_ERP` | Base ERP / DMI (par défaut `Gclinique_Maroc`) |
| `CLINISYS_BASE_PHARMACIE` | Base du module pharmacie (par défaut la même que l'ERP) |
| `CLINISYS_PREFIXE` | Préfixe de serveur lié, ex. `[SRV_ERP]` ; vide = connexion directe |
| `CLINISYS_HASH` | Schéma de hachage AccessControl : `auto`, `pbkdf2-sha512`, `pbkdf2-sha256`, `pbkdf2-sha1` |
| `DS_ADMINS` | Logins administrateurs, séparés par des virgules |
| `CLINISYS_ENCRYPT`, `CLINISYS_TRUST_SERVER_CERTIFICATE` | Options TLS du pilote SQL Server |
| `DS_ETABLISSEMENT` | Nom de l'établissement affiché sur les bons |
| `DS_PEREMPTION_JOURS` | Alerte quand une poche en stock périme dans moins de N jours (défaut 3) |
| `DS_SYNC_PLANIFIEE` | `true` = relecture Clinisys planifiée toutes les 5 min (par défaut : à l'ouverture des écrans) |
| `DS_DEMO` | `true` = Clinisys fictif pour les démonstrations |

Le fichier `.env` n'est jamais versionné ; seul `.env.example` l'est.

## 5. Rôles et connexion

Deux sortes de comptes :
- **Compte Clinisys** : login et mot de passe Clinisys, vérifiés dans `AccessControl` (lecture seule,
  même vérification que l'application oncologie). Un compte inactif ou expiré est refusé.
- **Compte local** : login + code PIN haché dans `ds_comptes.pin_hash`, pour le coursier s'il n'a pas de compte Clinisys.

Dans les deux cas, **les rôles viennent de `ds_comptes`** : un compte sans rôle (et non
administrateur) ne peut pas se connecter. Cinq tentatives échouées bloquent la connexion 5 minutes.

| Rôle (`roles`) | Libellé | Ce qu'il fait dans l'application |
|---|---|---|
| `infirmiere` | Infirmière du service | Inscrit la demande, prépare (A4, A6, A7, A9), remet au coursier (A11), accuse réception (C9), saisit la transfusion (C10) |
| `major` | Major du service | Comme l'infirmière, plus la validation A10, les services source / destination et l'annulation |
| `coursier` | Coursier | Confirme la prise en charge (A11), vérifie le paiement (A3), B1, B2, C1, remet au surveillant (C2), porte à la pharmacie (C4) |
| `surveillant` | Surveillant général / de garde | **Seul** à confirmer C2, faire la conformité (C3) et prévenir le service (C6) ; voit tous les services ; peut régulariser et annuler |
| `pharmacie` | Agent de pharmacie | Consultation : elle travaille dans Clinisys et l'application lit ses bons (C5, C8 automatiques) |
| `direction` | Direction / qualité | Consultation, indicateurs, export, registre |
| *(admin)* | Administrateur | Paramétrage : comptes, services, seuils, téléphones, journal (`ds_comptes.admin` ou `DS_ADMINS`) |

Une infirmière ou un major ne voit que les demandes de **ses services rattachés**
(`ds_compte_services`, plusieurs possibles) et celles qu'il a créées ; un compte sans service voit tout.

## 6. La procédure : circuit d'une demande

Le diagramme de la procédure qualité (5 pages, corrigé le 03/11/2026) est dans `docs/` :
`Diagramme de procédure des demandes de sang - corrigé.drawio` (à ouvrir avec
[draw.io / diagrams.net](https://app.diagrams.net)) et sa version PDF
`Diagramme de procédure des demandes de sang.drawio.pdf`. Il couvre trois cas : patient externe,
patient hospitalisé, et patient hospitalisé avec demande hors hospitalisation. Le circuit
ci-dessous est celui de l'application (`config/demandesang.php`, clé `etapes`).

```
 Inscription : A1 prescription DMI · A2 stock vérifié · A5 demande Clinisys · A8 registre
 Phase A (service)  : A4 identité → A6 prélèvement · A7 préparation · A9 coursier informé
                      → A10 validation major → A11 remise au coursier ⇄ → A3 paiement (externe)
 Phase B (CTS)      : B1 transport au CTS → B2 poches prêtes / en instance
 Phase C (clinique) : C1 livraison → C2 remise au surveillant ⇄ → C3 conformité → C4 transport pharmacie
                      → C5 réception (bon BD, auto) → C6 service informé → C8 sortie (bon PA, auto)
                      → C9 accusé de réception → C10 transfusion / poche non utilisée
 ⇄ = remise de main à main avec double validation
```

### Étapes enregistrées à l'inscription de la demande
| Code | Étape | Qui |
|---|---|---|
| A1 | Prescription de la transfusion (Clinisys DMI) | Médecin prescripteur |
| A2 | Vérification du stock pour le patient (tous ses dossiers ; blocage si une poche est disponible, sauf motif) | Infirmière / major |
| A5 | Élaboration de la demande sur Clinisys (lue dans `Demande_Sang_Maroc`) | Infirmière / major |
| A8 | Enregistrement sur le registre et nature : à ramener dès que prêtes, ou à laisser en instance au CTS | Infirmière / major |

### Phase A — Au service
| Code | Étape | Type | Qui saisit | Prérequis | Données saisies |
|---|---|---|---|---|---|
| A4 | Vérification de l'identification patient | simple | infirmière, major | — | identité OK |
| A6 | Prélèvement de sang (1 tube si carte de groupage, sinon 2) | simple | infirmière, major | A4 | carte de groupage |
| A7 | Préparation de la demande (pièces jointes) | simple | infirmière, major | — | pièces |
| A9 | Informer le coursier | simple | infirmière, major | — | heure d'appel |
| A10 | Validation de la demande avec le surveillant du service | simple | major | A4, A6, A7 | services source / destination |
| A11 | Dossier remis au coursier | **remise** | infirmière, major → **coursier** confirme | A9, A10 | — |
| A3 | Vérification du paiement (reçu, ou prise en charge par un médecin, le personnel ou une association) — **patient externe ou hors séjour** | simple | coursier | A11 | paiement |

A4, A6, A7 et A9 se saisissent ensemble dans le formulaire « Préparation ».

### Phase B — Au centre de transfusion sanguine (CTS)
| Code | Étape | Type | Qui saisit | Prérequis | Données saisies |
|---|---|---|---|---|---|
| B1 | Transport de la demande au CTS | simple | coursier | A11, A3 | — |
| B2 | Poches prêtes au CTS, ou gardées en instance (éventuellement moins que demandé) | simple | coursier | B1 | état au CTS |

### Phase C — Retour à la clinique
| Code | Étape | Type | Qui saisit | Prérequis | Données saisies |
|---|---|---|---|---|---|
| C1 | Livraison des poches à la clinique | simple | coursier | B2 | — |
| C2 | Bon de distribution + demande Clinisys remis au surveillant | **remise** | coursier → **surveillant** confirme | C1 | — |
| C3 | Vérification de la conformité poche / demande | simple | surveillant | C2 | conforme ou non + motif, poches |
| C4 | Transport vers le site de stockage (pharmacie) | simple | coursier | C3 | — |
| C5 | Enregistrement de réception | **automatique** (bon de dépôt « BD » Clinisys) ; saisie pharmacie sans Clinisys | pharmacie | C4 | n° de bon de dépôt |
| C6 | Informer le service demandeur / patient | simple | surveillant | C5 | heure |
| C8 | Récupération de la poche au service de pharmacie | **automatique** (sortie patient « PA » Clinisys) | pharmacie | C5 | poches délivrées |
| C9 | Accusé de réception dans le service | simple | infirmière, major | C8 | — |
| C10 | Transfusion réalisée / poche non utilisée (motif) — clôt la demande | simple | infirmière, major | C9 | issue |

### Règles du circuit
- **Remise (double validation)** : le donneur enregistre la remise, la demande passe « remise, non
  confirmée » jusqu'à ce que le receveur confirme ou refuse (avec motif). Une remise non confirmée
  au-delà du seuil passe en alerte.
- **Prérequis** : une étape ne peut être saisie que si ses étapes « après » sont faites (A3 ne
  compte que pour un patient externe ou hors séjour).
- **Plusieurs produits** dans une demande DMI : l'application suit le premier (CGR, puis PFC, puis CPS)
  et met les autres en commentaire.
- **Rien ne s'efface** : une erreur se corrige par une action `correction` qui pointe vers l'action annulée.
- **Urgences** : `vitale`, `urgente`, `programmee`. Seuils d'alerte par défaut (minutes, modifiables
  dans Paramétrage > Seuils) :

| Urgence | Phase service | Phase CTS | Retour | Remise non confirmée |
|---|---|---|---|---|
| Vitale | 30 | 60 | 30 | 10 |
| Urgente | 60 | 180 | 60 | 15 |
| Programmée | 240 | 720 | 240 | 30 |

- **Produits** : CGR (culot globulaire), PFC (plasma frais congelé), CPS (concentré plaquettaire
  standard), CPA (concentré plaquettaire d'aphérèse), AUTRE.
- **Autres seuils** (Paramétrage) : bon Clinisys pas encore saisi après 60 min, instance au CTS
  au-delà de 3 jours, DMI non inscrite après 30 min (sur 7 jours), A2 sur 30 jours de demandes et
  3 jours de sorties, pastille « périment dans 5 jours ».
- **Motifs de non-utilisation** (C10 et prélèvement fournisseur) : périmée, patient non venu,
  transfusion annulée, décès du patient, poche non conforme, autre.

## 7. Base de données de l'application (tables `ds_*`)

Créées par [`database/migrations/2026_10_26_000001_create_ds_tables.php`](database/migrations/2026_10_26_000001_create_ds_tables.php)
puis complétées par les migrations suivantes :

| Migration | Changement |
|---|---|
| `2026_10_28_000001_index_demande_id` | Index sur `demande_id` (`ds_actions`, `ds_remises`, `ds_poches`, `ds_services_demande`) et `ds_actions.remise_id` |
| `2026_10_28_000002_create_ds_compte_services` | Table `ds_compte_services` (plusieurs services par agent), reprise de `ds_comptes.service_code` |
| `2026_10_28_000003_add_demande_relancee_id` | `ds_demandes.demande_relancee_id` (relance d'une demande servie partiellement) |
| `2026_10_28_000004_journal_identifiants` | `ds_journal.demande_id` ; le journal ne garde plus que des identifiants |
| `2026_10_29_000001_add_regularisation_to_ds_demandes` | `ds_demandes.regularisation` (motif d'une inscription a posteriori) |
| `2026_10_29_000002_add_langue_to_ds_comptes` | `ds_comptes.langue` (`fr` / `ar`) |
| `2026_11_03_000001_index_performance` | Index `ds_remises(reponse)`, `ds_remises(donneur)`, `ds_poches(statut)`, `ds_demandes(num_demande_clinisys)`, `ds_demandes(statut, etape)` |
| `2026_11_03_000002_cas_patient` | `ds_demandes.cas_patient` |
| `2026_11_06_000001_cin_patient` | `ds_demandes.cin` et `cin_source`, CIN repris de Clinisys |

Les tableaux ci-dessous décrivent l'état final après toutes les migrations.
Elles vivent dans la base dédiée (`DB_DATABASE`, par défaut `demandesang_app`), jamais dans Clinisys.
Les tables Laravel standard `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches` et
`failed_jobs` sont créées par les migrations `0001_01_01_*`.

Principe : **`ds_actions` est le registre**. Une ligne par action, jamais modifiée ni supprimée.
Les colonnes d'état de `ds_demandes` et `ds_poches` (`etape`, `statut`…) ne sont qu'un résumé
recalculé à chaque action, pour les listes et les filtres.

### Schéma des relations
```
ds_comptes (user_name) ──── ds_compte_services ····> ds_services (code)
ds_demandes (id) ──── ds_demandes.demande_relancee_id   (relance)
ds_services (code) ·······> ds_comptes.service_code, ds_demandes.service_source / service_destination,
                            ds_services_demande.service_source / service_destination   (liens logiques)

                    ┌──────────────── ds_actions ──┐ annule_action_id → ds_actions.id
                    │   demande_id                 │ remise_id ·····> ds_remises.id (logique)
ds_demandes (id) ───┼──────────────── ds_remises
                    ├──────────────── ds_services_demande
                    └──────────────── ds_poches

ds_comptes (user_name) ·····> ds_actions.user_name, ds_remises.donneur / receveur,
                              ds_demandes.cree_par, ds_journal.user_name   (liens logiques)
ds_journal.demande_id ·····> ds_demandes.id (logique) ; ds_parametres : table indépendante
```
Trait plein = clé étrangère déclarée ; pointillé = lien logique (pas de contrainte, car un login
peut être un compte Clinisys sans ligne dans `ds_comptes`, et l'historique doit survivre à un
service ou un compte désactivé).

### `ds_comptes` — comptes et rôles
Un compte Clinisys (login AccessControl) ou un compte local à code PIN.

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `user_name` | varchar(50) | non | | **PK**. Login Clinisys, ou login local |
| `nom` | varchar(120) | non | | Nom affiché |
| `roles` | varchar(200) | non | `''` | Rôles séparés par des virgules (`infirmiere,major`…) |
| `service_code` | varchar(20) | oui | | Service de rattachement (infirmière, major) → `ds_services.code` |
| `pin_hash` | varchar(255) | oui | | Hash du code PIN (compte local uniquement ; null = compte Clinisys) |
| `admin` | boolean | non | false | Administrateur de l'application |
| `langue` | varchar(2) | oui | | Langue préférée : `fr` ou `ar` |
| `actif` | boolean | non | true | Un compte inactif ne peut pas se connecter |
| `modifie_par` | varchar(50) | oui | | Dernier administrateur qui a modifié le compte |
| `modifie_le` | datetime | oui | | Date de la dernière modification |

### `ds_compte_services` — services rattachés à un compte
Une infirmière ou un major ne voit que les demandes de ses services.

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `user_name` | varchar(50) | non | | **PK (1/2)** → `ds_comptes.user_name` |
| `service_code` | varchar(20) | non | | **PK (2/2)** → `ds_services.code` |

(`ds_comptes.service_code` reste le service principal historique.)

### `ds_services` — référentiel des services
| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `code` | varchar(20) | non | | **PK**. Ex. `URG`, `HOSP2`, `REA`, `EXT` |
| `libelle` | varchar(120) | non | | Libellé affiché |
| `actif` | boolean | non | true | Proposé dans les listes |
| `ordre` | int | non | 0 | Ordre d'affichage |

Services de départ créés par le seeder, puis synchronisés avec les services de soin Clinisys
(`prmEtage`) par `php artisan ds:services-clinisys` ou Paramétrage > Services.

### `ds_demandes` — une ligne par demande de sang
| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | | **PK** |
| `num_demande_clinisys` | varchar(30) | oui | | N° de l'entête « demande de sang » du DMI (index) |
| `num_doss` | varchar(20) | non | | N° de dossier patient Clinisys (index) |
| `identifiant_patient` | varchar(20) | oui | | Identifiant patient Clinisys (index) |
| `nom_patient` | varchar(150) | non | | Nom complet du patient |
| `cin` | varchar(40) | oui | | N° de CIN du patient (exigé par la banque de sang) (index) |
| `cin_source` | varchar(10) | oui | | Provenance du CIN : `clinisys`, `saisie` (saisi dans l'appli), `autre` (autre pièce) |
| `externe` | boolean | non | false | Patient externe (déclenche l'étape A3) |
| `cas_patient` | varchar(15) | non | `hospitalise` | `hospitalise`, `externe` ou `hors_sejour` (A3 pour les deux derniers) |
| `urgence` | varchar(12) | non | | `vitale`, `urgente` ou `programmee` |
| `nature` | varchar(15) | non | | `acheminement` ou `instance` (A8) |
| `produit` | varchar(10) | non | | `CGR`, `PFC`, `CPS`, `CPA`, `AUTRE` |
| `nb_poches` | smallint non signé | non | | Nombre de poches demandées |
| `groupe_sanguin` | varchar(4) | oui | | `A+` … `O-` |
| `diagnostic` | varchar(255) | non | | Diagnostic (obligatoire, A5) |
| `medecin` | varchar(120) | oui | | Médecin prescripteur |
| `service_saisie_dmi` | varchar(120) | oui | | Service lu dans Clinisys, pour comparaison |
| `service_source` | varchar(20) | oui | | Résumé : dernier service source saisi par le major → `ds_services.code` |
| `service_destination` | varchar(20) | oui | | Résumé : dernier service de destination → `ds_services.code` |
| `etape` | varchar(10) | oui | | Résumé : dernière étape faite |
| `etape_le` | datetime | oui | | Date de la dernière étape |
| `statut` | varchar(15) | non | `en_cours` | `en_cours`, `cloturee`, `annulee` (index) |
| `cree_par` | varchar(50) | non | | Login du créateur |
| `cree_le` | datetime | non | | Date de création (index) |
| `demande_relancee_id` | bigint | oui | | **FK → `ds_demandes.id`** : demande dont celle-ci est la relance (index) |
| `regularisation` | varchar(255) | oui | | Motif d'une inscription a posteriori (demande déjà reçue en pharmacie) |

### `ds_actions` — registre des actions (jamais modifié)
| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | | **PK** |
| `demande_id` | bigint | non | | **FK → `ds_demandes.id`** |
| `etape` | varchar(30) | non | | Code d'étape (`A4`, `C3`…) ou action hors circuit : `creation`, `services`, `commentaire`, `correction`, `remise_confirmee`, `remise_refusee`, `annulation`, `prelevement_fournisseur` (index) |
| `user_name` | varchar(50) | non | | Login de l'acteur (index) |
| `nom_acteur` | varchar(120) | non | | Nom de l'acteur au moment de l'action |
| `fait_le` | datetime | non | | Horodatage (index) |
| `donnees` | text | oui | | JSON des champs saisis avec l'étape |
| `commentaire` | text | oui | | Commentaire libre |
| `annule_action_id` | bigint | oui | | **FK → `ds_actions.id`** : action annulée par une correction |
| `remise_id` | bigint | oui | | Remise concernée → `ds_remises.id` (lien logique) |
| `ip` | varchar(45) | oui | | Adresse IP du poste |
| `appareil` | varchar(255) | oui | | User-agent du navigateur |

### `ds_remises` — remises de main à main (double validation)
Créée par le donneur aux étapes A11, C2, C4 et C8, puis complétée par le receveur.

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | | **PK** |
| `demande_id` | bigint | non | | **FK → `ds_demandes.id`** |
| `etape` | varchar(10) | non | | `A11`, `C2`, `C4` ou `C8` |
| `donneur` | varchar(50) | non | | Login de celui qui remet |
| `nom_donneur` | varchar(120) | non | | Nom du donneur |
| `remis_le` | datetime | non | | Heure de la remise |
| `receveur` | varchar(50) | oui | | Login de celui qui a répondu |
| `nom_receveur` | varchar(120) | oui | | Nom du receveur |
| `repondu_le` | datetime | oui | | Heure de la confirmation ou du refus |
| `reponse` | varchar(10) | non | `attente` | `attente`, `confirmee`, `refusee` (index) |
| `motif_refus` | varchar(255) | oui | | Motif obligatoire en cas de refus |

### `ds_services_demande` — historique service source / destination
Chaque saisie du major (A10 ou écran « Services ») ajoute une ligne ; la dernière est recopiée
dans `ds_demandes.service_source` / `service_destination`.

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | | **PK** |
| `demande_id` | bigint | non | | **FK → `ds_demandes.id`** |
| `service_source` | varchar(20) | oui | | → `ds_services.code` |
| `service_destination` | varchar(20) | oui | | → `ds_services.code` |
| `transfert` | boolean | non | false | Le patient a été transféré entre-temps |
| `saisi_par` | varchar(50) | non | | Login du major |
| `saisi_le` | datetime | non | | Date de la saisie |

### `ds_poches` — poches de sang
Saisies au contrôle de conformité (C3), en attendant la lecture des réceptions et sorties dans Clinisys pharmacie.

| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | | **PK** |
| `demande_id` | bigint | non | | **FK → `ds_demandes.id`** |
| `numero` | varchar(40) | non | | N° de la poche (index) |
| `produit` | varchar(10) | non | | `CGR`, `PFC`, `CPS`, `CPA`, `AUTRE` |
| `groupe_sanguin` | varchar(4) | oui | | Groupe de la poche |
| `peremption` | date | oui | | Date de péremption (alerte à J-`DS_PEREMPTION_JOURS`) |
| `bon_depot` | varchar(30) | oui | | N° de bon de dépôt FRS « BD… » (C5) |
| `statut` | varchar(12) | non | `attente` | `attente` (pas encore reçue en pharmacie), `en_stock`, `delivree`, `transfusee`, `prelevee` (reprise par le fournisseur) (index) |
| `motif` | varchar(30) | oui | | Motif de non-utilisation |
| `bon_prelevement` | varchar(30) | oui | | N° de bon de prélèvement fournisseur « PR… » |
| `statut_le` | datetime | oui | | Date du dernier changement de statut |

### `ds_parametres` — paramètres modifiables
| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `cle` | varchar(60) | non | | **PK**. Ex. `seuil.vitale.service`, `seuil.urgente.remise`, `peremption_jours`, `perime_bientot_jours`, `clinisys_minutes`, `instance_jours`, `dmi_jours`, `dmi_minutes`, `tel_coursier`, `tel_surveillant`, `tel_major` |
| `valeur` | varchar(255) | non | | Valeur (minutes pour les seuils, jours pour la péremption) |
| `modifie_par` | varchar(50) | oui | | Administrateur |
| `modifie_le` | datetime | oui | | Date de modification |

Sans ligne, la valeur par défaut de `config/demandesang.php` s'applique.

### `ds_journal` — journal des connexions et consultations
| Colonne | Type | Null | Défaut | Description |
|---|---|---|---|---|
| `id` | bigint auto | non | | **PK** |
| `user_name` | varchar(50) | oui | | Login (null avant connexion) (index) |
| `action` | varchar(40) | non | | Type d'événement : connexion, page consultée, export… (index) |
| `details` | text | oui | | Détails en JSON (route et identifiants seulement, jamais de données patient) |
| `ip` | varchar(45) | oui | | Adresse IP |
| `navigateur` | varchar(255) | oui | | User-agent |
| `url` | varchar(500) | oui | | Page consultée |
| `cree_le` | datetime | non | | Horodatage (index) |
| `demande_id` | bigint | oui | | Demande consultée → `ds_demandes.id` (lien logique, index) ; sert à « Qui a consulté cette demande » |

## 8. Tables Clinisys lues (lecture seule)

Connexion `clinisys` (`config/database.php`), éventuellement via un **serveur lié** (`CLINISYS_PREFIXE`).
Les noms complets sont construits par `App\Support\Clinisys::t()` : `[préfixe.]<base>.dbo.<table>`,
avec validation des noms ; les valeurs saisies passent toujours par des paramètres liés.

Règles :
- uniquement des `SELECT`, avec `WITH (NOLOCK)` ;
- les listes lourdes passent par `OPENQUERY` sur le serveur lié (`Clinisys::distant()`) ;
- cache de 60 s pour le stock et les compteurs, 10 min pour la liste des comptes ; une demande ou un dossier est lu en direct ;
- pas de tâche planifiée par défaut : Clinisys est relu à l'ouverture d'un écran (au plus une fois par minute) et à chaque saisie d'étape ;
- collation `SQL_Latin1_General_CP1256_CI_AS`, `SET DATEFORMAT ymd` à chaque connexion ;
- si Clinisys ne répond pas, l'écran affiche « Clinisys indisponible » et l'inscription est refusée.

| Besoin | Table(s) Clinisys (`dbo`) | Colonnes utiles | Utilisation |
|---|---|---|---|
| Connexion, liste des comptes | `AccessControl` | `UserName`, `Description`, `hasched_password`, `salt`, `hash_iterations`, `Actif`, `expire`, `CompteExpire`, `dateExpirationCompte` | Vérification du mot de passe (PBKDF2) et liste des comptes actifs dans Paramétrage |
| Patient / admission | `Client` | `NumDoss`, `Identifiant`, `NomCli`, `Prenom`, `NumCIN`, `DatNai`, `DatArr`, `DatDep`, `NumCha`, `MedTrait` | Recherche, identité, CIN, tous les dossiers d'un patient (même `Identifiant`) |
| Service de soin | `chambre` → `prmEtage` | `chambre.NumCha`, `chambre.Etage` → `prmEtage.etage`, `prmEtage.libelle` | Service du patient (ex. « REANIMATION ») et référentiel des services ; `C1`, `C4`, `UR` = patients externes |
| Réception | `Reception` | `NumSer`, `LibRecep` | Libellé de réception |
| Médecin | `Medecin` | `CodMed`, `NomMed` | Médecin traitant / prescripteur |
| Demande de sang DMI (A1, A5) | `Demande_Sang_Maroc` | `ID` (n° de demande), `NumDoss`, `Diagnostic`, `determination1/2`, `GlobuleRouge`, `Plaquette`, `Plasma`, `Medecin_prescripteur`, `date_create`, `Etat` | Préremplissage de l'inscription. `Etat` : `S` supprimée, `D` non réceptionnée, `V` réceptionnée |
| Panier dépôt fournisseur | `Panier_Depot_Frs` | `numDemande` | Patient d'une poche, encart Clinisys |
| Réception pharmacie (C5) | `FactureBD_PR` + `MvtStoBD_PR` | `typbon = 'BD'`, `numaffiche` (« BD… »), `numDemandeS`, `Reffrs` ; `Identifiant` (n° de poche), `DatPer` (péremption) | C5 enregistrée automatiquement dès que le bon BD existe |
| Sortie patient (C8) | `facture` + `mvtsto` | `typbon = 'PA'`, `numdoss` | C8 enregistrée automatiquement |
| Retour fournisseur | `FactureBD_PR` + `MvtStoBD_PR` | `typbon = 'PR'`, `NumBon_Depot` (BD d'origine) | Poche marquée « retournée » |
| Stock banque de sang | `Stock` (+ `DepSto`, `Depot`) | `coddep = 'BS'`, quantité > 0 | Écran Stock ; une poche est périmée le jour même de sa péremption |

Jointures patient : `Client.NumCha = chambre.NumCha`, `chambre.Etage = prmEtage.etage`,
`Client.MedTrait = Medecin.CodMed`.

Les scripts [`docs/sql/01_decouverte_schema.sql`](docs/sql/01_decouverte_schema.sql) et
[`docs/sql/02_recherche_tables_demande_sang.sql`](docs/sql/02_recherche_tables_demande_sang.sql)
(SELECT uniquement) ont servi à identifier ces tables.

## 9. Organisation du code

| Fichier / dossier | Rôle |
|---|---|
| `config/demandesang.php` | Étapes du circuit, rôles, urgences, produits, motifs, seuils par défaut |
| `app/Services/Circuit.php` | État des étapes, enregistrement, remises, corrections, annulation |
| `app/Services/SyncClinisys.php` | Étapes pharmacie déduites de Clinisys (C5, C8, retours) |
| `app/Services/SuiviDemande.php`, `EtatEcran.php` | Tableau de suivi (étape bloquée, responsable, retard) |
| `app/Services/Alertes.php`, `Indicateurs.php` | Alertes et indicateurs |
| `app/Services/DmiNonInscrites.php`, `PochesEnAttente.php`, `CinPatients.php`, `ServicesClinisys.php` | DMI non inscrites, poches, CIN, services de soin |
| `app/Services/Referentiels.php`, `Journal.php`, `EtatInstallation.php` | Services, paramètres, journal, état de l'installation |
| `app/Auth/*` | Connexion Clinisys ou PIN, rôles |
| `app/Clinisys/*` | Lectures Clinisys : `Patients`, `DemandesDmi`, `Pharmacie`, `Comptes`, `Medecins` |
| `app/Demo/ClinisysDemo.php` | Clinisys fictif (`DS_DEMO=true`) |
| `app/Support/Clinisys.php` | Connexion et noms qualifiés Clinisys, OPENQUERY via serveur lié |
| `app/Http/Controllers/*` | Accueil, Demande, Cts, Pharmacie, Stock, Suivi, Admin, Auth, Langue |
| `app/Http/Middleware/*` | `Role` (rôles), `JournaliserConsultation` (journal), `Langue` (fr / ar), `MesurePage` (temps de réponse) |
| `app/Models/*` | Modèles Eloquent des tables `ds_*` |
| `database/migrations/*` | Tables `ds_*` et tables Laravel |
| `resources/views/*` | Écrans Blade ; CSS et JS dans `public/css/app.css` et `public/js/app.js` |
| `lang/` | Traductions (arabe : `ar.json`, assemblé depuis `ar_parts/` par `fusionner.php`) |
| `routes/console.php` | Commandes `ds:services-clinisys`, `ds:cin`, `ds:sync-clinisys` |
| `deploiement/`, `mise-a-jour.bat`, `CORRIGER-403.bat` | Installation et mise à jour sur IIS |
| `routes/web.php` | Routes et rôles requis |
| `tests/Feature/CircuitTest.php`, `ClinisysTest.php` | Tests du circuit complet et des lectures Clinisys (simulées) |

## 10. Documentation (`docs/`)

| Fichier | Contenu |
|---|---|
| [`docs/CAHIER_DES_CHARGES.md`](docs/CAHIER_DES_CHARGES.md) | Cahier des charges (problème, acteurs, circuit, écrans, indicateurs) |
| `docs/Diagramme de procédure des demandes de sang - corrigé.drawio` | Diagramme de la procédure qualité (draw.io, corrigé) |
| `docs/Diagramme de procédure des demandes de sang.drawio.pdf` | Le même diagramme en PDF |
| [`docs/sql/01_decouverte_schema.sql`](docs/sql/01_decouverte_schema.sql) | Découverte du schéma Clinisys (SELECT uniquement) |
| [`docs/sql/02_recherche_tables_demande_sang.sql`](docs/sql/02_recherche_tables_demande_sang.sql) | Recherche des tables demandes de sang / pharmacie (SELECT uniquement ; valeurs d'exemple anonymisées) |
| [`docs/sql/03_creer_base_demandesang_app.sql`](docs/sql/03_creer_base_demandesang_app.sql) | Création de la base dédiée `demandesang_app` |
| [`README-demandesang.md`](README-demandesang.md) | Mode d'emploi technique d'origine |
