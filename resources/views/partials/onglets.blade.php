{{-- Barre d'onglets d'un écran en étapes (CTS, Pharmacie, À faire, Alertes) : une pastille colorée par étape avec
     son compteur, « Toutes » par défaut, fil d'Ariane « Écran › étape ». Les sections de la page portent
     data-bloc="<clé>" ; le filtre est gardé dans l'adresse (#etape-<clé>) et dans le navigateur.
     Variables : $ecran (clé de config demandesang.onglets), $titre (nom de l'écran), $compteurs [clé => nombre] ;
     facultatif : $defs (onglets [clé => [libellé, couleur, icône]] à la place de la config), $cible (sélecteur des éléments
     filtrés par leur data-roles au lieu des sections data-bloc), $toutes (libellé de « Toutes »). --}}
@php($defs = array_intersect_key($defs ?? config("demandesang.onglets.$ecran"), $compteurs))
<div class="onglets non-imprime" data-onglets="{{ $ecran }}" data-titre="{{ $titre }}" @isset($cible) data-cible="{{ $cible }}" style="position:static;" @endisset role="tablist">
    <button type="button" class="onglet actif" data-onglet="toutes" style="--c:#44403c;">{{ __($toutes ?? 'Toutes') }} <span class="n">{{ $total ?? array_sum($compteurs) }}</span></button>
    @foreach($defs as $cle => [$libelle, $couleur, $icone])
        <button type="button" class="onglet" data-onglet="{{ $cle }}" data-libelle="{{ __($libelle) }}" style="--c:{{ $couleur }};">{{ $icone }} {{ __($libelle) }} <span class="n">{{ $compteurs[$cle] }}</span></button>
    @endforeach
</div>
<div class="fil non-imprime" data-fil="{{ $ecran }}"><strong>{{ __($titre) }}</strong> › <span>{{ __('Toutes') }}</span></div>
