<div class="collant non-imprime">
<form class="carte filtres non-imprime" method="get">
    @include('partials.champ_recherche', ['placeholder' => __('Nom, n° dossier, identifiant, n° demande, n° poche, n° bon…')])
    <label class="petit">{{ __('Du') }} <input type="date" name="du" value="{{ $f['du'] }}"></label>
    <label class="petit">{{ __('au') }} <input type="date" name="au" value="{{ $f['au'] }}"></label>
    <select name="service"><option value="">{{ __('Tout service') }}</option>
        @foreach($ref->services() as $c => $l)<option value="{{ $c }}" @selected(($f['service'] ?? '') === $c)>{{ $l }}</option>@endforeach</select>
    <select name="urgence"><option value="">{{ __('Toute urgence') }}</option>
        @foreach(config('demandesang.urgences') as $c => $l)<option value="{{ $c }}" @selected(($f['urgence'] ?? '') === $c)>{{ __($l) }}</option>@endforeach</select>
    @if(request()->routeIs('registre'))
        <select name="pharmacie"><option value="">{{ __('Toutes les demandes') }}</option>
            <option value="recues" @selected(($f['pharmacie'] ?? '') === 'recues')>{{ __('Réceptionnées pharmacie (C5)') }}</option></select>
    @endif
    <button class="btn sec" type="submit">{{ __('Afficher') }}</button>
    @include('partials.effacer_filtres')
</form>
</div>
@include('partials.filtres_actifs', ['resultat' => __(':n demande(s) du :du au :au', ['n' => isset($demandes) ? $demandes->count() : ($ind['total'] ?? 0), 'du' => \Illuminate\Support\Carbon::parse($f['du'])->format('d/m/Y'), 'au' => \Illuminate\Support\Carbon::parse($f['au'])->format('d/m/Y')]), 'actifs' => [
    'service' => __('Service : :valeur', ['valeur' => $ref->services()[$f['service'] ?? ''] ?? ($f['service'] ?? '')]),
    'urgence' => __('Urgence : :valeur', ['valeur' => __(config('demandesang.urgences.'.($f['urgence'] ?? ''), ''))]),
    'pharmacie' => __('Réceptionnées pharmacie (C5)'),
    'q' => __('Recherche : :v', ['v' => $f['q'] ?? '']).(! empty($f['historique']) ? ' · '.__('recherche sur tout l\'historique') : ''),
    'du' => __('Du :date', ['date' => \Illuminate\Support\Carbon::parse($f['du'])->format('d/m/Y')]),
    'au' => __('Au :date', ['date' => \Illuminate\Support\Carbon::parse($f['au'])->format('d/m/Y')]),
]])
