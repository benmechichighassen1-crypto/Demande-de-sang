<?php

namespace App\Services;

use App\Models\Demande;
use Illuminate\Support\Carbon;

/**
 * Suivi d'une demande en un coup d'œil : la phase du circuit où elle se trouve (6 phases), l'étape qui la bloque,
 * qui doit la faire, depuis quand, et un niveau de retard (vert / orange / rouge). Calculé depuis la base de l'appli seule.
 */
class SuiviDemande
{
    /** Les 6 phases du circuit (diagramme) : [clé => libellé]. */
    public const PHASES = [
        1 => 'Service',
        2 => 'Coursier',
        3 => 'CTS',
        4 => 'Clinique',
        5 => 'Pharmacie',
        6 => 'Patient',
    ];

    /** Phase d'une étape : préparation au service, coursier (remise, paiement, transport), CTS, retour clinique, pharmacie, service. */
    public static function phaseDe(string $code, string $type): int
    {
        return match (true) {
            $code === 'A11' => $type === 'confirmer' ? 2 : 1,
            in_array($code, ['A3', 'B1'], true) => 2,
            $code === 'B2' => 3,
            in_array($code, ['C1', 'C2', 'C3'], true) => 4,
            in_array($code, ['C4', 'C5', 'C6', 'C8'], true) => 5,
            in_array($code, ['C9', 'C10'], true) => 6,
            default => 1,
        };
    }

    /**
     * @return array{phase: int, code: ?string, etape: ?string, type: ?string, roles: string[], depuis: Carbon, minutes: int,
     *               par: ?string, niveau: string, termine: bool, instance: bool}
     */
    public static function calculer(Demande $d, array $etat, Referentiels $ref): array
    {
        $code = null;
        $type = null;
        foreach ($etat as $c => $e) {
            if ($e['statut'] === 'remise_attente') {
                [$code, $type] = [$c, 'confirmer'];
                break;
            }
        }
        if ($code === null) {
            foreach ($etat as $c => $e) {
                if ($e['statut'] === 'disponible') {
                    [$code, $type] = [$c, Circuit::automatique($e['def']) ? 'clinisys' : 'faire'];
                    break;
                }
            }
        }
        $def = $code ? $etat[$code]['def'] : null;
        $roles = match ($type) {
            'confirmer' => $def['receveur'] ?? [],
            'clinisys' => ['pharmacie'],
            'faire' => $def['roles'] ?? [],
            default => [],
        };

        // Dernière étape faite : quand et par qui.
        $derniere = null;
        foreach ($etat as $e) {
            $quand = $e['statut'] === 'faite' ? ($e['remise']?->repondu_le ?? $e['action']?->fait_le) : null;
            if ($quand && (! $derniere || $quand->gt($derniere['quand']))) {
                $derniere = ['quand' => $quand, 'par' => $e['remise']?->nom_receveur ?? $e['action']?->nom_acteur];
            }
        }
        $depuis = $derniere['quand'] ?? $d->cree_le;
        $minutes = (int) $depuis->diffInMinutes(now());
        $termine = ! $d->enCours() || $code === null;
        $phase = $termine ? 6 : self::phaseDe($code, (string) $type);
        // Poches laissées en instance au CTS (à la demande du service) : pas de retard avant le délai des instances.
        $instance = $code === 'C1' && ($d->nature === 'instance' || ($etat['B2']['action']?->getAttributes()['donnees'] ?? null) && (($etat['B2']['action']->donnees['etat_cts'] ?? null) === 'instance'));

        return [
            'phase' => $phase, 'code' => $code, 'etape' => $def['libelle'] ?? null, 'type' => $type, 'roles' => $roles,
            'depuis' => $depuis, 'minutes' => $minutes, 'par' => $derniere['par'] ?? $d->cree_par, 'termine' => $termine, 'instance' => $instance,
            'niveau' => $termine ? 'vert' : self::niveau($d, $code, (string) $type, $minutes, $instance, $ref),
        ];
    }

    /**
     * Retard : orange au-delà du délai d'alerte de la phase (Paramétrage › Seuils, selon l'urgence), rouge au-delà du double.
     * Remise en attente de confirmation : délai « remise ». Poches en instance au CTS : délai des instances (en jours).
     */
    private static function niveau(Demande $d, ?string $code, string $type, int $minutes, bool $instance, Referentiels $ref): string
    {
        if ($instance) {
            $seuil = 1440 * (int) $ref->parametre('instance_jours', config('demandesang.instance_jours'));

            return $minutes > $seuil ? 'rouge' : 'vert';
        }
        $phase = match (true) {
            $type === 'confirmer' => 'remise',
            in_array($code, ['A3', 'A4', 'A6', 'A7', 'A9', 'A10', 'A11'], true) => 'service',
            in_array($code, ['B1', 'B2'], true) => 'cts',
            $code === 'C10' => null,
            default => 'retour',
        };
        if ($phase === null) {
            return 'vert';
        }
        $seuil = max(1, $ref->seuil($d->urgence, $phase));

        return $minutes > 2 * $seuil ? 'rouge' : ($minutes > $seuil ? 'orange' : 'vert');
    }

    /** Qui doit agir, en clair : rôle, plus le service pour l'infirmière / le major, et le téléphone du coursier / du surveillant. */
    public static function quiDoitAgir(Demande $d, array $s, Referentiels $ref): array
    {
        $badges = config('demandesang.roles_badges');
        $libelles = array_map(fn ($r) => __($badges[$r][0] ?? $r), $s['roles']);
        $service = null;
        if (array_intersect($s['roles'], ['infirmiere', 'major']) !== []) {
            $code = $s['phase'] === 6 ? $d->service_destination : $d->service_source;
            $service = $code ? $ref->service($code) : $d->service_saisie_dmi;
        }
        $telephones = array_values(array_intersect(['coursier', 'surveillant'], $s['roles']));

        return ['libelle' => implode(' / ', array_unique($libelles)), 'service' => $service, 'telephones' => $telephones];
    }
}
