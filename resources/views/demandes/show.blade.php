@extends('layouts.app')
@section('titre', __('Demande :id', ['id' => $d->id]))
@php($u = auth()->user())
@php($acteur = fn ($a) => $a ? $a->nom_acteur.' · '.$a->fait_le->format('d/m H:i') : '')
@section('contenu')
{{-- En-tête collant : complet en haut de la fiche, réduit en une barre compacte quand on défile (non imprimé). --}}
<div class="collant entete-fiche">
<div class="ligne espace">
    <h1 style="margin:0;">{{ __('Demande n° :id', ['id' => $d->id]) }} · {{ $d->nom_patient }}</h1>
    <div class="ligne non-imprime">
        <span class="badge u-{{ $d->urgence }}">{{ __(config("demandesang.urgences.{$d->urgence}")) }}</span>
        <span class="badge s-{{ $d->statut }}">{{ __(['en_cours' => 'En cours', 'cloturee' => 'Clôturée', 'annulee' => 'Annulée'][$d->statut]) }}</span>
        <a class="btn petit gris" href="{{ route('demandes.bon', $d) }}" target="_blank">{{ __('Imprimer le bon') }}</a>
    </div>
</div>
<div class="visible-reduit petit">{{ __('CIN') }} {{ $d->cin ?: '—' }} · {{ __('dossier :n', ['n' => $d->num_doss]) }}@if($d->libelleCas()) ({{ $d->libelleCas() }})@endif · {{ $d->nb_poches }} × {{ $d->produit }} {{ $d->groupe_sanguin }}</div>
<div class="carte carte-suivi niveau-{{ $suivi['niveau'] }}" style="margin-top:10px;">@include('demandes.partials.suivi', ['s' => $suivi, 'grand' => true])</div>
</div>

@if($d->regularisation)
    <div class="alerte rouge" style="margin-top:12px;"><strong>{{ __('Inscrite a posteriori (régularisation)') }}</strong> : {{ __('demande réceptionnée en pharmacie sans inscription au registre.') }}
        {{ __('Motif :') }} {{ $d->regularisation }}. {!! __("Les étapes d'avant la pharmacie sont « non tracées ».") !!}</div>
@endif

{{-- Venu de « À faire » pour une étape : son formulaire tout en haut, bien visible. --}}
@php($cible = in_array(request('etape'), $possibles, true) ? request('etape') : null)
@php($cibleDansPreparation = $cible && in_array($cible, $preparation, true))
@if($cible)
    <div class="non-imprime" style="margin-top:12px; outline:3px solid #f59e0b; border-radius:12px;">
        @if($cibleDansPreparation)
            @include('demandes.partials.preparation', ['codes' => $preparation, 'retour' => 'accueil'])
        @else
            @include('demandes.partials.etape', ['code' => $cible, 'retour' => 'accueil'])
        @endif
    </div>
@endif

<div class="carte grille" style="margin-top:12px;">
    <div><span>{{ __('N° CIN') }}</span>{{ $d->cin ? $d->libelleCin() : '—' }}</div>
    <div><span>{{ __('N° dossier') }}</span>{{ $d->num_doss }} @if($d->libelleCas())<b>({{ $d->libelleCas() }})</b>@endif</div>
    @if($paiement = $d->paiement())<div><span>{{ __('Paiement') }}</span>{{ $paiement }}</div>@endif
    <div><span>{{ __('N° demande Clinisys') }}</span>{{ $d->num_demande_clinisys ?? '—' }}</div>
    <div><span>{{ __('Produit') }}</span>{{ $d->nb_poches }} × {{ __(config("demandesang.produits.{$d->produit}")) }} {{ $d->groupe_sanguin }}</div>
    <div><span>{{ __('Poches arrivées à la pharmacie') }}</span>{{ __(':n sur :total', ['n' => $d->recues(), 'total' => $d->nb_poches]) }}@if($d->serviePartiellement()) <b style="color:#c2410c;">· {{ __('servie partiellement') }}</b>@endif</div>
    @if($d->relanceDe)<div><span>{{ __('Relance de') }}</span><a class="loupe" href="{{ route('demandes.show', $d->relanceDe) }}">{{ __('demande :id', ['id' => $d->relanceDe->id]) }}</a> ({{ __('DMI n°') }} {{ $d->relanceDe->num_demande_clinisys }}, {{ $d->relanceDe->ratioPoches() }} {{ __('reçues') }})</div>@endif
    @if($d->relances->isNotEmpty())<div><span>{{ __('Relancée par') }}</span>@foreach($d->relances as $rel)<a class="loupe" href="{{ route('demandes.show', $rel) }}">{{ __('demande :id', ['id' => $rel->id]) }}</a> ({{ __('DMI n°') }} {{ $rel->num_demande_clinisys }})@if(! $loop->last), @endif @endforeach</div>@endif
    <div><span>{{ __('Diagnostic') }}</span>{{ $d->diagnostic }}</div>
    <div><span>{{ __('Poches au CTS') }}</span>{{ __(config("demandesang.natures.{$d->nature}")) }}</div>
    <div><span>{{ __('Médecin') }}</span>{{ $d->medecin ?? '—' }}</div>
    <div><span>{{ __('Service source') }}</span>{{ $ref->service($d->service_source) }}</div>
    <div><span>{{ __('Service de destination') }}</span>{{ $ref->service($d->service_destination) }}
        @if($d->services->where('transfert', true)->isNotEmpty())
            @php($t = $d->services->where('transfert', true)->last())
            <div class="doux petit">{{ __('transfert le :date par :nom', ['date' => $t->saisi_le->format('d/m H:i'), 'nom' => $t->saisi_par]) }}</div>
        @endif</div>
    <div><span>{{ __('Service de soin (Clinisys)') }}</span>{{ $d->service_saisie_dmi ?? '—' }}</div>
    <div><span>{{ __('Inscrite le') }}</span>{{ $d->cree_le->format('d/m/Y H:i') }} {{ __('par') }} {{ $d->cree_par }}</div>
</div>

@if($clinisys)
    {{-- Où est chaque poche de la demande, lu dans Clinisys : en stock, délivrée au service, retournée, pas encore arrivée. --}}
    <div class="carte">
        <h3 style="margin:0;"><span title="Clinisys · {{ __('lecture seule') }}">{{ __('Où sont les poches') }}</span></h3>
        @include('partials.lieux_poches', ['lieux' => $clinisys['lieux'] ?? [], 'demandees' => $d->nb_poches])
        @if(($clinisys['lieux'] ?? []) === [] && ! $d->nb_poches)<p class="doux petit">{{ __('Aucune poche reçue pour cette demande.') }}</p>@endif
    </div>
    @php($dmi = $clinisys['demande'])
    @php($ouvert = ($clinisys['ecarts'] && ($clinisys['ecarts']['manquantes'] || $clinisys['ecarts']['en_trop']))
        || (\App\Clinisys\DemandesDmi::supprimee($dmi) && $d->enCours())
        || ($dmi && strtoupper(trim($dmi->Etat)) === 'V' && ($etat['C5']['statut'] ?? '') !== 'faite'))
    <details class="carte" @if($ouvert) open @endif>
        <summary><strong>Clinisys</strong> · {{ __('demande DMI n°') }} {{ $d->num_demande_clinisys }}
            @if($dmi) · {{ \App\Clinisys\DemandesDmi::libelleEtat($dmi->Etat) }} @endif
            · {{ __(':n bon(s) BD', ['n' => count($clinisys['bons'])]) }} · {{ __(':n ligne(s) PR', ['n' => count($clinisys['prelevements'])]) }}</summary>
        @if($clinisys['erreur'])<div class="alerte rouge">{{ $clinisys['erreur'] }}</div>@endif
        @if(\App\Clinisys\DemandesDmi::supprimee($dmi) && $d->enCours())
            <div class="alerte rouge">⚠ {{ __('Cette demande est') }} <strong>{{ __('supprimée') }}</strong> {!! __("dans le DMI Clinisys alors qu'elle est en cours ici.") !!}</div>
        @endif
        @if($dmi && strtoupper(trim($dmi->Etat)) === 'V' && ($etat['C5']['statut'] ?? '') !== 'faite')
            <div class="alerte rouge">⚠ {{ __('La demande est') }} <strong>{{ __('réceptionnée') }}</strong> {!! __("dans le DMI (V) mais la réception en pharmacie (C5) n'est pas enregistrée ici.") !!}</div>
        @endif

        @if($dmi)
            @php($pr = \App\Clinisys\DemandesDmi::preremplissage($dmi))
            <div class="grille" style="margin-top:8px;">
                <div><span>{{ __('Dossier (DMI)') }}</span>{{ $dmi->NumDoss }} @if(trim($dmi->NumDoss) !== $d->num_doss)<b style="color:#b91c1c;">≠ {{ $d->num_doss }}</b>@endif</div>
                <div><span>{{ __('Saisie le') }}</span>{{ \Illuminate\Support\Carbon::parse($dmi->date_create)->format('d/m/Y H:i') }} {{ __('par') }} {{ $dmi->user_create }}</div>
                <div><span>{{ __('Produits') }}</span>{{ $pr['produit'] ? \App\Clinisys\DemandesDmi::libelleProduits(array_filter([$pr['produit'] => $pr['nb_poches']] + $pr['autres_produits'])) : '—' }}</div>
                <div><span>{{ __('Groupe (DMI)') }}</span>{{ trim($dmi->determination1) ?: '—' }} / {{ trim($dmi->determination2) ?: '—' }}</div>
                <div><span>{{ __('Diagnostic') }}</span>{{ $dmi->Diagnostic }}</div>
                <div><span>{{ __('Médecin') }}</span>{{ $dmi->medecin ?? $dmi->Medecin_prescripteur }}</div>
                <div><span>{{ __('État DMI') }}</span>{{ \App\Clinisys\DemandesDmi::libelleEtat($dmi->Etat) }}</div>
            </div>
        @elseif(! $clinisys['erreur'])
            <p class="erreur">{{ __('Demande n° :num introuvable dans le DMI.', ['num' => $d->num_demande_clinisys]) }}</p>
        @endif

        @if($clinisys['ecarts'])
            @if($clinisys['ecarts']['manquantes'])<div class="alerte rouge">{{ __('Poches du BD Clinisys absentes du contrôle (C3) :') }} {{ implode(', ', $clinisys['ecarts']['manquantes']) }}</div>@endif
            @if($clinisys['ecarts']['en_trop'])<div class="alerte rouge">{{ __('Poches du contrôle (C3) absentes des BD Clinisys :') }} {{ implode(', ', $clinisys['ecarts']['en_trop']) }}</div>@endif
        @endif

        <div class="table-defile">
            <h3>{{ __('Panier dépôt fournisseur') }}</h3>
            <table>
                <tr><th>{{ __('Date') }}</th><th>{{ __('Poche') }}</th><th>{{ __('Article') }}</th><th>{{ __('Péremption') }}</th><th>{{ __('Bon BD') }}</th><th>{{ __('État') }}</th><th>{{ __('Par') }}</th></tr>
                @forelse($clinisys['panier'] as $p)
                    <tr><td>{{ \Illuminate\Support\Carbon::parse($p->DateSys)->format('d/m H:i') }}</td><td>{{ $p->Identifiant }}</td><td>{{ $p->desart ?? $p->CodArt }}</td>
                        <td>{{ $p->DatPer ? \Illuminate\Support\Carbon::parse($p->DatPer)->format('d/m/Y') : '' }}</td><td>{{ $p->numBon_depot }}</td>
                        <td>{{ $p->Etat }} {{ $p->NumQuittance }}</td><td>{{ $p->UserName }}</td></tr>
                @empty
                    <tr><td colspan="7" class="doux">{{ __('Aucune ligne.') }}</td></tr>
                @endforelse
            </table>

            <h3>{{ __('Bons de dépôt FRS (BD)') }}</h3>
            <table>
                <tr><th>{{ __('Bon') }}</th><th>{{ __('Date') }}</th><th>{{ __('Réf. fournisseur') }}</th><th>{{ __('Groupe') }}</th><th>{{ __('Poches') }}</th></tr>
                @forelse($clinisys['bons'] as $b)
                    <tr><td>{{ $b->numaffiche }}</td><td>{{ \Illuminate\Support\Carbon::parse($b->datbon)->format('d/m/Y') }}</td><td>{{ $b->Reffrs }}</td><td>{{ $b->memop }}</td>
                        <td>@foreach($b->poches as $p){{ $p->Identifiant }} · {{ $p->desart }}@if($p->DatPer) · {{ \Illuminate\Support\Carbon::parse($p->DatPer)->format('d/m/Y') }}@endif<br>@endforeach</td></tr>
                @empty
                    <tr><td colspan="5" class="doux">{{ __('Aucun bon de dépôt.') }}</td></tr>
                @endforelse
            </table>

            <h3>{{ __('Bons de prélèvement FRS (PR)') }}</h3>
            <table>
                <tr><th>{{ __('Bon') }}</th><th>{{ __('Date') }}</th><th>{!! __("BD d'origine") !!}</th><th>{{ __('Poche') }}</th><th>{{ __('Article') }}</th></tr>
                @forelse($clinisys['prelevements'] as $p)
                    <tr><td>{{ $p->numaffiche }}</td><td>{{ \Illuminate\Support\Carbon::parse($p->datbon)->format('d/m/Y') }}</td><td>{{ $p->NumBon_Depot }}</td><td>{{ $p->Identifiant }}</td><td>{{ $p->desart }}</td></tr>
                @empty
                    <tr><td colspan="5" class="doux">{{ __('Aucun prélèvement.') }}</td></tr>
                @endforelse
            </table>
        </div>
    </details>
@endif

@foreach($remisesAConfirmer as $r)
    @include('demandes.partials.remise', ['r' => $r, 'retour' => null])
@endforeach

@if($preparation !== [] && ! $cibleDansPreparation)
    <div class="non-imprime">@include('demandes.partials.preparation', ['codes' => $preparation, 'retour' => null])</div>
@endif
@if(array_diff($possibles, $preparation, [$cible]) !== [])
    <div class="non-imprime">
        @foreach(array_diff($possibles, $preparation, [$cible]) as $code)
            @include('demandes.partials.etape', ['code' => $code, 'retour' => null])
        @endforeach
    </div>
@endif

<div class="carte">
    <h2>{{ __('Circuit') }}</h2>
    <table>
        @foreach($etat as $code => $e)
            @continue($e['statut'] === 'sans_objet')
            <tr id="etape-ligne-{{ $code }}" @if(in_array($code, session('etapes_faites', []), true)) class="etape-juste-faite" @endif>
                <td style="width:38px;">
                    @switch($e['statut'])
                        @case('faite') ✅ @break
                        @case('remise_attente') ⏳ @break
                        @case('disponible') 🔴 @break
                        @default ⚪
                    @endswitch
                </td>
                <td><strong>{{ $code }}</strong> {{ __($e['def']['libelle']) }}
                    @if($e['statut'] === 'remise_attente')
                        <div class="petit" style="color:var(--orange);">{{ __('Remis par :nom à :heure', ['nom' => $e['remise']->nom_donneur, 'heure' => $e['remise']->remis_le->format('H:i')]) }}</div>
                        <div style="margin-top:3px;">@include('partials.role', ['roles' => $e['def']['receveur'], 'prefixe' => 'En attente de confirmation par'])</div>
                    @elseif($e['statut'] === 'non_tracee')
                        <div class="doux petit">{{ __('Non tracée : demande inscrite a posteriori (reçue en pharmacie hors registre)') }}</div>
                    @elseif($e['statut'] === 'disponible' && \App\Services\Circuit::automatique($e['def']))
                        <div class="petit" style="color:#7c3aed;">⏳ {{ __('Automatique : enregistrée dès que la pharmacie saisit le :bon dans Clinisys', ['bon' => __($e['def']['auto'])]) }}</div>
                    @elseif($e['statut'] === 'disponible')
                        <div style="margin-top:3px;">@include('partials.role', ['roles' => $e['def']['roles']])</div>
                    @endif
                </td>
                <td class="petit">
                    @if($e['statut'] === 'faite')
                        @if($e['remise'])
                            {{ __('Remis :') }} {{ $e['remise']->nom_donneur }} · {{ $e['remise']->remis_le->format('d/m H:i') }}<br>
                            {{ __('Reçu :') }} {{ $e['remise']->nom_receveur }} · {{ $e['remise']->repondu_le->format('d/m H:i') }}
                        @else
                            {{ $acteur($e['action']) }}
                        @endif
                    @endif
                </td>
            </tr>
        @endforeach
    </table>
</div>

@if($d->poches->isNotEmpty())
    <div class="carte table-defile">
        <h2>{{ __('Poches') }}</h2>
        <table>
            <tr><th>{{ __('N° poche') }}</th><th>{{ __('Produit') }}</th><th>{{ __('Groupe') }}</th><th>{{ __('Péremption') }}</th><th>{{ __('Bon de dépôt') }}</th><th>{{ __('État') }}</th></tr>
            @foreach($d->poches as $p)
                <tr><td>{{ $p->numero }}</td><td>{{ $p->produit }}</td><td>{{ $p->groupe_sanguin }}</td>
                    <td>{{ $p->peremption?->format('d/m/Y') }}</td><td>{{ $p->bon_depot }}</td>
                    <td>{{ __(\App\Models\Poche::STATUTS[$p->statut]) }}@if($p->motif) · {{ __(config("demandesang.motifs_non_utilisation.{$p->motif}")) }}@endif</td></tr>
            @endforeach
        </table>
    </div>
@endif

<details class="carte non-imprime">
    <summary>{{ __('Qui a consulté cette demande (:n personne(s))', ['n' => $consultations->count()]) }}</summary>
    <table class="petit" style="margin-top:6px;">
        <tr><th>{{ __('Personne') }}</th><th>{{ __('Dernière consultation') }}</th><th>{{ __('Nombre') }}</th></tr>
        @forelse($consultations as $c)
            <tr><td>{{ $nomsComptes[$c->user_name] ?? $c->user_name }}<div class="doux">{{ $c->user_name }}</div></td>
                <td>{{ \Illuminate\Support\Carbon::parse($c->derniere)->format('d/m/Y H:i') }}</td><td>{{ $c->fois }}</td></tr>
        @empty
            <tr><td colspan="3" class="doux">{{ __('Pas encore de consultation enregistrée.') }}</td></tr>
        @endforelse
    </table>
    @if(auth()->user()->admin)<a class="petit" href="{{ route('admin.journal', ['demande' => $d->id]) }}">{{ __('Voir le détail dans le journal') }}</a>@endif
</details>

<div class="carte">
    <h2>{{ __('Chronologie (registre)') }}</h2>
    <table>
        @foreach($d->actions as $a)
            @php($annulee = in_array($a->id, $annulees, true))
            <tr @if($annulee) style="text-decoration:line-through; color:var(--doux);" @endif>
                <td class="petit" style="white-space:nowrap;">{{ $a->fait_le->format('d/m H:i') }}</td>
                <td>
                    <strong>{{ __($a->libelle()) }}</strong>
                    @if(in_array($a->etape, ['remise_confirmee', 'remise_refusee', 'correction'], true) && isset($a->donnees['etape']))
                        : {{ __(config('demandesang.etapes.'.$a->donnees['etape'].'.court') ?? $a->donnees['etape']) }}
                    @endif
                    @include('demandes.partials.donnees', ['a' => $a])
                    @if($a->commentaire)<div class="petit">💬 {{ $a->commentaire }}</div>@endif
                    @if(! $annulee && isset($etat[$a->etape]) && $etat[$a->etape]['def']['type'] === 'simple' && $etat[$a->etape]['action']?->id === $a->id && $u->a(...$etat[$a->etape]['def']['roles']) && $d->enCours())
                        <details class="non-imprime"><summary class="doux petit">{{ __('Corriger cette saisie') }}</summary>
                            <form method="post" action="{{ route('actions.corriger', $a) }}" class="ligne">@csrf
                                <input name="motif" placeholder="{{ __('Motif de la correction') }}" required style="flex:1;">
                                <button class="btn petit gris" type="submit">{{ __('Corriger') }}</button>
                            </form>
                        </details>
                    @endif
                </td>
                <td class="petit">@if($a->user_name === 'clinisys')<span class="badge" style="background:#7c3aed;">Clinisys</span> @endif{{ $a->nom_acteur }}<div class="doux">{{ $a->user_name }}</div></td>
            </tr>
        @endforeach
    </table>
</div>

<div class="non-imprime">
    @if($d->enCours() && $u->a('major'))
        <details class="carte"><summary>{{ __('Modifier les services (transfert du patient)') }}</summary>
            <form method="post" action="{{ route('demandes.services', $d) }}">@csrf
                <div class="grille">
                    <div class="champ"><label>{{ __('Service source') }}</label><select name="service_source" required>
                        @foreach($ref->services() as $c => $l)<option value="{{ $c }}" @selected($d->service_source === $c)>{{ $l }}</option>@endforeach</select></div>
                    <div class="champ"><label>{{ __('Service de destination') }}</label><select name="service_destination" required>
                        @foreach($ref->services() as $c => $l)<option value="{{ $c }}" @selected($d->service_destination === $c)>{{ $l }}</option>@endforeach</select></div>
                </div>
                <div class="champ"><input name="commentaire" placeholder="{{ __('Commentaire') }}" style="width:100%;"></div>
                <button class="btn sec" type="submit">{{ __('Enregistrer') }}</button>
            </form>
        </details>
    @endif
    <details class="carte"><summary>{{ __('Ajouter un commentaire') }}</summary>
        <form method="post" action="{{ route('demandes.commentaire', $d) }}">@csrf
            <textarea name="texte" rows="2" required style="width:100%;"></textarea>
            <button class="btn sec" type="submit" style="margin-top:6px;">{{ __('Ajouter') }}</button>
        </form>
    </details>
    @if($d->enCours() && $u->a('major', 'surveillant'))
        <details class="carte" @if($d->relances->isNotEmpty() && $d->recues() === 0) open @endif><summary style="color:#b91c1c;">{{ __('Annuler la demande') }}</summary>
            @if($d->relances->isNotEmpty() && $d->recues() === 0)<p class="doux petit">{!! __("Aucune poche reçue et la demande a été relancée : vous pouvez l'annuler.") !!}</p>@endif
            <form method="post" action="{{ route('demandes.annuler', $d) }}" class="ligne">@csrf
                <input name="motif" placeholder="{{ __("Motif de l'annulation") }}" required style="flex:1;" value="{{ $d->relances->isNotEmpty() && $d->recues() === 0 ? 'Relancée par la demande '.$d->relances->last()->id : '' }}">
                <button class="btn" type="submit" onclick="return confirm(@js(__('Annuler cette demande ?')))">{{ __('Annuler la demande') }}</button>
            </form>
        </details>
    @endif
</div>
@endsection
