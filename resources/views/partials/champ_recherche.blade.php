{{-- Champ de recherche commun aux écrans à liste (petite croix pour l'effacer, cf. layout).
     Dans un formulaire de filtres : $nom (q par défaut) envoyé au serveur et gardé dans l'adresse.
     Sur un tableau de bord sans formulaire ($local = true) : filtre les cartes / lignes de la page, sans recharger. --}}
@php($nom = $nom ?? 'q')
@if($local ?? false)
    <form class="carte filtres non-imprime" onsubmit="return false;" role="search">
        <input type="search" data-filtre-local="{{ $cibles ?? '.cartes > .carte, .cartes > a.carte, tbody tr' }}" placeholder="{{ $placeholder }}"
               aria-label="{{ __('Rechercher') }}" style="flex:1 1 320px; min-width:0; font-size:16px;">
        <span class="doux petit" data-filtre-compte></span>
    </form>
@else
    <input type="search" name="{{ $nom }}" value="{{ request($nom) }}" placeholder="{{ $placeholder }}" aria-label="{{ __('Rechercher') }}"
           style="flex:1 1 320px; min-width:0; font-size:16px; border:2px solid {{ filled(request($nom)) ? 'var(--rouge2)' : 'var(--bord)' }};">
@endif
