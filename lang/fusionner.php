<?php
/*
 * Assemble lang/ar.json à partir des morceaux lang/ar_parts/*.json (base.json d'abord).
 * Usage : php lang/fusionner.php — signale les clés traduites différemment dans deux morceaux.
 */
$dossier = __DIR__.'/ar_parts';
$fichiers = glob("$dossier/*.json");
usort($fichiers, fn ($a, $b) => (basename($a) === 'base.json' ? -1 : 0) <=> (basename($b) === 'base.json' ? -1 : 0) ?: strcmp($a, $b));
$ar = [];
foreach ($fichiers as $f) {
    $morceau = json_decode(file_get_contents($f), true);
    if (! is_array($morceau)) {
        fwrite(STDERR, 'JSON invalide : '.basename($f)."\n");
        exit(1);
    }
    foreach ($morceau as $cle => $texte) {
        if (isset($ar[$cle]) && $ar[$cle] !== $texte) {
            echo 'Doublon gardé (', basename($f), ' ignoré) : ', $cle, "\n";
            continue;
        }
        $ar[$cle] = $texte;
    }
}
ksort($ar);
file_put_contents(__DIR__.'/ar.json', json_encode($ar, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo count($ar), " clés dans lang/ar.json\n";
