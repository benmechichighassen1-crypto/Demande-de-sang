<?php

return [

    // Base propre à l'application (actions, remises, poches, comptes, journal, sessions).
    'default' => env('DB_CONNECTION', 'sqlsrv'),

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'demandesang_app'),
            'username' => env('DB_USERNAME'),
            'password' => env('DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => env('DB_ENCRYPT', 'yes'),
            'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'true'),
            // MARS inutile ici (une requête à la fois) et coûteux : 2 fois plus d'allers-retours réseau.
            'multiple_active_result_sets' => false,
        ],

        // Clinisys en lecture seule (DMI / ERP et pharmacie, sur le même serveur
        // ou via un serveur lié, voir CLINISYS_PREFIXE).
        'clinisys' => [
            'driver' => 'sqlsrv',
            'host' => env('CLINISYS_HOST', '192.168.1.154'),
            'port' => env('CLINISYS_PORT', '1433'),
            // Base ouverte à la connexion : la base ERP en direct, ou une base locale (ex. master) avec un serveur lié.
            'database' => env('CLINISYS_DATABASE', env('CLINISYS_BASE_ERP', 'Gclinique_Maroc')),
            'username' => env('CLINISYS_USERNAME'),
            'password' => env('CLINISYS_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'encrypt' => env('CLINISYS_ENCRYPT', 'yes'),
            'trust_server_certificate' => env('CLINISYS_TRUST_SERVER_CERTIFICATE', 'true'),
            'multiple_active_result_sets' => false,
            // Délai maximal d'une requête (s). La lecture seule est garantie par les droits du compte SQL.
            'options' => defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT') ? [PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 30] : [],
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
    ],

];
