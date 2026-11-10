<?php

namespace App\Clinisys;

use App\Support\Clinisys as C;
use Illuminate\Support\Facades\Cache;

/**
 * Comptes Clinisys actifs (AccessControl : UserName, Description), pour choisir
 * le login d'un compte de l'appli. Lecture seule ; jamais les colonnes de mot de passe.
 */
class Comptes
{
    /** @return array<int, object{UserName: string, Description: string}> triés par login, gardés 10 min en cache */
    public function actifs(): array
    {
        if (! config('demandesang.clinisys_actif')) {
            return [];
        }
        $table = C::local('erp', config('demandesang.auth.table'));

        // JSON : le cache Laravel ne désérialise pas les objets stdClass.
        return json_decode(Cache::remember('clinisys.comptes_actifs', 600, fn () => json_encode(array_map(
            fn ($c) => ['UserName' => trim((string) $c->UserName), 'Description' => trim((string) $c->Description)],
            C::distant("SELECT UserName, Description FROM $table WITH (NOLOCK)
                        WHERE Actif = 1 AND ISNULL(expire, 0) <> 1 AND ISNULL(UserName, '') <> '' ORDER BY UserName")
        ))));
    }
}
