<?php

namespace App\Clinisys;

use App\Support\Clinisys as C;
use Illuminate\Support\Facades\Cache;

/** Médecins de Clinisys (table Medecin, lecture seule) : liste de choix « à la charge du médecin ». */
class Medecins
{
    /** @return string[] noms des médecins, triés, gardés 1 h en cache */
    public function noms(): array
    {
        if (! config('demandesang.clinisys_actif')) {
            return [];
        }

        return json_decode(Cache::remember('clinisys.medecins', 3600, fn () => json_encode(array_values(array_unique(array_filter(array_map(
            fn ($m) => trim((string) $m->NomMed),
            C::distant('SELECT NomMed FROM '.C::local('erp', 'dbo.Medecin')." WITH (NOLOCK) WHERE ISNULL(NomMed, '') <> '' ORDER BY NomMed")
        ))))))) ?? [];
    }
}
