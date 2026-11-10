{{-- Bouton « ✕ Effacer les filtres », à côté de « Filtrer », seulement quand un filtre ou une recherche est actif.
     $garder : paramètres conservés (ex. la vue du Stock, le statut des Demandes). --}}
@php($garder = $garder ?? [])
@if(collect(request()->except([...$garder, 'page']))->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty())
    <a class="btn effacer-filtres non-imprime" href="{{ url()->current().(($g = array_filter(request()->only($garder))) ? '?'.http_build_query($g) : '') }}">✕ {{ __('Effacer les filtres') }}</a>
@endif
