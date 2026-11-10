<?php

/*
 * Paramètres de l'application de traçabilité des demandes de sang.
 *
 * Clinisys (DMI et pharmacie) est lu en lecture seule par la connexion
 * « clinisys » (config/database.php). L'application n'y écrit jamais : ses
 * propres données (actions, remises, poches, services, comptes, journal) sont
 * dans la base dédiée (DB_DATABASE, tables ds_*).
 */

return [

    'connexion' => env('CLINISYS_CONNEXION', 'clinisys'),

    // false : aucune lecture Clinisys (poste de développement, démonstration).
    // La demande se crée alors avec le n° de dossier et le nom saisis à la main.
    'clinisys_actif' => (bool) env('CLINISYS_ACTIF', true),

    'etablissement' => env('DS_ETABLISSEMENT', 'CLINIQUE INTERNATIONALE IBN SINA TANGER'),

    // Préfixe optionnel de serveur lié, par exemple « [SRV_ERP] ». Vide = même serveur.
    'prefixe' => env('CLINISYS_PREFIXE', ''),

    'bases' => [
        'erp' => env('CLINISYS_BASE_ERP', 'Gclinique_Maroc'),
        // Base du module pharmacie : à confirmer (scripts 01 et 02).
        'pharmacie' => env('CLINISYS_BASE_PHARMACIE', env('CLINISYS_BASE_ERP', 'Gclinique_Maroc')),
    ],

    'collation' => 'SQL_Latin1_General_CP1256_CI_AS',

    'auth' => [
        'table' => env('CLINISYS_TABLE_COMPTES', 'dbo.AccessControl'),
        // auto | pbkdf2-sha512 | pbkdf2-sha256 | pbkdf2-sha1 (même schéma que l'appli oncologie).
        'hash' => env('CLINISYS_HASH', 'auto'),
        // Administrateurs de l'application (UserName Clinisys ou compte local), séparés par des virgules.
        'admins' => array_filter(array_map('trim', explode(',', (string) env('DS_ADMINS', '')))),
    ],

    /*
     * Rôles de l'application. Un compte peut en avoir plusieurs.
     */
    'roles' => [
        'infirmiere' => 'Infirmière du service',
        'major' => 'Major du service',
        'coursier' => 'Coursier',
        'surveillant' => 'Surveillant général / de garde',
        'pharmacie' => 'Agent de pharmacie',
        'direction' => 'Direction / qualité (consultation)',
    ],

    'urgences' => [
        'vitale' => 'Urgence vitale',
        'urgente' => 'Urgente',
        'programmee' => 'Programmée',
    ],

    // A8 : nature de la demande inscrite au registre.
    'natures' => [
        'acheminement' => 'À ramener directement (dès qu\'elles sont prêtes)',
        'instance' => 'À laisser en instance au CTS (jusqu\'au besoin)',
    ],

    'produits' => [
        'CGR' => 'Culot globulaire (CGR)',
        'PFC' => 'Plasma frais congelé (PFC)',
        'CPS' => 'Concentré plaquettaire standard (CPS)',
        'CPA' => 'Concentré plaquettaire d\'aphérèse (CPA)',
        'AUTRE' => 'Autre produit',
    ],

    // Paiement (A3, et A7 pièces jointes) : reçu de paiement, ou patient à la charge d'un organisme.
    'paiements' => [
        'recu' => ['libelle' => 'Reçu de paiement', 'ref' => 'N° du reçu'],
        'charge' => ['libelle' => 'Patient à la charge de…', 'ref' => 'Nom'],
    ],
    // « À la charge de » : qui paie à la place du patient.
    'charges' => ['medecin' => 'Médecin', 'personnel' => 'Personnel', 'association' => 'Association'],

    'groupes' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],

    // Alerte si la pharmacie n'a pas saisi dans Clinisys le bon de dépôt (après C4) ou la sortie patient
    // (après C5) au bout de N minutes (modifiable dans Paramètres).
    'clinisys_minutes' => 60,

    // Poches gardées en instance au CTS : alerte au-delà de N jours (modifiable dans Paramètres).
    'instance_jours' => 3,

    // Demandes DMI (état D) pas encore inscrites dans l'appli : période affichée (jours)
    // et délai avant alerte (minutes). Modifiables dans Paramètres.
    'dmi_non_inscrites' => ['jours' => 7, 'minutes' => 30],

    // A2 : autres demandes DMI du même patient (tous dossiers) regardées sur N jours,
    // et poches sorties vers lui (bons PA) sur N jours.
    'a2' => ['jours_demandes' => 30, 'jours_sorties' => 3],

    // Services de soin Clinisys (prmEtage.etage) des patients externes : consultation et urgences.
    // « Patient externe » (paiement A3) y est coché par défaut à l'inscription.
    'services_externes' => ['C1', 'C4', 'UR'],

    // Responsable d'une étape : libellé court et couleur du rôle (badges « À faire par : … » et filtre par rôle).
    'roles_badges' => [
        'infirmiere' => ['Infirmière', '#0f766e'],
        'major' => ['Major', '#4338ca'],
        'coursier' => ['Coursier', '#c2410c'],
        'surveillant' => ['Surveillant de garde', '#7c3aed'],
        'pharmacie' => ['Pharmacie (Clinisys)', '#15803d'],
    ],

    // Onglets des écrans en étapes : [libellé court, couleur, icône]. Une couleur par étape, reprise par ses cartes.
    'onglets' => [
        'cts' => [
            'recuperer' => ['À récupérer', '#1d4ed8', '📥'],
            'deposer' => ['À déposer au CTS', '#c2410c', '🚚'],
            'attente' => ['En attente au CTS', '#7c3aed', '⏳'],
            'ramener' => ['À ramener', '#15803d', '✅'],
            'instance' => ['En instance', '#be185d', '🏥'],
        ],
        'pharmacie' => [
            'hors_registre' => ['Hors registre', '#b91c1c', '⚠'],
            'attente_bd' => ['Bon BD à saisir', '#c2410c', '📝'],
            'nouvelles' => ['Nouvelles réceptions', '#15803d', '🆕'],
            'a_delivrer' => ['À délivrer', '#1d4ed8', '💊'],
            'delivrees' => ['Délivrées', '#57534e', '📦'],
        ],
        'accueil' => [
            'remises' => ['À confirmer', '#1d4ed8', '🤝'],
            'dmi' => ['Pas encore inscrites', '#c2410c', '📝'],
            'etapes' => ['Étapes à saisir', '#7f1d1d', '✍'],
            'mes' => ['Mes remises en attente', '#57534e', '⏳'],
        ],
        'alertes' => [
            'retard' => ['Retards', '#c2410c', '⏱'],
            'remise' => ['Remises', '#1d4ed8', '🤝'],
            'conformite' => ['Non-conformités', '#b91c1c', '⚠'],
            'dmi' => ['DMI non inscrites', '#a16207', '📝'],
            'clinisys' => ['Clinisys', '#7c3aed', '💊'],
            'peremption' => ['Péremption', '#be185d', '📅'],
            'retour' => ['Retours', '#0f766e', '↩'],
            'instance' => ['Instance', '#57534e', '🏥'],
        ],
    ],

    // Demande_Sang_Maroc.Etat dans le DMI Clinisys.
    'etats_dmi' => [
        'S' => 'Supprimée',
        'D' => 'Non réceptionnée',
        'V' => 'Réceptionnée',
    ],

    // Cas du patient à l'inscription (diagramme, pages 3 à 5). Externe et hors séjour : ouverture du dossier + paiement (A3).
    'cas_patient' => [
        'hospitalise' => 'Patient hospitalisé',
        'externe' => 'Patient externe (consultation / urgence)',
        'hors_sejour' => 'Hospitalisé : transfusion hors séjour (second dossier)',
    ],

    // Numéros du diagramme ; modifiables dans Paramétrage (clés tel_coursier, tel_surveillant, tel_major).
    'telephones' => [
        'coursier' => ['libelle' => 'Flotte coursier', 'numero' => '06 68 80 17 02'],
        'surveillant' => ['libelle' => 'Surveillant général', 'numero' => '06 68 86 09 46'],
        'major' => ['libelle' => 'Major réanimation / USI', 'numero' => '06 68 82 13 78'],
    ],

    // Poche disponible pour le patient : on la récupère à la pharmacie ; une nouvelle demande exige l'un de ces motifs.
    'motifs_poche_disponible' => [
        'non_compatible' => 'Poche non compatible',
        'quantite' => 'Quantité insuffisante',
        'reservee' => 'Poche réservée à une autre demande',
        'autre' => 'Autre (préciser)',
    ],

    // C10 et prélèvement fournisseur : motif obligatoire.
    'motifs_non_utilisation' => [
        'perimee' => 'Poche périmée',
        'patient_non_venu' => 'Patient non venu',
        'annulee' => 'Transfusion annulée',
        'deces' => 'Décès du patient',
        'non_conforme' => 'Poche non conforme',
        'autre' => 'Autre (préciser)',
    ],

    /*
     * Circuit d'une demande (procédure qualité « Diagramme procédure demandes de sang »).
     *
     * type    : simple  = une personne saisit l'étape ;
     *           remise  = remise de main à main, faite par le donneur puis confirmée
     *                     par le receveur (double validation) ;
     * roles   : qui peut saisir l'étape (le donneur pour une remise) ;
     * receveur: rôles qui peuvent confirmer une remise ;
     * apres   : étapes qui doivent être faites avant ;
     * si      : condition d'application (externe, instance) ;
     * champs  : données saisies avec l'étape (voir resources/views/demandes/partials/champs.blade.php).
     *
     * A1, A2, A5 et A8 sont enregistrées à la création de la demande.
     */
    'etapes' => [
        'A4' => ['libelle' => 'Vérification de l\'identification patient', 'court' => 'Vérification de l\'identification', 'phase' => 'A',
            'type' => 'simple', 'roles' => ['infirmiere', 'major'], 'apres' => [], 'champs' => ['identite_ok']],
        'A6' => ['libelle' => 'Prélèvement de sang', 'court' => 'Prélèvement de sang', 'phase' => 'A',
            'type' => 'simple', 'roles' => ['infirmiere', 'major'], 'apres' => ['A4'], 'champs' => ['carte_groupage']],
        'A7' => ['libelle' => 'Préparation de la demande', 'court' => 'Préparation de la demande', 'phase' => 'A',
            'type' => 'simple', 'roles' => ['infirmiere', 'major'], 'apres' => [], 'champs' => ['pieces']],
        'A9' => ['libelle' => 'Informer le coursier', 'court' => 'Informer le coursier', 'phase' => 'A',
            'type' => 'simple', 'roles' => ['infirmiere', 'major'], 'apres' => [], 'champs' => ['heure']],
        'A10' => ['libelle' => 'Validation de la demande avec le surveillant', 'court' => 'Validation avec le surveillant', 'phase' => 'A',
            'type' => 'simple', 'roles' => ['major'], 'apres' => ['A4', 'A6', 'A7'], 'champs' => ['services']],
        'A11' => ['libelle' => 'Dossier remis au coursier', 'court' => 'Remis au coursier', 'phase' => 'A',
            'type' => 'remise', 'roles' => ['infirmiere', 'major'], 'receveur' => ['coursier'], 'apres' => ['A9', 'A10'], 'champs' => []],

        // A3 (externe / hors séjour) : le coursier vérifie le paiement en recevant le dossier (après A11), avant le transport au CTS.
        'A3' => ['libelle' => 'Vérification du paiement (reçu ou prise en charge)', 'court' => 'Vérification du paiement', 'phase' => 'A',
            'type' => 'simple', 'roles' => ['coursier'], 'si' => 'externe', 'apres' => ['A11'],
            'champs' => ['paiement']],

        'B1' => ['libelle' => 'Transport de la demande au CTS', 'court' => 'Transport de la demande au CTS', 'phase' => 'B',
            'type' => 'simple', 'roles' => ['coursier'], 'apres' => ['A11', 'A3'], 'champs' => []],
        'B2' => ['libelle' => 'Poches prêtes au CTS', 'court' => 'Poches prêtes', 'phase' => 'B',
            'type' => 'simple', 'roles' => ['coursier'], 'apres' => ['B1'], 'champs' => ['etat_cts']],

        'C1' => ['libelle' => 'Livraison des poches à la clinique', 'court' => 'Livraison à la clinique', 'phase' => 'C',
            'type' => 'simple', 'roles' => ['coursier'], 'apres' => ['B2'], 'champs' => []],
        'C2' => ['libelle' => 'Délivrance du bon de distribution + demande Clinisys au surveillant', 'court' => 'Remis au surveillant', 'phase' => 'C',
            'type' => 'remise', 'roles' => ['coursier'], 'receveur' => ['surveillant'], 'apres' => ['C1'], 'champs' => []],
        'C3' => ['libelle' => 'Vérification de la conformité poche / demande', 'court' => 'Vérification de la conformité', 'phase' => 'C',
            'type' => 'simple', 'roles' => ['surveillant'], 'apres' => ['C2'], 'champs' => ['conformite', 'poches']],
        // C4 : déclarée par le coursier seul. C5 et C8 (auto) : avec Clinisys, déduites des bons de la pharmacie
        // (bon de dépôt BD, sortie patient PA) ; la pharmacie ne saisit rien. Sans Clinisys : saisie par la pharmacie.
        'C4' => ['libelle' => 'Transport vers le site de stockage (pharmacie)', 'court' => 'Transport vers la pharmacie', 'phase' => 'C',
            'type' => 'simple', 'roles' => ['coursier'], 'apres' => ['C3'], 'champs' => []],
        'C5' => ['libelle' => 'Enregistrement de réception', 'court' => 'Enregistrement de réception', 'phase' => 'C',
            'type' => 'simple', 'roles' => ['pharmacie'], 'auto' => 'bon de dépôt BD', 'apres' => ['C4'], 'champs' => ['bon_depot']],
        'C6' => ['libelle' => 'Informer le service demandeur / patient', 'court' => 'Informer le service', 'phase' => 'C',
            'type' => 'simple', 'roles' => ['surveillant'], 'apres' => ['C5'], 'champs' => ['heure']],
        'C8' => ['libelle' => 'Récupération de la poche au service de pharmacie', 'court' => 'Récupération en pharmacie', 'phase' => 'C',
            'type' => 'simple', 'roles' => ['pharmacie'], 'auto' => 'sortie patient PA', 'apres' => ['C5'], 'champs' => ['poches_delivrees']],
        'C9' => ['libelle' => 'Accusé de réception', 'court' => 'Accusé de réception', 'phase' => 'C',
            'type' => 'simple', 'roles' => ['infirmiere', 'major'], 'apres' => ['C8'], 'champs' => []],
        'C10' => ['libelle' => 'Transfusion réalisée / poche non utilisée', 'court' => 'Transfusion', 'phase' => 'C',
            'type' => 'simple', 'roles' => ['infirmiere', 'major'], 'apres' => ['C9'], 'champs' => ['issue']],
    ],

    // Libellés des étapes enregistrées à la création, pour la chronologie.
    'etapes_creation' => [
        'A1' => 'Prescription (Clinisys DMI)',
        'A2' => 'Stock vérifié pour le patient',
        'A5' => 'Élaboration de la demande sur Clinisys',
        'A8' => 'Enregistrement sur le registre',
    ],

    // Libellés des actions hors circuit.
    'actions' => [
        'creation' => 'Demande créée',
        'services' => 'Services source / destination',
        'commentaire' => 'Commentaire',
        'correction' => 'Correction',
        'remise_refusee' => 'Remise refusée',
        'remise_confirmee' => 'Remise confirmée',
        'annulation' => 'Demande annulée',
        'prelevement_fournisseur' => 'Prélèvement fournisseur',
    ],

    // Seuils par défaut (minutes) avant alerte, modifiables dans Paramètres (table ds_parametres).
    'seuils_defaut' => [
        'vitale' => ['service' => 30, 'cts' => 60, 'retour' => 30, 'remise' => 10],
        'urgente' => ['service' => 60, 'cts' => 180, 'retour' => 60, 'remise' => 15],
        'programmee' => ['service' => 240, 'cts' => 720, 'retour' => 240, 'remise' => 30],
    ],
    // Une poche en stock à moins de N jours de sa péremption passe en alerte.
    'peremption_jours' => (int) env('DS_PEREMPTION_JOURS', 3),
    // Stock : pastille « Périment dans X jours » (X modifiable à l'écran ; valeur par défaut dans Paramétrage).
    'perime_bientot_jours' => 5,
];
