<?php

// Messages de validation en français (règles utilisées par l'application).
return [
    'accepted' => 'Le champ :attribute doit être coché.',
    'array' => 'Le champ :attribute est invalide.',
    'boolean' => 'Le champ :attribute est invalide.',
    'date' => 'Le champ :attribute n\'est pas une date valide.',
    'date_format' => 'Le champ :attribute doit avoir le format :format.',
    'distinct' => 'Le champ :attribute est en double.',
    'exists' => 'Le champ :attribute est invalide.',
    'in' => 'Le champ :attribute est invalide.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'max' => ['numeric' => 'Le champ :attribute ne doit pas dépasser :max.', 'string' => 'Le champ :attribute ne doit pas dépasser :max caractères.', 'array' => 'Le champ :attribute ne doit pas avoir plus de :max éléments.'],
    'min' => ['numeric' => 'Le champ :attribute doit être au moins :min.', 'string' => 'Le champ :attribute doit faire au moins :min caractères.', 'array' => 'Le champ :attribute doit avoir au moins :min élément(s).'],
    'regex' => 'Le format du champ :attribute est invalide.',
    'required' => 'Le champ :attribute est obligatoire.',
    'required_if' => 'Le champ :attribute est obligatoire quand :other vaut :value.',
    'string' => 'Le champ :attribute doit être un texte.',

    'attributes' => [
        'username' => 'identifiant', 'password' => 'mot de passe', 'num_doss' => 'n° de dossier', 'nom_patient' => 'nom du patient',
        'num_demande_clinisys' => 'n° de demande Clinisys', 'urgence' => 'degré d\'urgence', 'nature' => 'nature de la demande',
        'produit' => 'produit', 'nb_poches' => 'nombre de poches', 'diagnostic' => 'diagnostic', 'numero_recu' => 'n° de reçu',
        'identite_ok' => 'identité vérifiée', 'carte_groupage' => 'carte de groupage', 'pieces.demande' => 'demande papier + fiche Clinisys',
        'pieces.recu' => 'reçu de paiement', 'service_source' => 'service source', 'service_destination' => 'service de destination',
        'etat_cts' => 'état au CTS', 'conforme' => 'conformité', 'motif_non_conformite' => 'motif de non-conformité',
        'poches' => 'poches', 'poches.*.numero' => 'n° de poche', 'bon_depot' => 'n° de bon de dépôt', 'poches_delivrees' => 'poches délivrées',
        'issue' => 'issue de la transfusion', 'motif' => 'motif', 'commentaire' => 'commentaire', 'pin' => 'code PIN', 'roles' => 'rôles',
    ],
    'values' => ['conforme' => ['non' => 'non conforme'], 'motif' => ['autre' => 'autre']],
];
