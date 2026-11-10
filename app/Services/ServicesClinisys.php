<?php

namespace App\Services;

use App\Support\Clinisys as C;
use Illuminate\Support\Facades\DB;

/**
 * Services source / destination = services de soin de Clinisys (table prmEtage :
 * etage = code, libelle = « REANIMATION », « HOSPITALISATION 2 EME ETAGE »…), la même
 * liste que l'étage des chambres (chambre.Etage). Lecture seule dans Clinisys.
 *
 * La synchronisation recopie la liste dans ds_services ; les autres codes (anciens
 * services de l'appli) sont désactivés, jamais supprimés : les demandes qui les
 * utilisent gardent leur libellé.
 */
class ServicesClinisys
{
    /** Étages Clinisys qui ne sont pas des services de soin. */
    public const EXCLUS = ['99']; // PHARMACIE CENTRALE

    /** @return array<string, string> code => libellé */
    public function liste(): array
    {
        $liste = [];
        foreach (C::distant('SELECT etage, libelle FROM '.C::local('erp', 'dbo.prmEtage').' WITH (NOLOCK) ORDER BY etage') as $e) {
            $code = strtoupper(trim((string) $e->etage));
            if ($code !== '' && ! in_array($code, self::EXCLUS, true) && preg_match('/^[A-Z0-9_]{1,20}$/', $code)) {
                $liste[$code] = trim((string) $e->libelle);
            }
        }

        return $liste;
    }

    /** @return array{ajoutes: int, mis_a_jour: int, desactives: string[]} */
    public function synchroniser(): array
    {
        $clinisys = $this->liste();
        if ($clinisys === []) {
            throw new \RuntimeException('Aucun service de soin lu dans Clinisys : liste inchangée.');
        }
        $existants = DB::table('ds_services')->pluck('code')->all();

        return DB::transaction(function () use ($clinisys, $existants) {
            $ordre = 0;
            $ajoutes = 0;
            foreach ($clinisys as $code => $libelle) {
                $ajoutes += in_array($code, $existants, true) ? 0 : 1;
                DB::table('ds_services')->updateOrInsert(['code' => $code], ['libelle' => $libelle, 'actif' => true, 'ordre' => $ordre += 10]);
            }
            $desactives = array_values(array_diff($existants, array_keys($clinisys)));
            DB::table('ds_services')->whereIn('code', $desactives)->update(['actif' => false]);
            Journal::ecrire('services_clinisys', ['services' => array_keys($clinisys), 'desactives' => $desactives]);

            return ['ajoutes' => $ajoutes, 'mis_a_jour' => count($clinisys) - $ajoutes, 'desactives' => $desactives];
        });
    }
}
