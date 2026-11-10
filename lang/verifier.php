<?php
/*
 * Liste les textes __('…') des vues et du code qui n'ont pas de traduction arabe dans lang/ar.json.
 * Usage : php lang/verifier.php
 */
$racine = dirname(__DIR__);
$ar = json_decode(file_get_contents(__DIR__.'/ar.json'), true);
$fichiers = array_merge(
    glob("$racine/resources/views/{,*/,*/*/}*.blade.php", GLOB_BRACE),
    glob("$racine/app/{Http/Controllers,Services,Models,Clinisys}/*.php", GLOB_BRACE)
);
$manquants = [];
foreach ($fichiers as $f) {
    $t = file_get_contents($f);
    preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/u", $t, $m1);
    preg_match_all('/__\\(\\s*"((?:[^"\\\\]|\\\\.)*)"/u', $t, $m2);
    $cles = array_merge(array_map(fn ($k) => stripcslashes(str_replace("\\'", "'", $k)), $m1[1]), array_map('stripcslashes', $m2[1]));
    foreach ($cles as $k) {
        if ($k !== '' && ! isset($ar[$k]) && ! preg_match('/^[a-z_.]+$/', $k)) {
            $manquants[$k] = str_replace($racine.'/', '', $f);
        }
    }
}
foreach ($manquants as $k => $f) {
    echo "$f : $k\n";
}
echo count($manquants), " texte(s) sans traduction\n";
