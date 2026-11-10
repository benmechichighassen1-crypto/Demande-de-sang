<?php

namespace App\Services;

use App\Clinisys\DemandesDmi;
use App\Models\Demande;
use App\Support\Clinisys as C;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Demandes de sang saisies dans le DMI Clinisys (état D, non réceptionnée, ou V reçue hors registre) sur les
 * N derniers jours et pas encore inscrites au registre de l'appli.
 * La lecture Clinisys est gardée 60 s en cache ; le rapprochement avec l'appli,
 * lui, est refait à chaque appel (une demande inscrite disparaît aussitôt).
 */
class DmiNonInscrites
{
    public function __construct(private DemandesDmi $dmi, private Referentiels $ref, private \App\Clinisys\Pharmacie $pharmacie)
    {
    }

    public function jours(): int
    {
        return (int) $this->ref->parametre('dmi_jours', config('demandesang.dmi_non_inscrites.jours'));
    }

    public function minutes(): int
    {
        return (int) $this->ref->parametre('dmi_minutes', config('demandesang.dmi_non_inscrites.minutes'));
    }

    /**
     * @return array<int, object> demandes DMI, les plus anciennes d'abord ; ->hors_registre = true pour une demande
     * déjà réceptionnée en pharmacie (état V) sans avoir été inscrite : à régulariser.
     */
    public function liste(): array
    {
        if (! $this->dmi->actif()) {
            return [];
        }
        $jours = $this->jours();
        // JSON : le cache Laravel ne désérialise pas les objets stdClass.
        $dmi = C::essayer(fn () => json_decode(Cache::remember("clinisys.dmi_dv.$jours", 60,
            fn () => json_encode($this->dmi->dernieres($jours * 24, ['D', 'V'])))), []) ?? [];
        if ($dmi === []) {
            return [];
        }

        $inscrites = Demande::whereIn('num_demande_clinisys', array_map(fn ($d) => (string) $d->ID, $dmi))
            ->pluck('num_demande_clinisys')->map(fn ($n) => (string) $n)->all();
        $liste = array_values(array_filter($dmi, fn ($d) => ! in_array((string) $d->ID, $inscrites, true)));
        // Reçue hors registre : état V, ou bon de dépôt BD déjà saisi (le DMI reste souvent en D après le BD).
        $enD = array_values(array_map(fn ($d) => (string) $d->ID, array_filter($liste, fn ($d) => trim((string) $d->Etat) !== 'V')));
        $avecBd = $enD === [] ? [] : (C::essayer(fn () => Cache::remember('clinisys.bd_par_dmi.'.md5(implode(',', $enD)), 60,
            fn () => $this->pharmacie->pochesParDemande($enD)), []) ?? []);
        foreach ($liste as $d) {
            $d->hors_registre = trim((string) $d->Etat) === 'V' || ($avecBd[(string) $d->ID] ?? 0) > 0;
        }
        usort($liste, fn ($a, $b) => strcmp((string) $a->date_create, (string) $b->date_create));

        return $liste;
    }

    /** Réceptionnées en pharmacie (V) sans inscription au registre. */
    public function horsRegistre(): array
    {
        return array_values(array_filter($this->liste(), fn ($d) => $d->hors_registre));
    }

    public static function depuis(object $d): Carbon
    {
        return Carbon::parse($d->date_create);
    }
}
