<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Référentiels de départ (services, à compléter dans Paramétrage > Services).
 * En local uniquement : comptes de démonstration à code PIN, un par rôle.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            'URG' => 'Urgences', 'CONS1' => 'Consultation 1er étage', 'HOSP2' => 'Hospitalisation 2e étage',
            'HOSP3' => 'Hospitalisation 3e étage', 'REA' => 'Réanimation', 'BLOC' => 'Bloc opératoire',
            'ONCO' => 'Oncologie / hôpital de jour', 'MAT' => 'Maternité', 'EXT' => 'Patient externe',
        ];
        $ordre = 0;
        foreach ($services as $code => $libelle) {
            DB::table('ds_services')->updateOrInsert(['code' => $code], ['libelle' => $libelle, 'actif' => true, 'ordre' => $ordre += 10]);
        }

        // Comptes fictifs (PIN) : seulement pour les tests et l'instance vidéo de démo (DS_COMPTES_DEMO=1, base demo.sqlite),
        // jamais dans la vraie base demandesang_app.
        if (app()->environment('testing') || env('DS_COMPTES_DEMO')) {
            $demo = [
                'inf' => ['Infirmière (démo)', 'infirmiere', 'HOSP2', false],
                'major' => ['Major (démo)', 'major,infirmiere', 'HOSP2', false],
                'coursier' => ['Coursier (démo)', 'coursier', null, false],
                'surv' => ['Surveillant de garde (démo)', 'surveillant', null, false],
                'pharma' => ['Agent pharmacie (démo)', 'pharmacie', null, false],
                'direction' => ['Direction (démo)', 'direction', null, true],
            ];
            foreach ($demo as $login => [$nom, $roles, $service, $admin]) {
                DB::table('ds_comptes')->updateOrInsert(['user_name' => $login], [
                    'nom' => $nom, 'roles' => $roles, 'service_code' => $service, 'admin' => $admin, 'actif' => true,
                    'pin_hash' => password_hash('1234', PASSWORD_DEFAULT),
                ]);
                if ($service) {
                    DB::table('ds_compte_services')->updateOrInsert(['user_name' => $login, 'service_code' => $service]);
                }
            }
        }
    }
}
