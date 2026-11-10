<?php

namespace App\Services;

use App\Clinisys\Pharmacie;
use App\Models\Demande;
use App\Support\Clinisys as C;
use Illuminate\Support\Facades\Cache;

/**
 * Poches qui attendent déjà en pharmacie (stock BS Clinisys, non périmées, affectées à un dossier) alors qu'une
 * autre demande est en cours dans l'appli pour le même dossier : à utiliser avant de redemander.
 * Le stock est le même que celui de l'écran Stock (lu au plus une fois par minute).
 */
class PochesEnAttente
{
    public function __construct(private Pharmacie $pharmacie) {}

    /** @return array<int, array{poche: object, demande: Demande}> */
    public function liste(): array
    {
        if (! $this->pharmacie->branche()) {
            return [];
        }
        $stock = C::essayer(fn () => json_decode(Cache::remember('clinisys.stock_bs', 60, fn () => json_encode($this->pharmacie->stockBS()))), []) ?? [];
        $parDossier = collect($stock)->filter(fn ($p) => empty($p->perimee) && ! empty($p->NumDoss))->groupBy(fn ($p) => trim((string) $p->NumDoss));
        if ($parDossier->isEmpty()) {
            return [];
        }

        $liste = [];
        foreach (Demande::where('statut', 'en_cours')->whereIn('num_doss', $parDossier->keys()->all())->orderBy('cree_le')->get() as $d) {
            foreach ($parDossier[trim((string) $d->num_doss)] ?? [] as $p) {
                // La poche reçue pour cette demande-ci n'est pas « en attente » : c'est son circuit normal.
                if ((string) ($p->numDemande ?? '') !== (string) $d->num_demande_clinisys) {
                    $liste[] = ['poche' => $p, 'demande' => $d];
                }
            }
        }

        return $liste;
    }
}
