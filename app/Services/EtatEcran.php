<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Empreinte légère de ce que montrent les écrans de suivi (À faire, CTS, Pharmacie, Alertes). Elle change dès qu'une
 * action, une remise ou une demande change ; l'écran propose alors d'actualiser, sans jamais se recharger seul.
 */
class EtatEcran
{
    public function __construct(private DmiNonInscrites $dmi)
    {
    }

    public function empreinte(): string
    {
        return substr(md5((string) json_encode([
            DB::selectOne("SELECT (SELECT MAX(id) FROM ds_actions) AS a, (SELECT MAX(id) FROM ds_remises) AS r,
                (SELECT COUNT(*) FROM ds_remises WHERE reponse = 'attente') AS ra, (SELECT COUNT(*) FROM ds_demandes WHERE statut = 'en_cours') AS d,
                (SELECT MAX(id) FROM ds_poches) AS p"),
            array_map(fn ($d) => [$d->ID, $d->hors_registre], $this->dmi->liste()),
        ])), 0, 12);
    }
}
