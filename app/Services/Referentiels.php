<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Services de la clinique et paramètres (seuils d'alerte), mémorisés le temps de la requête. */
class Referentiels
{
    private ?array $services = null;

    private ?array $parametres = null;

    /** @return array<string, string> code => libellé (services actifs) */
    public function services(bool $tous = false): array
    {
        $this->services ??= DB::table('ds_services')->orderBy('ordre')->orderBy('libelle')->get()->all();

        $liste = [];
        foreach ($this->services as $s) {
            if ($tous || $s->actif) {
                $liste[$s->code] = $s->libelle;
            }
        }

        return $liste;
    }

    public function service(?string $code): string
    {
        return $code === null ? '—' : ($this->services(true)[$code] ?? $code);
    }

    public function parametre(string $cle, $defaut = null)
    {
        $this->parametres ??= DB::table('ds_parametres')->pluck('valeur', 'cle')->all();

        return $this->parametres[$cle] ?? $defaut;
    }

    /** Seuil en minutes pour une urgence et une phase (service, cts, retour, remise). */
    public function seuil(string $urgence, string $phase): int
    {
        return (int) $this->parametre("seuil.$urgence.$phase", config("demandesang.seuils_defaut.$urgence.$phase", 60));
    }
}
