<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Mise à jour incomplète (fichiers décompressés sans lancer mise-a-jour.bat) : configuration gardée en cache plus ancienne
 * que les fichiers de configuration, ou migrations de la base pas encore passées. Vérifié au plus une fois par minute.
 */
class EtatInstallation
{
    /** @return string[] problèmes trouvés (vide si tout est à jour) */
    public static function problemes(): array
    {
        return Cache::remember('installation.problemes', 60, function () {
            $problemes = [];
            $cache = base_path('bootstrap/cache/config.php');
            if (is_file($cache)) {
                $recente = max(array_map('filemtime', glob(config_path('*.php')) ?: [0]));
                if ($recente > filemtime($cache)) {
                    $problemes[] = __('la configuration en cache est plus ancienne que les fichiers installés');
                }
            }
            try {
                $faites = DB::table('migrations')->pluck('migration')->all();
                $fichiers = array_map(fn ($f) => basename($f, '.php'), glob(database_path('migrations/*.php')) ?: []);
                if (array_diff($fichiers, $faites) !== []) {
                    $problemes[] = __('des changements de la base ne sont pas encore appliqués');
                }
            } catch (\Throwable) {
                // Base injoignable : rien à signaler ici, l'erreur se verra ailleurs.
            }

            return $problemes;
        });
    }
}
