# Traçabilité des demandes de sang — cahier des charges (v1, brouillon)

## 1. Problème à résoudre
Aujourd'hui, une demande de sang traverse plusieurs mains (service, coursier, banque de sang,
pharmacie) sans preuve de passage. Exemple typique : le major affirme avoir saisi et remis la
demande, le coursier affirme ne pas l'avoir reçue. Personne ne peut trancher.

**Objectif :** suivre chaque demande de bout en bout, avec à chaque étape **qui**, **quand** et
**quoi**, et voir à tout moment l'état de la demande et des poches en stock.

Phase 1 (v1) : application web responsive, utilisable depuis le navigateur d'un smartphone. Phase 2 (v2, plus tard) : ajout d'une application Android native.

## 2. Principe général
- **Clinisys reste la source.** L'application lit, sans jamais y écrire, les données de Clinisys
  (module DMI et module pharmacie), via un serveur lié vers notre base. Les tables et requêtes
  seront fournies par l'équipe.
- **L'application ajoute ce que Clinisys ne trace pas** : les remises de main à main (service →
  coursier → banque de sang → pharmacie) et les actions saisies par chaque acteur.
- **Toute action est horodatée et signée** avec l'identifiant de la personne connectée. Rien ne
  se supprime : une erreur se corrige par une nouvelle action, l'historique reste visible.

## 3. Acteurs
Référence : procédure qualité « Diagramme procédure demandes de sang » (responsable qualité,
octobre 2026) et registre papier PQ-ORG-017-002 (abandonné dès le déploiement : remplacé par le registre de l'application).
| Acteur | Rôle dans le circuit |
|---|---|
| Médecin prescripteur | Prescrit la transfusion (nombre de poches, diagnostic) |
| Infirmière du service | Vérifie le stock, vérifie l'identité, saisit la demande Clinisys, prélève les tubes, prépare le dossier, prévient le coursier, inscrit au registre |
| Major / surveillant du service | Valide la demande avant le départ du coursier ; saisit service source / destination |
| Coursier | Transporte la demande au CTS, ramène les poches, remet les documents au surveillant général, porte les poches à la pharmacie |
| Surveillant général / de garde | Reçoit les poches et le bon de distribution, contrôle la conformité, prévient le service ou le patient |
| Agent de pharmacie | Enregistre la réception (bon de dépôt FRS), stocke, délivre au service, fait les prélèvements fournisseur |
| Patient / accompagnant (patient externe) | Ouverture de dossier et paiement du montant prévisionnel |
| Direction / qualité | Consultation, indicateurs, export, audit du registre |

## 4. Service source et service de destination (saisie manuelle)
Le parcours du patient (consultation, urgences, hospitalisation…) ne permet pas de déduire
le bon service depuis Clinisys. Ces informations sont donc **saisies manuellement par le major
du service** dans l'application, sur chaque demande :
- **Service source** : d'où vient le patient (ex. consultation 1er étage, urgences, externe).
- **Service de destination / transfert** : où le patient sera transfusé (ex. hospitalisation
  2e étage), avec possibilité de modifier si le patient est transféré entre-temps.
- Le **service qui a saisi la demande** dans le DMI reste lu depuis Clinisys, pour comparaison.

Chaque saisie ou modification est horodatée et signée comme toute autre action.
Exemple : demande saisie au 1er étage, patient transféré au 2e : la fiche affiche
« Source : Consultation 1er — Destination : Hospitalisation 2e (transfert le … par … ) ».
Les services source et destination servent de filtres dans tous les écrans et indicateurs.

## 5. Circuit d'une demande et étapes tracées
Conforme à la procédure qualité. « Clinisys » = lu automatiquement ; « Appli » = saisi dans l'application.

### Phase A — Au service (avant le départ de la demande)
| # | Étape | Qui | Source | Contrôle dans l'application |
|---|---|---|---|---|
| A1 | Prescription de transfusion | Médecin | Clinisys DMI | — |
| A2 | Vérification de disponibilité des poches **par nom du patient** | Infirmière | Appli (recherche stock) | Si une poche est déjà disponible pour ce patient : **alerte, pas de nouvelle demande** |
| A3 | Patient externe : ouverture de dossier + paiement du montant prévisionnel | Patient / accompagnant | Appli | Case « paiement fait » + n° de reçu obligatoires pour un externe |
| A4 | Vérification de l'identité du patient (étiquette) | Infirmière | Appli | Case à cocher |
| A5 | Saisie de la demande dans Clinisys | Infirmière | Clinisys DMI | Nombre exact de poches et diagnostic **obligatoires** |
| A6 | Prélèvement des tubes (1 tube si carte de groupage, sinon 2) | Infirmière | Appli | Carte de groupage oui/non → nombre de tubes |
| A7 | Préparation du dossier : demande papier + Clinisys, analyses précédentes, reçu de paiement | Infirmière | Appli | Liste de pièces à cocher |
| A8 | Inscription au registre + **nature de la demande** : poches gardées en instance au CTS **ou** acheminées dès préparation | Infirmière | Appli | Remplace entièrement le registre papier PQ-ORG-017-002, abandonné dès le déploiement |
| A9 | Coursier prévenu | Infirmière | Appli | Heure d'appel |
| A10 | Validation de la demande par le surveillant | Major / surveillant | Appli | Bloque le départ tant que non validée |
| A11 | Remise du dossier au coursier ↔ prise en charge | Infirmière → coursier | Appli | **Double validation** (remise + confirmation) |

### Phase B — Au centre de transfusion sanguine (CTS)
| # | Étape | Qui | Source |
|---|---|---|---|
| B1 | Demande déposée au CTS (le coursier garde la fiche Clinisys jusqu'au retour) | Coursier | Appli |
| B2 | Poches en instance au CTS (si demandé) / poches prêtes | Coursier | Appli |

### Phase C — Retour à la clinique
| # | Étape | Qui | Source | Contrôle |
|---|---|---|---|---|
| C1 | Livraison des poches à la clinique | Coursier | Appli | Heure d'arrivée |
| C2 | Remise du bon de distribution + fiche Clinisys au surveillant général / de garde | Coursier → surveillant | Appli | **Double validation** |
| C3 | Contrôle de conformité poche ↔ demande (identité, produit, nombre) | Surveillant général | Appli | Conforme / non conforme + motif |
| C4 | Transport des poches à la pharmacie | Coursier → pharmacie | Appli | **Double validation** |
| C5 | Enregistrement de la réception (bon de dépôt FRS « BD… », dépôt BS) | Agent pharmacie | Clinisys pharmacie | Rapprochement automatique avec la demande |
| C6 | Service / patient prévenu | Surveillant général | Appli | Heure de l'appel |
| C7 | Demande interne du service | Infirmière | Clinisys DMI | — |
| C8 | Récupération de la poche à la pharmacie (sortie de stock patient) | Service ↔ pharmacie | Clinisys pharmacie + Appli | **Double validation** |
| C9 | Accusé de réception dans le service | Service demandeur | Appli | Clôt la traçabilité |
| C10 | Transfusion faite / poche non utilisée (→ prélèvement fournisseur) | Infirmière | Appli | Motif si non utilisée |

**Les remises de main à main** (A11, C2, C4, C8) exigent la validation des deux personnes.
Tant que le receveur n'a pas confirmé, la demande reste « remise, non confirmée » et passe en
alerte. C'est ce qui règle le cas « j'ai remis / je n'ai rien reçu ».

Option : **bon imprimé avec QR code**, scanné par le coursier à chaque remise.

### Écarts entre la procédure qualité et le besoin exprimé
- La procédure ne prévoit pas le **prélèvement fournisseur** (poche périmée, patient non venu) : ajouté (§ 6).
- La procédure s'arrête à l'accusé de réception : l'étape C10 (transfusion faite / poche non utilisée) est ajoutée pour boucler le stock.
- Le registre papier PQ-ORG-017-002 n'est plus utilisé dès le déploiement : le registre de l'application le remplace entièrement (impression possible).
- Le coursier et le surveillant de garde communiquent et valident aujourd'hui par WhatsApp : ces échanges sont remplacés par les validations de l'application sur smartphone.

## 6. Gestion du stock : deux types de sortie
Une poche peut quitter le stock de deux façons, et **les deux sont comptées** :
| Type de sortie | Cas | Source |
|---|---|---|
| **Sortie patient** | Poche délivrée au service pour le patient (demande interne DMI) | Clinisys pharmacie |
| **Prélèvement fournisseur** | Poche non consommée : retour à la banque de sang, poche périmée, patient non venu, transfusion annulée… | Clinisys pharmacie (prélèvement fournisseur) |

Pour chaque prélèvement fournisseur, un **motif** est obligatoire dans l'application
(périmée, patient non venu, annulée, autre + commentaire).
Pour identifier les tables, un accès au module Pharmacie limité à l'action « prélèvement
fournisseur » sera fourni : on fera un prélèvement de test et on retrouvera où il est enregistré.

Calcul du stock : **Stock = Réceptions − Sorties patient − Prélèvements fournisseur.**
L'écran Stock distingue : en stock, consommées (sortie patient), retournées / non consommées
(prélèvement fournisseur). Un indicateur suit le taux de poches non consommées et leurs motifs.

## 7. Écrans (v1)
0. **Vérification avant demande** : recherche par nom / dossier dans le stock Banque de sang
   (étape A2) ; si une poche est disponible pour ce patient, l'application le signale.
1. **Tableau des demandes** : une ligne par demande, avec son état actuel (dernière étape),
   l'heure de chaque étape et le temps écoulé. Colonne **Actions** pour saisir l'étape suivante.
2. **Recherche** par numéro de dossier ou nom du patient : demandes du patient et disponibilité
   des poches en stock pour lui.
3. **Stock banque de sang** : poches en stock / consommées / retournées (prélèvement fournisseur),
   filtres par date, service source, service de destination, groupe sanguin, type de produit, motif.
4. **Fiche demande** : chronologie complète (qui, quand, quoi), commentaires, impression du bon.
5. **Alertes** : demandes bloquées trop longtemps à une étape (seuils à définir), remises non
   confirmées, poches proches de la péremption.
6. **Indicateurs / export Excel** : délais moyens par étape, demandes perdues ou en retard.

## 8. Données propres à l'application (base dédiée, schéma app)
- `app.ds_actions` : id, n° demande Clinisys, n° dossier, étape, acteur (identifiant), date/heure,
  commentaire, poste/appareil. Jamais modifiée ni supprimée.
- `app.ds_services_demande` : service source et service de destination saisis par le major,
  avec l'historique des transferts (qui, quand).
- `app.ds_remises` : remise de main à main (donneur, receveur, heure remise, heure confirmation).
- `app.ds_parametres` : seuils d'alerte par étape et par degré d'urgence.
- Vues de lecture Clinisys : `app.vw_cli_demandes_sang` (DMI), `app.vw_cli_receptions_bs`
  (dépôt fournisseur Banque de sang), `app.vw_cli_demandes_internes`, `app.vw_cli_sorties_stock` (sorties patient et prélèvements fournisseur, avec leur type),
  `app.vw_cli_patients`. Leurs requêtes seront écrites à partir des tables fournies.

## 9. Technique
Même architecture que l'application « extras bloc » : Laravel, SQL Server, connexion avec
l'identifiant ERP / Clinisys, rôles par middleware, journal des actions. Pensée dès le départ
pour être utilisable sur téléphone (écrans simples, gros boutons).
- **Phase 1 (v1)** : application web responsive, utilisée depuis un poste fixe ou le navigateur d'un smartphone.
- **Phase 2 (v2, plus tard)** : ajout d'une application Android native, branchée sur le même serveur Laravel.

Les étapes de validation du coursier et du surveillant de garde doivent fonctionner sur smartphone
(web responsive) : elles remplacent les échanges WhatsApp utilisés aujourd'hui.

## 10. Questions ouvertes
1. ~~Le coursier a-t-il un compte Clinisys ? Sinon, faut-il lui créer un accès propre à
   l'application (identifiant + code PIN) ?~~ **Résolu** : oui, le coursier se connecte avec son compte Clinisys (rôle « coursier » à lui donner dans Paramétrage > Comptes).
2. **En grande partie résolu (scripts du 27/10)** : demande DMI = `Demande_Sang_Maroc` (Etat : S supprimée, D non réceptionnée, V réceptionnée) ; bons BD/PR = `FactureBD_PR` (numDemandeS = n° de demande) + `MvtStoBD_PR` (n° de poche) ; stock = `DepSto` dépôt BS. Lien réception ↔ demande : d'après les captures, le bon de dépôt FRS porte le n° de dossier
   et le n° de la demande de sang (« Entête demande de sang », ex. n° 694). À confirmer avec le script 02.
3. Une demande peut-elle donner plusieurs poches ? Le n° de poche est-il saisi dans Clinisys ?
4. Faut-il le QR code dès la v1, ou une simple confirmation par bouton suffit-elle au départ ?
5. ~~Le registre papier PQ-ORG-017-002 peut-il être remplacé par le registre de l'application, ou faut-il garder les deux ?~~ **Résolu** : le registre de l'application remplace entièrement le registre papier, abandonné dès le déploiement.
6. ~~Le coursier et le surveillant de garde valident-ils sur un poste fixe ou sur téléphone ?~~ **Résolu** : sur smartphone (web responsive), en remplacement des échanges WhatsApp actuels.
7. Le prélèvement fournisseur porte-t-il un motif dans Clinisys, ou faut-il le saisir dans l'application ?
8. Quels délais maximums par étape avant alerte (urgence vitale / programmée) ?
