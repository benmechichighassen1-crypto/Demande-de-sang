{{-- Filtres actifs d'une liste : une pastille « Champ : valeur × » par filtre de l'adresse (clic = retirer ce filtre),
     et le nombre de résultats ; « ✕ Effacer les filtres » est dans le formulaire (partials.effacer_filtres). Variables : $actifs [paramètre => libellé affiché], $resultat (texte),
     $garder (paramètres conservés, ex. la vue du Stock). --}}
@php($actifs = array_filter($actifs ?? [], fn ($l, $p) => request()->filled($p), ARRAY_FILTER_USE_BOTH))
<div class="actifs non-imprime">
    <span class="doux">{{ $resultat }}@if($actifs !== []) <strong style="color:var(--rouge);">{{ __('(filtrées)') }}</strong>@endif</span>
    @foreach($actifs as $p => $libelle)
        <a class="puce" href="{{ request()->fullUrlWithoutQuery([$p, 'page']) }}" title="{{ __('Retirer ce filtre') }}">{{ $libelle }} <b>×</b></a>
    @endforeach
</div>
