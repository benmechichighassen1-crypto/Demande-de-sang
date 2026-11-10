@extends('layouts.app')
@section('titre', 'Alertes')
@push('tete')<meta name="suivi-empreinte" data-url="{{ route('suivi.empreinte') }}" content="{{ \App\Support\Clinisys::essayer(fn () => app(\App\Services\EtatEcran::class)->empreinte(), '') }}">@endpush
@section('contenu')
<h1>{{ __('Alertes') }} ({{ count($alertes) }})</h1>
{{-- Une section par type d'alerte, dans l'ordre et les couleurs de config demandesang.onglets.alertes. --}}
@php($types = config('demandesang.onglets.alertes'))
@php($parType = collect($alertes)->groupBy('type'))
@php($compteurs = collect($types)->keys()->mapWithKeys(fn ($t) => [$t => $parType->get($t, collect())->count()])->filter()->all())
@if($compteurs !== [])
    @include('partials.onglets', ['ecran' => 'alertes', 'titre' => 'Alertes', 'compteurs' => $compteurs])
    @include('partials.champ_recherche', ['local' => true, 'placeholder' => __('Nom, n° dossier, n° demande, n° poche, n° bon…')])
@endif
@foreach($compteurs as $type => $n)
    @php([$libelle, $couleur, $icone] = $types[$type])
    <section data-bloc="{{ $type }}" style="--c:{{ $couleur }};">
        <h2>{{ $icone }} {{ __($libelle) }} ({{ $n }})</h2>
        @foreach($parType[$type] as $a)
            <a href="{{ $a['demande'] ? route('demandes.show', $a['demande']) : ($a['lien'] ?? '#') }}" class="carte" style="display:block; text-decoration:none; color:inherit;">
                <div class="ligne espace">
                    <strong>{{ $a['texte'] }}</strong>
                    <span>
                        @if($a['gravite'] === 'haute')<span class="badge" style="background:#b91c1c;">{{ __('Urgent') }}</span>@endif
                        @if($a['demande'])<span class="badge u-{{ $a['demande']->urgence }}">{{ __(config('demandesang.urgences.'.$a['demande']->urgence)) }}</span>@endif
                    </span>
                </div>
                @if($a['demande'])<div class="doux petit">{{ __('Demande') }} {{ $a['demande']->id }} · {{ $a['demande']->nom_patient }} · {{ __('dossier') }} {{ $a['demande']->num_doss }}</div>@endif
            </a>
            {{-- Alerte sur une poche : vérification dans l'écran Stock, recherche préremplie avec le n° de poche. --}}
            @if(! empty($a['poche']))
                <a class="petit loupe" style="display:inline-block; margin:-6px 0 10px;" href="{{ route('stock', ['q' => $a['poche']->numero, 'vue' => config('demandesang.clinisys_actif') ? 'bs_stock' : 'en_stock']) }}">{{ __('Vérifier la poche :n dans le stock', ['n' => $a['poche']->numero]) }}</a>
            @endif
        @endforeach
    </section>
@endforeach
@if($compteurs === [])
    <div class="carte doux">{{ __('Aucune alerte.') }}</div>
@endif
@endsection
