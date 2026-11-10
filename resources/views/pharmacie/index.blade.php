@extends('layouts.app')
@section('titre', __('Pharmacie'))
@push('tete')<meta name="suivi-empreinte" data-url="{{ route('suivi.empreinte') }}" content="{{ \App\Support\Clinisys::essayer(fn () => app(\App\Services\EtatEcran::class)->empreinte(), '') }}">@endpush
@section('contenu')
<div class="ligne espace">
    <h1>{{ __('Pharmacie : réceptions et délivrances') }}</h1>
    <span class="doux petit">{{ __('Lu dans Clinisys (bon de dépôt BD, sortie patient)') }} · {{ __('Vérifié toutes les minutes, sans recharger la page') }}</span>
</div>

@php($compteurs = ($horsRegistre !== [] ? ['hors_registre' => count($horsRegistre)] : []) + array_map('count', $blocs))
@php($compteurs['nouvelles'] += count($recuesHorsRegistre) + count($sansC4))
@include('partials.onglets', ['ecran' => 'pharmacie', 'titre' => 'Pharmacie', 'compteurs' => $compteurs])
@include('partials.champ_recherche', ['local' => true, 'placeholder' => __('Nom, n° dossier, n° demande, n° poche, n° bon…')])
@include('partials.poches_en_attente')

@if($horsRegistre !== [])
    <section data-bloc="hors_registre" style="--c:#b91c1c;">
    {{-- Demandes DMI réceptionnées en pharmacie (V) qui n'ont jamais été inscrites au registre : à régulariser. --}}
    <h2 id="bloc-hors_registre">⚠ {{ __('Reçues hors registre') }} ({{ count($horsRegistre) }})</h2>
    <p class="doux petit">{!! __("Réceptionnées dans Clinisys sans demande dans l'appli : le service, le coursier ou le CTS n'ont rien tracé. L'infirmière, le major ou le surveillant inscrit la demande « a posteriori » avec un motif.") !!}</p>
    <div class="cartes">
        @foreach($horsRegistre as $h)
            @php($x = $h['dmi'])
            @php($pr = \App\Clinisys\DemandesDmi::preremplissage($x))
            <div class="carte">
                <div class="ligne espace">
                    <strong>{{ trim(($x->NomCli ?? '').' '.($x->Prenom ?? '')) ?: '—' }}</strong>
                    <span class="badge" style="background:#b91c1c;">{{ __('Hors registre') }}</span>
                </div>
                <div class="doux petit">{{ __('Dossier :n', ['n' => trim($x->NumDoss)]) }} · {{ __('DMI n° :id', ['id' => $x->ID]) }} · {{ $pr['produit'] ? __(\App\Clinisys\DemandesDmi::libelleProduits(array_filter([$pr['produit'] => $pr['nb_poches']] + $pr['autres_produits']))) : '—' }} · {{ trim((string) ($x->service ?? '')) ?: '—' }}</div>
                @if($h['bon'])
                    <div class="petit" style="margin-top:4px;">{{ __('Bon BD') }} <strong>{{ $h['bon']->numaffiche }}</strong> · {{ $h['recu_le']->format('d/m H:i') }} · Clinisys ({{ $h['bon']->codvend }})</div>
                    <ul class="petit" style="margin:4px 0 0 16px; padding:0;">
                        @foreach($h['bon']->poches ?? [] as $p)
                            <li>{{ $p->Identifiant ?: __('sans n°') }} · {{ $p->desart }}@if($p->DatPer) · {{ __('périme le :date', ['date' => \Illuminate\Support\Carbon::parse($p->DatPer)->format('d/m/Y')]) }}@endif</li>
                        @endforeach
                    </ul>
                @else
                    <div class="petit" style="margin-top:4px;">{{ __('Demande DMI réceptionnée (état V), bon BD non trouvé.') }}</div>
                @endif
                @if(auth()->user()->a('infirmiere', 'major', 'surveillant'))
                    <div style="margin-top:8px;"><a class="btn petit" href="{{ route('demandes.create', ['num_demande' => $x->ID]) }}">{{ __('Inscrire (régularisation)') }}</a></div>
                @endif
            </div>
        @endforeach
    </div>
    </section>
@endif

@foreach(\App\Http\Controllers\PharmacieController::BLOCS as $c => $titre)
    @php([$court, $couleur, $icone] = config("demandesang.onglets.pharmacie.$c"))
    <section data-bloc="{{ $c }}" style="--c:{{ $couleur }};">
    @php($horsCircuit = $c === 'nouvelles' ? count($recuesHorsRegistre) + count($sansC4) : 0)
    <h2 id="bloc-{{ $c }}">{{ $icone }} {{ __($titre) }} ({{ count($blocs[$c]) + $horsCircuit }})</h2>
    @if($c === 'attente_bd')<p class="doux petit">{{ __('Le coursier a déposé les poches (C4) ; la réception apparaît dès que le bon de dépôt BD est saisi dans Clinisys. Alerte au-delà de :seuil min.', ['seuil' => $seuil]) }}</p>@endif
    <div class="cartes">
        @if($c === 'nouvelles')
            {{-- Bons BD des dernières 24 h hors circuit : demande non inscrite (hors registre) ou étape C4 non saisie. --}}
            @foreach($recuesHorsRegistre as $h)
                <a class="carte" href="#bloc-hors_registre" style="display:block; text-decoration:none; color:inherit; border-left-color:#b91c1c;">
                    <div class="ligne espace"><strong>{{ trim(($h['dmi']->NomCli ?? '').' '.($h['dmi']->Prenom ?? '')) ?: '—' }}</strong><span class="badge" style="background:#b91c1c;">{{ __('Hors registre') }}</span></div>
                    <div class="doux petit">{{ __('Dossier :n', ['n' => trim($h['dmi']->NumDoss)]) }} · {{ __('DMI n° :id', ['id' => $h['dmi']->ID]) }} · {{ __('Bon BD') }} {{ $h['bon']->numaffiche ?? '' }} · {{ $h['recu_le']->format('d/m H:i') }}</div>
                    <div class="petit">{{ __('Reçue dans Clinisys, demande pas inscrite au registre : à inscrire (régularisation).') }}</div>
                </a>
            @endforeach
            @foreach($sansC4 as $x)
                <a class="carte" href="{{ route('demandes.show', $x['demande']) }}" style="display:block; text-decoration:none; color:inherit; border-left-color:#c2410c;">
                    <div class="ligne espace"><strong>{{ $x['demande']->nom_patient }}</strong><span class="badge" style="background:#c2410c;">{{ __('C4 non saisie') }}</span></div>
                    <div class="doux petit">{{ __('Dossier :n', ['n' => $x['demande']->num_doss]) }} · {{ __('DMI n° :id', ['id' => $x['demande']->num_demande_clinisys]) }} · {{ __(':n poche(s) au bon BD', ['n' => $x['poches']]) }}</div>
                    <div class="petit">{{ __('Bon BD saisi dans Clinisys, mais le dépôt à la pharmacie (C4) n\'est pas enregistré dans l\'appli : la réception (C5) sera reprise dès que C4 est saisie.') }}</div>
                </a>
            @endforeach
        @endif
        @forelse($blocs[$c] as $l)
            @php($d = $l['demande'])
            @php($e = $l['etat'])
            @php($minutes = $l['depuis'] ? (int) $l['depuis']->diffInMinutes(now()) : 0)
            @php($retard = $c === 'attente_bd' && $minutes > $seuil)
            @php($c5 = $e['C5']['action'])
            @php($c8 = $e['C8']['action'])
            <a class="carte" href="{{ route('demandes.show', $d) }}" style="display:block; text-decoration:none; color:inherit; {{ $retard ? 'border-left-color:#b91c1c;' : '' }}">
                <div style="margin-bottom:6px;"><span class="badge-etape">{{ $icone }} {{ __($court) }}</span></div>
                <div class="ligne espace">
                    <strong>{{ $d->nom_patient }}</strong>
                    <span>
                        @if($c === 'nouvelles' && $minutes < 120)<span class="badge" style="background:#15803d;">{{ __('Nouveau') }}</span>@endif
                        <span class="badge u-{{ $d->urgence }}">{{ __(config("demandesang.urgences.{$d->urgence}")) }}</span>
                    </span>
                </div>
                <div class="doux petit">{{ __('Dossier :n', ['n' => $d->num_doss]) }} · {{ __('DMI n° :id', ['id' => $d->num_demande_clinisys ?? '—']) }} · {{ $d->nb_poches }} {{ $d->produit }} · {{ __('vers :service', ['service' => $ref->service($d->service_destination)]) }}</div>

                @if(in_array($c, ['nouvelles', 'a_delivrer', 'delivrees'], true) && $c5)
                    <div class="petit" style="margin-top:4px;">
                        {{ __('Bon BD') }} <strong>{{ $c5->donnees['bon_depot'] ?? '—' }}</strong> · {{ $c5->fait_le->format('d/m H:i') }} · {{ $c5->nom_acteur }}
                    </div>
                    @if($c === 'nouvelles')
                        @php($poches = $c5->donnees['poches_clinisys'] ?? $d->poches->map(fn ($p) => ['numero' => $p->numero, 'article' => $p->produit, 'peremption' => $p->peremption?->toDateString()])->all())
                        @if($poches)
                            <ul class="petit" style="margin:4px 0 0 16px; padding:0;">
                                @foreach($poches as $p)
                                    <li>{{ $p['numero'] ?: __('sans n°') }} · {{ $p['article'] ?? $d->produit }}@if(! empty($p['peremption'])) · {{ __('périme le :date', ['date' => \Illuminate\Support\Carbon::parse($p['peremption'])->format('d/m/Y')]) }}@endif</li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                @endif
                @if($c === 'delivrees' && $c8)
                    <div class="petit" style="margin-top:4px;">{{ __('Sortie :bons', ['bons' => implode(', ', $c8->donnees['bons_pa'] ?? []) ?: '—']) }} · {{ $c8->fait_le->format('H:i') }} · {{ $c8->nom_acteur }}</div>
                @endif

                <div class="petit" style="margin-top:4px; {{ $retard ? 'color:#b91c1c; font-weight:600;' : '' }}">
                    @switch($c)
                        @case('attente_bd') {{ __('Portées à la pharmacie il y a :duree', ['duree' => $alertes->duree($minutes)]) }}@if($retard) : {{ __('bon BD pas encore saisi dans Clinisys') }} @endif @break
                        @case('nouvelles') {{ __('Reçue il y a :duree', ['duree' => $alertes->duree($minutes)]) }} · {{ __($d->libelleEtape()) }} @break
                        @case('a_delivrer') {{ __('En pharmacie depuis :duree', ['duree' => $alertes->duree($minutes)]) }}@if(($e['C6']['statut'] ?? '') === 'faite') · {{ __('service prévenu') }} @endif @break
                        @case('delivrees') {{ __('Délivrée il y a :duree · le service doit accuser réception (C9)', ['duree' => $alertes->duree($minutes)]) }} @break
                    @endswitch
                </div>
            </a>
        @empty
            @if($horsCircuit === 0)<div class="carte doux">{{ __('Aucune demande.') }}</div>@endif
        @endforelse
    </div>
    </section>
@endforeach
@endsection
