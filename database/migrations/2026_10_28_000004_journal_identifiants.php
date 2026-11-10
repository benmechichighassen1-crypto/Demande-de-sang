<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Journal des consultations : on ne garde que des identifiants (route, n° de demande, dossier,
 * n° DMI), plus jamais la demande entière (patient, diagnostic, actions…). Les lignes existantes
 * sont nettoyées ; demande_id permet de lister qui a consulté une demande.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ds_journal', function (Blueprint $table) {
            $table->foreignId('demande_id')->nullable()->index();
        });

        DB::table('ds_journal')->where('action', 'page')->orderBy('id')->chunkById(200, function ($lignes) {
            foreach ($lignes as $l) {
                $d = json_decode((string) $l->details, true) ?: [];
                $propre = ['route' => $d['route'] ?? null];
                $demandeId = null;
                foreach (($d['parametres'] ?? []) as $nom => $valeur) {
                    if (is_array($valeur)) {
                        // Ancien format : objet sérialisé (demande, remise, action…) → son identifiant seulement.
                        if ($nom === 'demande') {
                            $demandeId = $valeur['id'] ?? null;
                            $propre += ['demande' => $demandeId, 'dossier' => $valeur['num_doss'] ?? null, 'dmi' => $valeur['num_demande_clinisys'] ?? null];
                        } else {
                            $propre[$nom] = $valeur['id'] ?? null;
                        }
                    } elseif (is_scalar($valeur)) {
                        $propre[$nom] = $valeur;
                    }
                }
                DB::table('ds_journal')->where('id', $l->id)->update([
                    'details' => json_encode(array_filter($propre, fn ($v) => $v !== null), JSON_UNESCAPED_UNICODE),
                    'demande_id' => $demandeId,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('ds_journal', fn (Blueprint $table) => $table->dropColumn('demande_id'));
    }
};
