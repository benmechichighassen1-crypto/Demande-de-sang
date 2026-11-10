@extends('layouts.app')
@section('titre', 'À faire')
@push('tete')<meta name="suivi-empreinte" data-url="{{ route('suivi.empreinte') }}" content="{{ \App\Support\Clinisys::essayer(fn () => app(\App\Services\EtatEcran::class)->empreinte(), '') }}">@endpush
@section('contenu')
<div class="ligne espace">
    <h1>{{ __('À faire') }}</h1>
    <span class="doux petit">{{ __('Vérifié toutes les minutes, sans recharger la page') }}</span>
</div>
@if(auth()->user()->a('coursier'))
    <p><a class="btn sec" href="{{ route('cts') }}">🏥 {{ __('Écran CTS : à déposer, à ramener · poches en instance') }} ({{ $enInstance }})</a></p>
@endif

@if(auth()->user()->a('pharmacie', 'surveillant', 'major', 'direction'))
    <p><a class="btn sec" href="{{ route('pharmacie') }}#bloc-nouvelles">💊 {{ __('Réceptions pharmacie des dernières 24 h') }} ({{ \App\Http\Controllers\PharmacieController::nouvellesReceptions() }})@if(auth()->user()->a('surveillant')) : {{ __('prévenir le service (C6)') }}@endif</a></p>
@endif

@include('partials.poches_en_attente')
@include('partials.champ_recherche', ['local' => true, 'placeholder' => __('Nom, n° dossier, n° demande, n° poche, n° bon…')])

@php($ong = config('demandesang.onglets.accueil'))
{{-- Pas de filtre par catégorie sur « À faire » (liste courte : il compliquait plus qu'il n'aidait) ; les sections gardent
     leur couleur et leur compteur. Seul un compte à plusieurs rôles a le filtre « par rôle ». --}}
{{-- Compte à plusieurs rôles : filtre « par rôle » des tâches (étapes à saisir et remises à confirmer). --}}
@php($badgesRoles = config('demandesang.roles_badges'))
@php($rolesTaches = collect($aFaire)->map(fn ($i) => collect($i['etapes'])->flatMap(fn ($c) => config("demandesang.etapes.$c.roles"))->unique()->all())
    ->merge($aConfirmer->map(fn ($r) => config("demandesang.etapes.{$r->etape}.receveur", [])))
    ->merge(collect($dmiNonInscrites)->map(fn ($x) => ! empty($x->hors_registre) ? ['infirmiere', 'major', 'surveillant'] : ['infirmiere', 'major']))
    ->merge($mesRemises->map(fn ($r) => config("demandesang.etapes.{$r->etape}.receveur", []))))
@php($parRole = collect($badgesRoles)->keys()->filter(fn ($r) => auth()->user()->a($r))
    ->mapWithKeys(fn ($r) => [$r => $rolesTaches->filter(fn ($l) => in_array($r, $l, true))->count()])->filter()->all())
@if(count($parRole) > 1)
    @include('partials.onglets', ['ecran' => 'accueil_roles', 'titre' => 'Par rôle', 'toutes' => 'Tous les rôles', 'cible' => '[data-roles]',
        'total' => $rolesTaches->count(), 'compteurs' => $parRole,
        'defs' => collect($badgesRoles)->map(fn ($b) => [$b[0], $b[1], '👤'])->all()])
@endif

@if($aConfirmer->isNotEmpty())
    <section data-bloc="remises" style="--c:{{ $ong['remises'][1] }};">
    <h2>{{ $ong['remises'][2] }} {{ __('Remises à confirmer') }} ({{ $aConfirmer->count() }})</h2>
    <div class="cartes">
        @foreach($aConfirmer as $r)
            @include('demandes.partials.remise', ['r' => $r, 'retour' => 'accueil'])
        @endforeach
    </div>
    </section>
@endif


@if($aFaire !== [])
    <section data-bloc="etapes" style="--c:{{ $ong['etapes'][1] }};">
    <h2>{{ $ong['etapes'][2] }} {{ __('Étapes à saisir') }} ({{ count($aFaire) }})</h2>
    <div class="cartes">
        @foreach($aFaire as $item)
            @php($rolesCarte = collect($item['etapes'])->flatMap(fn ($c) => config("demandesang.etapes.$c.roles"))->unique()->values()->all())
            @php($d = $item['demande'])
            <div class="carte" data-roles="{{ implode(' ', $rolesCarte) }}">
                <a href="{{ route('demandes.show', $d) }}" style="display:block; text-decoration:none; color:inherit;">
                    <div class="ligne espace">
                        <strong>{{ $d->nom_patient }}</strong>
                        <span class="badge u-{{ $d->urgence }}">{{ __(config("demandesang.urgences.{$d->urgence}")) }}</span>
                    </div>
                    <div class="doux petit">{{ __('Dossier') }} {{ $d->num_doss }} · {{ $d->nb_poches }} {{ $d->produit }} · {{ $ref->service($d->service_source) }} → {{ $ref->service($d->service_destination) }}</div>
                    <div class="doux petit" style="margin-top:6px;">{{ __('Dernière étape :') }} {{ __($d->libelleEtape()) }}, {{ ($d->etape_le ?? $d->cree_le)->diffForHumans() }}</div>
                </a>
                <div style="margin-top:8px; display:flex; flex-wrap:wrap; gap:10px;">
                    @foreach($item['etapes'] as $code)
                        @php($def = config("demandesang.etapes.$code"))
                        <div class="tache">
                        @include('partials.role', ['roles' => $def['roles']])
                        <div style="display:flex; flex-wrap:wrap; gap:6px;">
                        @if(\App\Services\Circuit::sansSaisie($def))
                            {{-- Étape sans champ obligatoire : validée d'un geste, sans ouvrir la fiche. --}}
                            <form method="post" action="{{ route('demandes.etape', [$d, $code]) }}">
                                @csrf <input type="hidden" name="retour" value="accueil">
                                <button class="btn" type="submit">{{ $def['type'] === 'remise' ? '🤝 '.__('Je remets :').' ' : '✔ ' }}{{ __($def['court']) }}</button>
                            </form>
                        @elseif($def['champs'] === ['etat_cts'])
                            {{-- B2 : un bouton par réponse (poches prêtes ou gardées en instance au CTS). --}}
                            <form method="post" action="{{ route('demandes.etape', [$d, $code]) }}">
                                @csrf <input type="hidden" name="retour" value="accueil"><input type="hidden" name="etat_cts" value="pretes"><input type="hidden" name="nb_pretes" value="{{ $d->nb_poches }}">
                                <button class="btn {{ $d->nature === 'instance' ? 'sec' : '' }}" type="submit">✔ {{ __('À ramener : poches prêtes') }} ({{ $d->nb_poches }}/{{ $d->nb_poches }})</button>
                            </form>
                            <a class="btn sec" href="{{ route('demandes.show', [$d, 'etape' => $code]) }}#etape-{{ $code }}">{{ __('Le CTS a donné moins de poches (ex. 1/3)…') }}</a>
                            <form method="post" action="{{ route('demandes.etape', [$d, $code]) }}">
                                @csrf <input type="hidden" name="retour" value="accueil"><input type="hidden" name="etat_cts" value="instance">
                                <button class="btn {{ $d->nature === 'instance' ? '' : 'sec' }}" type="submit">{{ __('À laisser en instance') }} @if($d->nature === 'instance')({{ __('demandé') }})@endif</button>
                            </form>
                        @else
                            <a class="btn sec" href="{{ route('demandes.show', [$d, 'etape' => $code]) }}#etape-{{ $code }}">{{ __($def['court']) }} →</a>
                        @endif
                        </div></div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
    </section>
@endif

@if($dmiNonInscrites !== [])
    <section data-bloc="dmi" style="--c:{{ $ong['dmi'][1] }};">@include('demandes.partials.dmi_non_inscrites')</section>
@endif
@if($mesRemises->isNotEmpty())
    <section data-bloc="mes" style="--c:{{ $ong['mes'][1] }};">
    <h2>{{ $ong['mes'][2] }} {{ __('Mes remises en attente de confirmation') }} ({{ $mesRemises->count() }})</h2>
    <div class="carte">
        @foreach($mesRemises as $r)
            <div style="padding:6px 0; border-bottom:1px solid var(--bord);" data-roles="{{ implode(' ', config("demandesang.etapes.{$r->etape}.receveur", [])) }}">
                <a class="loupe" href="{{ route('demandes.show', $r->demande) }}">{{ $r->demande->nom_patient }}</a> ·
                {{ __(config("demandesang.etapes.{$r->etape}.court")) }} ·
                <span class="doux">{{ __('remis à :heure, pas encore confirmé', ['heure' => $r->remis_le->format('H:i')]) }}</span>
                @include('partials.role', ['roles' => config("demandesang.etapes.{$r->etape}.receveur", []), 'prefixe' => 'En attente de confirmation par'])
            </div>
        @endforeach
    </div>
    </section>
@endif

@if($aConfirmer->isEmpty() && $aFaire === [] && $mesRemises->isEmpty() && $dmiNonInscrites === [])
    <div class="carte doux">{{ __('Rien à faire pour le moment') }} ✓</div>
@endif
@endsection
