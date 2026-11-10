<?php

namespace App\Services;

use App\Models\Demande;
use App\Support\Clinisys as C;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * N° CIN des demandes qui n'en ont pas encore : lu dans Clinisys (Client.NumCIN, par n° de dossier, une lecture pour toute
 * une liste) puis gardé sur la demande (cin_source = clinisys), pour que les lectures suivantes restent locales.
 * Rien n'est écrit dans Clinisys.
 */
class CinPatients
{
    /** @param iterable<Demande> $demandes complétées sur place (attribut cin) */
    public static function completer(iterable $demandes): void
    {
        if (! config('demandesang.clinisys_actif') || ! self::colonnePresente()) {
            return;
        }
        $sansCin = collect($demandes)->filter(fn ($d) => $d instanceof Demande && blank($d->cin) && filled($d->num_doss));
        if ($sansCin->isEmpty()) {
            return;
        }
        $dossiers = $sansCin->map(fn ($d) => trim((string) $d->num_doss))->unique()->values()->all();
        $trouves = C::essayer(fn () => self::lire($dossiers), []) ?? [];
        foreach ($sansCin as $d) {
            $cin = $trouves[trim((string) $d->num_doss)] ?? '';
            if ($cin !== '') {
                DB::table('ds_demandes')->where('id', $d->id)->whereNull('cin')->update(['cin' => $cin, 'cin_source' => 'clinisys']);
                $d->setAttribute('cin', $cin)->setAttribute('cin_source', 'clinisys');
                $d->syncOriginalAttributes(['cin', 'cin_source']);
            }
        }
    }

    /** Toutes les demandes sans CIN (mise à jour, migration) : nombre de CIN repris de Clinisys. */
    public static function completerTout(): int
    {
        if (! config('demandesang.clinisys_actif') || ! self::colonnePresente()) {
            return 0;
        }
        $avant = DB::table('ds_demandes')->whereNotNull('cin')->count();
        Demande::whereNull('cin')->chunkById(200, fn ($lot) => self::completer($lot));

        return DB::table('ds_demandes')->whereNotNull('cin')->count() - $avant;
    }

    /** @return array<string, string> [n° dossier => CIN en majuscules] */
    private static function lire(array $dossiers): array
    {
        return app(\App\Clinisys\Patients::class)->cinParDossiers($dossiers);
    }

    private static function colonnePresente(): bool
    {
        return Cache::remember('schema.ds_demandes.cin', 300, fn () => Schema::hasColumn('ds_demandes', 'cin'));
    }
}
