<?php

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => null,
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'comptes',
        ],
    ],

    // Comptes Clinisys (AccessControl, lecture seule) ou comptes locaux (ds_comptes).
    'providers' => [
        'comptes' => [
            'driver' => 'demandesang',
        ],
    ],

    'passwords' => [],

    'password_timeout' => 10800,

];
