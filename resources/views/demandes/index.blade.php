@extends('layouts.app')
@section('titre', __('Demandes'))
@push('tete')<meta name="suivi-empreinte" data-url="{{ route('suivi.empreinte') }}" content="{{ \App\Support\Clinisys::essayer(fn () => app(\App\Services\EtatEcran::class)->empreinte(), '') }}">@endpush
@section('contenu')
<div class="ligne espace">
    <h1>{{ __('Demandes') }}</h1>
    <div style="flex:1; max-width:720px;">@include('demandes.partials.recherche')</div>
</div>

{{-- Demandes DMI pas encore inscrites : en haut pour qui inscrit, sans recherche ; sinon sous les résultats, repliées. --}}
@php($filtreActif = collect(request()->only(['q', 'urgence', 'service', 'du', 'au', 'role', 'suivi']))->filter(fn ($v) => filled($v))->isNotEmpty())
@php($inscrit = auth()->user()->a('infirmiere', 'major', 'surveillant'))
@php($q = \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii(trim((string) ($f['q'] ?? '')))))
@php($dmiTrouvees = $q === '' ? $dmiNonInscrites : array_values(array_filter($dmiNonInscrites, fn ($x) => str_contains(\Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii(implode(' ', [
    $x->ID, trim((string) $x->NumDoss), $x->Identifiant ?? '', $x->NumCIN ?? '', trim(($x->NomCli ?? '').' '.($x->Prenom ?? '')), trim(($x->Prenom ?? '').' '.($x->NomCli ?? ''))]))), $q))))
@if(! $filtreActif && $inscrit)
    @include('demandes.partials.dmi_non_inscrites')
@endif

{{-- Statut en onglets segmentés (l'actif bien visible), autres filtres dans le formulaire ; le tout reste visible en défilant. --}}
<div class="collant">
<div class="segments non-imprime" style="margin-bottom:10px;">
    @foreach(['en_cours' => 'En cours', 'cloturee' => 'Clôturées', 'annulee' => 'Annulées', 'toutes' => 'Toutes'] as $c => $l)
        <a href="{{ request()->fullUrlWithQuery(['statut' => $c]) }}" class="{{ $f['statut'] === $c ? 'actif' : '' }}">{{ __($l) }}</a>
    @endforeach
</div>
<form class="carte filtres non-imprime" method="get">
    <input type="hidden" name="statut" value="{{ $f['statut'] }}">
    <input type="hidden" name="suivi" value="{{ $f['suivi'] }}">
    @include('partials.champ_recherche', ['placeholder' => __('Nom, n° dossier, identifiant, n° demande, n° poche, n° bon…')])
    <select name="urgence"><option value="">{{ __('Toute urgence') }}</option>
        @foreach(config('demandesang.urgences') as $c => $l)<option value="{{ $c }}" @selected(($f['urgence'] ?? '') === $c)>{{ __($l) }}</option>@endforeach
    </select>
    <select name="service"><option value="">{{ __('Tout service') }}</option>
        @foreach($services as $c => $l)<option value="{{ $c }}" @selected(($f['service'] ?? '') === $c)>{{ $l }}</option>@endforeach
    </select>
    <input type="date" name="du" value="{{ $f['du'] ?? '' }}" title="{{ __('Du') }}">
    <input type="date" name="au" value="{{ $f['au'] ?? '' }}" title="{{ __('Au') }}">
    <select name="role"><option value="">{{ __('Tout responsable') }}</option>
        @foreach(config('demandesang.roles_badges') as $c => [$l])<option value="{{ $c }}" @selected(($f['role'] ?? '') === $c)>{{ __($l) }}</option>@endforeach
    </select>
    <button class="btn sec" type="submit">{{ __('Filtrer') }}</button>
    @include('partials.effacer_filtres', ['garder' => ['statut', 'suivi']])
</form>
</div>
@include('partials.filtres_actifs', ['garder' => ['statut', 'suivi'], 'resultat' => __(':n demande(s)', ['n' => $demandes->count()]).' '.__(['en_cours' => 'en cours', 'cloturee' => 'clôturée(s)', 'annulee' => 'annulée(s)', 'toutes' => 'au total'][$f['statut']]), 'actifs' => [
    'urgence' => __('Urgence : :v', ['v' => __(config('demandesang.urgences.'.($f['urgence'] ?? ''), '') ?? '')]),
    'service' => __('Service : :v', ['v' => ($services[$f['service'] ?? ''] ?? ($f['service'] ?? ''))]),
    'du' => __('Du :date', ['date' => (isset($f['du']) ? \Illuminate\Support\Carbon::parse($f['du'])->format('d/m/Y') : '')]),
    'au' => __('Au :date', ['date' => (isset($f['au']) ? \Illuminate\Support\Carbon::parse($f['au'])->format('d/m/Y') : '')]),
    'q' => __('Recherche : « :q »', ['q' => ($f['q'] ?? '')]),
    'role' => __('Responsable : :v', ['v' => __(config('demandesang.roles_badges.'.($f['role'] ?? '').'.0') ?? '')]),
]])

{{-- Suivi : une carte par demande (rouge = en retard d'après les délais d'alerte, orange = à surveiller), triée par retard puis urgence. --}}
@php($pastilles = ['toutes' => 'Toutes', 'moi' => 'À faire par moi', 'retard' => 'En retard', 'urgentes' => 'Urgentes'] + collect(\App\Services\SuiviDemande::PHASES)->mapWithKeys(fn ($l, $p) => ["phase$p" => $l])->all())
<nav class="pastilles-suivi non-imprime" aria-label="{{ __('Suivi') }}">
    @foreach($pastilles as $c => $l)
        @continue($c === 'moi' && $rolesCircuit === [])
        <a href="{{ request()->fullUrlWithQuery(['suivi' => $c]) }}" class="{{ $f['suivi'] === $c ? 'actif' : '' }} {{ $c === 'retard' && $comptes[$c] > 0 ? 'retard' : '' }}">{{ __($l) }} <b>{{ $comptes[$c] }}</b></a>
    @endforeach
</nav>
@forelse($demandes as $d)
    @php($s = $suivis[$d->id])
    <div class="carte carte-suivi niveau-{{ $s['niveau'] }}">
        <div class="entete-suivi">
            <a class="loupe" href="{{ route('demandes.show', $d) }}" style="font-size:15.5px; font-weight:700;">{{ $d->nom_patient }}</a>
            <span class="badge u-{{ $d->urgence }}">{{ __(config("demandesang.urgences.{$d->urgence}")) }}</span>
            @if($d->statut !== 'en_cours')<span class="badge s-{{ $d->statut }}">{{ $d->statut === 'cloturee' ? __('Clôturée') : __('Annulée') }}</span>@endif
            <span class="doux petit">{{ __('Demande :id', ['id' => $d->id]) }}@if($d->num_demande_clinisys) · {{ __('DMI n° :n', ['n' => $d->num_demande_clinisys]) }}@endif
                · {{ __('dossier :n', ['n' => $d->num_doss]) }}@if($d->cin) · {{ __('CIN') }} {{ $d->cin }}@endif @if($d->libelleCas())· {{ $d->libelleCas() }} @endif</span>
            <span class="petit">{{ $d->nb_poches }} × {{ $d->produit }} · {{ $ref->service($d->service_source) ?: ($d->service_saisie_dmi ?: '—') }}</span>
        </div>
        @include('demandes.partials.suivi', ['s' => $s])
        {{-- Où sont les poches (Clinisys, une lecture pour la liste). --}}
        @if($d->num_demande_clinisys && isset($lieux))
            @include('partials.lieux_poches', ['lieux' => $lieux[(string) $d->num_demande_clinisys] ?? [], 'demandees' => $d->enCours() ? $d->nb_poches : 0, 'compact' => true])
        @endif
    </div>
@empty
    <div class="carte doux">{{ __('Aucune demande.') }}</div>
@endforelse
@if($filtreActif || ! $inscrit)
    @if($q !== '' && $demandes->isEmpty() && $dmiTrouvees !== [])
        <div class="alerte" style="border-color:#f59e0b;">{{ __("Cette demande n'est pas encore inscrite dans l'appli.") }}
            @if(auth()->user()->a('infirmiere', 'major'))<a class="btn petit" href="{{ route('demandes.create', ['num_demande' => $dmiTrouvees[0]->ID]) }}">{{ __('Inscrire') }}</a>@endif</div>
    @endif
    @include('demandes.partials.dmi_non_inscrites', ['dmiNonInscrites' => $dmiTrouvees, 'replie' => true, 'ouvert' => $q !== '' && $demandes->isEmpty() && $dmiTrouvees !== []])
@endif
@endsection
