<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Accès en lecture seule aux bases Clinisys (ERP / DMI et pharmacie).
 *
 * Les noms de bases viennent de config/demandesang.php ; ils sont validés avant
 * d'être injectés dans le SQL, les valeurs utilisateur passant toujours par
 * des paramètres liés.
 */
class Clinisys
{
    public static function db(): ConnectionInterface
    {
        return DB::connection(config('demandesang.connexion'));
    }

    /** Nom complet d'un objet : t('erp', 'dbo.Client') => Gclinique_Maroc.dbo.Client */
    public static function t(string $base, string $objet): string
    {
        $nom = config("demandesang.bases.$base");
        if (! is_string($nom) || ! preg_match('/^[A-Za-z0-9_]+$/', $nom)) {
            throw new InvalidArgumentException("Base Clinisys inconnue : $base");
        }
        if (! preg_match('/^[A-Za-z0-9_]+\.[A-Za-z0-9_]+$/', $objet)) {
            throw new InvalidArgumentException("Objet invalide : $objet");
        }

        $prefixe = (string) config('demandesang.prefixe');
        if ($prefixe !== '' && ! preg_match('/^\[[A-Za-z0-9_\-\.]+\]$/', $prefixe)) {
            throw new InvalidArgumentException('Préfixe de serveur lié invalide.');
        }

        return ($prefixe !== '' ? $prefixe.'.' : '').$nom.'.'.$objet;
    }

    /** Nom à 3 parties sans serveur lié, pour le SQL exécuté à distance par OPENQUERY. */
    public static function local(string $base, string $objet): string
    {
        $complet = self::t($base, $objet);
        $prefixe = (string) config('demandesang.prefixe');

        return $prefixe !== '' ? substr($complet, strlen($prefixe) + 1) : $complet;
    }

    /**
     * Exécute une requête d'agrégation sur le serveur Clinisys lui-même (OPENQUERY)
     * quand on passe par un serveur lié : le regroupement se fait là-bas, seules
     * les lignes agrégées transitent. Le SQL ne doit contenir que des valeurs
     * déjà validées (OPENQUERY n'accepte pas de paramètres).
     */
    public static function distant(string $sql): array
    {
        $prefixe = (string) config('demandesang.prefixe');
        if ($prefixe === '') {
            return self::db()->select($sql);
        }

        return self::db()->select('SELECT * FROM OPENQUERY('.$prefixe.", '".str_replace("'", "''", $sql)."')");
    }

    /**
     * Lecture Clinisys gardée N secondes : plusieurs écrans ouverts = une seule lecture du serveur.
     * En JSON : le cache Laravel ne désérialise pas les objets stdClass. Une carte [clé => valeur] revient en objet : la lire avec (array).
     */
    public static function enCache(string $cle, int $secondes, callable $lecture): mixed
    {
        return json_decode(\Illuminate\Support\Facades\Cache::remember($cle, $secondes, fn () => json_encode($lecture())));
    }

    /** Dernière erreur de lecture attrapée par essayer() (affichée « Clinisys indisponible »). */
    public static ?string $erreur = null;

    /**
     * Lecture d'affichage : si Clinisys ne répond pas, la page s'affiche quand même
     * avec la valeur par défaut, et l'erreur est journalisée.
     */
    public static function essayer(callable $lecture, mixed $defaut = null): mixed
    {
        try {
            return $lecture();
        } catch (\Throwable $e) {
            report($e);
            self::$erreur = 'Clinisys indisponible : '.mb_substr($e->getMessage(), 0, 200);

            return $defaut;
        }
    }

    public static function collate(): string
    {
        return 'COLLATE '.config('demandesang.collation');
    }

    /** Liste de paramètres « ?, ?, ? » pour une clause IN. */
    public static function in(array $valeurs): string
    {
        return implode(', ', array_fill(0, max(count($valeurs), 1), '?'));
    }
}
