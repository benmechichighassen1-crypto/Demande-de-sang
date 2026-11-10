@extends('layouts.app')
@section('titre', __('Rechercher / inscrire'))
@php($verrou = fn ($champ) => $demandeDmi && ($prerempli[$champ] ?? null) !== null && ($prerempli[$champ] ?? '') !== '')
@section('contenu')
<h1>{{ __('Rechercher un patient / inscrire une demande') }}</h1>

<div class="carte">
    @if($clinisys)
        <form method="get" class="ligne">
            <input name="q" value="{{ $recherche !== '' ? $recherche : $numDemande }}" placeholder="{{ __('N° de demande DMI, nom, n° de dossier ou identifiant (ex. I2605044)') }}" style="flex:1; min-width:200px;" autocomplete="off" @if($recherche === '' && $numDemande === '' && $numDoss === '') autofocus @endif>
            <button class="btn" type="submit">{{ __('Rechercher') }}</button>
        </form>
    @else
        <form method="get" class="ligne">
            <input name="num_doss" value="{{ $numDoss }}" placeholder="{{ __('N° de dossier') }}" required style="flex:1; min-width:180px;" inputmode="numeric">
            <button class="btn sec" type="submit">{{ __('Vérifier') }}</button>
        </form>
        <p class="doux petit">{{ __('Lecture Clinisys désactivée : le nom du patient et la demande seront saisis à la main.') }}</p>
    @endif
</div>

@if($recherche !== '')
    <div class="carte table-defile">
        <h2>{{ __('Patients trouvés pour « :q »', ['q' => $recherche]) }} ({{ count($resultats) }}{{ count($resultats) >= 30 ? __(', les 30 plus récents') : '' }})</h2>
        <table>
            <tr><th>{{ __('Patient') }}</th><th>{{ __('Dossier(s) récents') }}</th><th>{{ __('Service de soin') }}</th><th>{{ __('Dernière arrivée') }}</th><th>{{ __('Demandes de sang DMI (30 j)') }}</th></tr>
            @forelse($resultats as $p)
                <tr>
                    <td><strong>{{ $p->NomCli }} {{ $p->Prenom }}</strong><div class="doux petit">{{ $p->Identifiant ?: '—' }}@if(! empty($p->cin)) · {{ __('CIN') }} {{ $p->cin }}@endif</div></td>
                    <td>{{ implode(', ', array_slice($p->dossiers, 0, 3)) }}@if(count($p->dossiers) > 3)<div class="doux petit">{{ __('+ :n autre(s)', ['n' => count($p->dossiers) - 3]) }}</div>@endif</td>
                    <td>{{ $p->service ?? '—' }}</td>
                    <td>{{ $p->DatArr ? \Illuminate\Support\Carbon::parse($p->DatArr)->format('d/m/Y') : '' }}</td>
                    <td>
                        @forelse($p->demandes as $x)
                            @php($pr = \App\Clinisys\DemandesDmi::preremplissage($x))
                            <div class="ligne" style="margin-bottom:4px;">
                                <span>{{ __('n° :id', ['id' => $x->ID]) }} · {{ \Illuminate\Support\Carbon::parse($x->date_create)->format('d/m H:i') }}
                                    · {{ $pr['produit'] ? __(\App\Clinisys\DemandesDmi::libelleProduits(array_filter([$pr['produit'] => $pr['nb_poches']] + $pr['autres_produits']))) : '—' }}
                                    · {{ __(\App\Clinisys\DemandesDmi::libelleEtat($x->Etat)) }}</span>
                                @if($x->app)
                                    <a class="loupe" href="{{ route('demandes.show', $x->app) }}">{{ __('inscrite (demande :id)', ['id' => $x->app->id]) }}</a>
                                @elseif($peutInscrire && ! \App\Clinisys\DemandesDmi::supprimee($x))
                                    <a class="btn petit" href="{{ route('demandes.create', ['num_demande' => $x->ID]) }}">{{ __('Inscrire') }}</a>
                                @elseif(! \App\Clinisys\DemandesDmi::supprimee($x))
                                    <span style="color:#b91c1c;">{{ __('non inscrite') }}</span>
                                @endif
                            </div>
                        @empty
                            <a class="btn petit sec" href="{{ route('demandes.create', ['num_doss' => $p->dossiers[0]]) }}">{{ __('Vérifier') }}</a>
                            <span class="doux petit">{{ __('aucune demande DMI') }}</span>
                        @endforelse
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="doux">{{ __('Aucun patient trouvé. Pour un nom, tapez le début (ex. « KHAR »).') }}</td></tr>
            @endforelse
        </table>
    </div>
    @if($demandesAppli->isNotEmpty())
        <div class="carte table-defile">
            <h2>{!! __("Demandes inscrites dans l'appli") !!} ({{ $demandesAppli->count() }})</h2>
            <table>
                <tr><th>{{ __('N°') }}</th><th>{{ __('Patient') }}</th><th>{{ __('Dossier') }}</th><th>DMI</th><th>{{ __('Inscrite le') }}</th><th>{{ __('État') }}</th></tr>
                @foreach($demandesAppli as $d)
                    <tr><td><a class="loupe" href="{{ route('demandes.show', $d) }}">{{ $d->id }}</a></td><td>{{ $d->nom_patient }}</td><td>{{ $d->num_doss }}</td><td>{{ $d->num_demande_clinisys }}</td>
                        <td>{{ $d->cree_le->format('d/m/Y H:i') }}</td><td>@if($d->statut !== 'en_cours')<span class="badge s-{{ $d->statut }}">{{ $d->statut === 'cloturee' ? __('Clôturée') : __('Annulée') }}</span>@endif {{ __($d->libelleEtape()) }}</td></tr>
                @endforeach
            </table>
        </div>
    @endif
@endif

@if($erreur)<div class="alerte rouge">{{ $erreur }}</div>@endif

@if($numDoss !== '')
    @if($clinisys && ! $patient)
        <div class="alerte rouge">{{ __('Dossier :n introuvable dans Clinisys.', ['n' => $numDoss]) }}</div>
    @else
        @if($patient)
            <div class="carte grille">
                <div><span>{{ __('Patient') }}</span>{{ $patient->NomCli }} {{ $patient->Prenom }}</div>
                <div><span>{{ __('N° dossier') }}</span>{{ $numDoss }}</div>
                <div><span>{{ __('Identifiant') }}</span>{{ $patient->Identifiant }}</div>
                <div><span>{{ __('N° CIN') }}</span>@if(trim((string) ($patient->NumCIN ?? '')) !== ''){{ strtoupper(trim($patient->NumCIN)) }}@else<strong style="color:#b91c1c;">{{ __('absent de Clinisys : à saisir ci-dessous') }}</strong>@endif</div>
                <div><span>{{ __('Service de soin') }}</span>{{ $patient->service ?? '—' }}@if(! empty($patient->NumCha ?? null)) · {{ __('ch.') }} {{ $patient->NumCha }}@endif</div>
                <div><span>{{ __('Médecin') }}</span>{{ $patient->medecin ?? '—' }}</div>
            </div>
        @endif

        @if($recentes !== [])
            <div class="carte">
                <h2>{{ __('Demandes DMI des dernières 48 h pour ce dossier') }}</h2>
                <table>
                    <tr><th>{{ __('N°') }}</th><th>{{ __('Saisie le') }}</th><th>{{ __('Produits') }}</th><th>{{ __('Diagnostic') }}</th><th>{{ __('État DMI') }}</th><th></th></tr>
                    @foreach($recentes as $r)
                        @php($pr = \App\Clinisys\DemandesDmi::preremplissage($r))
                        <tr @if($demandeDmi && (int) $demandeDmi->ID === (int) $r->ID) style="background:#fef9c3" @endif>
                            <td>{{ $r->ID }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($r->date_create)->format('d/m H:i') }}</td>
                            <td>{{ $pr['produit'] ? __(\App\Clinisys\DemandesDmi::libelleProduits(array_filter([$pr['produit'] => $pr['nb_poches']] + $pr['autres_produits']))) : '—' }}</td>
                            <td>{{ $r->Diagnostic }}</td>
                            <td>{{ __(\App\Clinisys\DemandesDmi::libelleEtat($r->Etat)) }}</td>
                            <td>@unless(($demandeDmi && (int) $demandeDmi->ID === (int) $r->ID) || \App\Clinisys\DemandesDmi::supprimee($r))<a class="btn petit sec" href="{{ route('demandes.create', ['num_demande' => $r->ID]) }}">{{ __('Choisir') }}</a>@endunless</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif

        {{-- A2 : tout ce qui existe déjà pour ce patient, sur tous ses dossiers (même identifiant).
             Orange = à vérifier (motif obligatoire) ; bleu = relance probable ou information. --}}
        @php($nbDispo = $a2['total'])
        @php($nbDossiers = count($a2['dossiers']))
        {{-- Phrases en clair pour les autres demandes du patient : produits écrits en toutes lettres, date et heure, dossier. --}}
        @php($enClair = function ($x) {
            $pr = \App\Clinisys\DemandesDmi::preremplissage($x);
            $liste = array_filter([$pr['produit'] => $pr['nb_poches']] + $pr['autres_produits']);
            $noms = ['CGR' => ['culot globulaire', 'culots globulaires'], 'PFC' => ['plasma frais congelé (PFC)', 'plasmas frais congelés (PFC)'],
                'CPS' => ['concentré plaquettaire (CP)', 'concentrés plaquettaires (CP)'], 'CPA' => ["concentré plaquettaire d'aphérèse (CPA)", "concentrés plaquettaires d'aphérèse (CPA)"],
                'AUTRE' => ['autre produit', 'autres produits']];

            return $liste === [] ? __('produit non précisé')
                : implode(', ', array_map(fn ($p, $n) => $n.' '.__($noms[$p][$n > 1 ? 1 : 0] ?? $p), array_keys($liste), $liste));
        })
        @php($quand = fn ($x) => ['id' => $x->ID, 'date' => \Illuminate\Support\Carbon::parse($x->date_create)->format('d/m'), 'heure' => \Illuminate\Support\Carbon::parse($x->date_create)->format('H:i')])
        {{-- « Reçues » = arrivées à la pharmacie (bons BD) ; puis délivrées au service (bons PA) ou encore en stock. --}}
        @php($devenir = fn ($x) => __(':recues reçue(s) sur :demandees à la pharmacie · :delivrees délivrée(s) au service · :stock en stock', ['recues' => $x->recues, 'demandees' => $x->demandees, 'delivrees' => $x->delivrees ?? 0, 'stock' => $x->en_stock ?? 0]))
        @php($dossierDe = fn ($x) => trim((string) $x->NumDoss) === trim((string) $numDoss) ? __('dossier :n', ['n' => trim($x->NumDoss)]) : __('autre dossier du même patient : :n', ['n' => trim($x->NumDoss)]))
        @if($a2['deja'])
            <div class="alerte rouge"><strong>{{ __('Cette demande DMI est déjà inscrite :') }}</strong>
                <a class="loupe" href="{{ route('demandes.show', $a2['deja']) }}">{{ __('demande :id', ['id' => $a2['deja']->id]) }}</a> ({{ __($a2['deja']->libelleEtape()) }}).</div>
        @else
            {{-- Poche disponible (diagramme, page 4) : pas de nouvelle demande, on la récupère à la pharmacie. --}}
            @if($a2['stockBS'] !== [])
                <div class="alerte" style="border-color:#c2410c; background:#fff7ed;">
                    <strong>⚠ {{ __(':n poche(s) déjà disponible(s) pour ce patient : pas de nouvelle demande, à récupérer à la pharmacie.', ['n' => count($a2['stockBS'])]) }}</strong>
                    <table class="petit" style="margin-top:6px;">
                        <thead><tr><th>{{ __('Dossier') }}</th><th>{{ __('N° poche') }}</th><th>{{ __('Produit') }}</th><th>{{ __('Groupe') }}</th><th>{{ __('Péremption') }}</th><th>{{ __('Bon') }}</th><th>{{ __('Demande DMI') }}</th><th></th></tr></thead>
                        <tbody>
                        @foreach($a2['stockBS'] as $p)
                            <tr>
                                <td>{{ trim((string) ($p->NumDoss ?? '')) }}@if(! empty($p->meme_nom_seul))<div style="color:#b91c1c; font-weight:600;">{{ __('Même nom, autre dossier : à vérifier') }}</div>@endif</td>
                                <td><a class="loupe" href="{{ route('stock', ['q' => trim((string) $p->Identifiant), 'vue' => 'bs_stock']) }}" target="_blank">{{ $p->Identifiant }}</a></td>
                                <td>{{ $p->desart }}</td><td>{{ $p->memop }}</td>
                                <td style="white-space:nowrap;">{{ $p->DatPer ? \Illuminate\Support\Carbon::parse($p->DatPer)->format('d/m/Y') : '' }}</td>
                                <td>{{ $p->bon_depot }}</td><td>{{ $p->numDemande ?: '' }}</td>
                                <td>
                                    <form method="post" action="{{ route('demandes.recuperer_poche') }}" style="margin:0;">@csrf
                                        <input type="hidden" name="poche" value="{{ trim((string) $p->Identifiant) }}">
                                        <input type="hidden" name="num_doss" value="{{ trim((string) ($p->NumDoss ?? '')) }}">
                                        <input type="hidden" name="num_demande" value="{{ $numDemande }}">
                                        <button class="btn petit" type="submit">{{ __('Récupérer la poche à la pharmacie') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if($a2['totalAutres'] > 0)
                <div class="alerte" style="border-color:#f59e0b;">
                    <strong>{{ __('À vérifier : :n demande(s) ou poche(s) pour ce patient', ['n' => $a2['totalAutres']]) }}</strong> ({{ __(':n dossier(s) vérifié(s)', ['n' => $nbDossiers]) }}).
                    <ul style="margin:6px 0 0;">
                        @foreach($a2['doublons'] as $x)
                            <li>{{ __('Ce patient a déjà une demande pour laquelle aucune poche n\'est arrivée : n° :id du :date à :heure (:dossier), :produits.', $quand($x) + ['dossier' => $dossierDe($x), 'produits' => $enClair($x)]) }}
                                @if($x->app)<a class="loupe" href="{{ route('demandes.show', $x->app) }}">{{ __('Elle est suivie dans l\'appli : demande :id (:etape).', ['id' => $x->app->id, 'etape' => __($x->app->libelleEtape())]) }}</a>@else {{ __('Elle n\'est pas inscrite dans l\'appli.') }} @endif
                                <strong>{{ __('Doublon possible : vérifiez avec le CTS avant d\'inscrire.') }}</strong>
                                @include('partials.lieux_poches', ['lieux' => $x->lieux ?? [], 'demandees' => $x->demandees])</li>
                        @endforeach
                        @foreach($a2['enCoursSansPoche'] as $e)
                            <li>{{ __('Une demande est déjà en cours dans l\'appli pour ce patient, sans poche reçue :') }} <a class="loupe" href="{{ route('demandes.show', $e) }}">{{ __('demande :id', ['id' => $e->id]) }}</a> ({{ __('dossier :n', ['n' => $e->num_doss]) }}, {{ __('étape : :etape', ['etape' => __($e->libelleEtape())]) }}).</li>
                        @endforeach
                        @foreach($a2['disponibles'] as $p)
                            <li>{{ __('Poche :n', ['n' => $p->numero]) }} · {{ $p->produit }} {{ $p->groupe_sanguin }} · {{ __(\App\Models\Poche::STATUTS[$p->statut]) }}
                                · <a class="loupe" href="{{ route('demandes.show', $p->demande_id) }}">{{ __('demande :id', ['id' => $p->demande_id]) }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @php($infos = collect($a2['autresDmi'])->where('classe', '<>', 'doublon'))
            @if($infos->isNotEmpty() || $a2['sorties'] !== [])
                <div class="alerte" style="background:#eff6ff; border-color:#93c5fd;">
                    <strong>{{ __('Pour information') }}</strong> · <span class="doux">{{ __('Information seulement, l\'inscription reste possible.') }}</span>
                    <ul style="margin:6px 0 0;">
                        @foreach($infos as $x)
                            @if($x->classe === 'relance')
                                <li data-relance="{{ $x->ID }}">⚠ {{ __('Ce patient a déjà une demande non complète : n° :id du :date à :heure (:dossier), :produits demandé(s).', $quand($x) + ['dossier' => $dossierDe($x), 'produits' => $enClair($x)]) }}
                                    <strong>{{ $devenir($x) }}</strong>.
                                    {{ __('Cette nouvelle demande est peut-être une relance pour les poches manquantes.') }}
                                    @if($x->app)<a class="loupe" href="{{ route('demandes.show', $x->app) }}">{{ __('Suivie dans l\'appli : demande :id (:etape).', ['id' => $x->app->id, 'etape' => __($x->app->libelleEtape())]) }}</a>@endif
                                    @include('partials.lieux_poches', ['lieux' => $x->lieux ?? [], 'demandees' => $x->demandees])
                                    @if($peutInscrire && $demandeDmi && ! \App\Clinisys\DemandesDmi::supprimee($demandeDmi))
                                        <div class="ligne" style="gap:6px; margin-top:4px;">
                                            <button type="button" class="btn petit" onclick="choisirRelance('{{ $x->ID }}')">{{ __('Oui, c\'est une relance de la n° :id', ['id' => $x->ID]) }}</button>
                                            <button type="button" class="btn petit sec" onclick="choisirRelance('')">{{ __('Non') }}</button>
                                        </div>
                                    @endif
                                </li>
                            @else
                                <li>{{ __('Ce patient a eu une autre demande, déjà servie : n° :id du :date à :heure (:dossier), :produits, toutes les poches reçues.', $quand($x) + ['dossier' => $dossierDe($x), 'produits' => $enClair($x)]) }} <strong>{{ $devenir($x) }}</strong>.
                                    @if($x->app) <a class="loupe" href="{{ route('demandes.show', $x->app) }}">{{ __('Suivie dans l\'appli : demande :id (:etape).', ['id' => $x->app->id, 'etape' => __($x->app->libelleEtape())]) }}</a>@endif
                                    @include('partials.lieux_poches', ['lieux' => $x->lieux ?? [], 'demandees' => $x->demandees])</li>
                            @endif
                        @endforeach
                        @foreach($a2['sorties'] as $s)
                            <li>{{ __('Une poche a été donnée à ce patient le :date : :produit, bon :bon (dossier :doss).', ['date' => \Illuminate\Support\Carbon::parse($s->datesys)->format('d/m'), 'produit' => $s->desart, 'bon' => $s->numaffiche, 'doss' => trim((string) $s->numdoss)]) }}</li>
                        @endforeach
                    </ul>
                    <script>
                        // « Oui / Non » : coche le choix « relance » du formulaire (lien Relance de / Relancée par à l'inscription).
                        function choisirRelance(id) {
                            const r = document.querySelector('input[name=relance_de][value="' + id + '"]');
                            if (r) { r.checked = true; r.dispatchEvent(new Event('change')); r.closest('.alerte')?.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
                            document.querySelectorAll('[data-relance] .ligne').forEach(b => b.style.display = 'none');
                        }
                    </script>
                </div>
            @endif
            @if($nbDispo === 0 && $infos->isEmpty() && $a2['sorties'] === [])
                <div class="alerte" style="background:#dcfce7; border-color:#86efac;">✔ {{ __('Aucune autre demande ni poche pour ce patient') }}
                    ({{ $clinisys ? __('tous dossiers : :liste, appli et Clinisys', ['liste' => implode(', ', $a2['dossiers'])]) : __('appli') }}, {{ __('étape A2') }}).</div>
            @endif
        @endif

        @if($clinisys && ! $demandeDmi)
            <div class="alerte">{!! __("Choisissez la demande DMI ci-dessus (ou saisissez son n° en haut) pour continuer : avec Clinisys, la demande doit d'abord être saisie dans le DMI.") !!}</div>
        @elseif($a2['deja'])
            {{-- Déjà inscrite (bandeau ci-dessus) : pas de second formulaire. --}}
        @elseif(! $peutInscrire)
            <div class="alerte">{!! __("Consultation seulement : l'inscription au registre est faite par l'infirmière ou le major du service.") !!}</div>
        @elseif(\App\Clinisys\DemandesDmi::supprimee($demandeDmi))
            <div class="alerte rouge">{{ __('La demande DMI n° :id est supprimée dans Clinisys : elle ne peut pas être inscrite au registre.', ['id' => $demandeDmi->ID]) }}</div>
        @else
            <form method="post" action="{{ route('demandes.store') }}" class="carte">
                @csrf
                <input type="hidden" name="num_doss" value="{{ $numDoss }}">
                <h2>{{ __('2. Demande') }} @if($demandeDmi)({{ __('lue dans le DMI : n° :id, état : :etat', ['id' => $demandeDmi->ID, 'etat' => __(\App\Clinisys\DemandesDmi::libelleEtat($demandeDmi->Etat))]) }})@else({{ __('saisie dans Clinisys') }})@endif</h2>
                @if($demandeDmi)<p class="doux petit">🔒 {{ __('Les champs grisés viennent de la demande DMI et ne se modifient que dans Clinisys.') }}</p>@endif
                @if(! empty($prerempli['autres_produits']))
                    <div class="alerte">{{ __('La demande DMI porte aussi : :produits. Cette demande suit le premier produit ; les autres seront notés en commentaire.', ['produits' => __(\App\Clinisys\DemandesDmi::libelleProduits($prerempli['autres_produits']))]) }}</div>
                @endif
                @unless($patient)
                    <div class="champ"><label>{{ __('Nom et prénom du patient') }}</label><input name="nom_patient" value="{{ old('nom_patient') }}" required></div>
                @endunless
                @if($demandeDmi)
                    {{-- Lu dans le DMI : récapitulatif, il ne reste que l'urgence et la nature à choisir. --}}
                    <div class="grille" style="margin-bottom:10px;">
                        <div><span>{{ __('Produit') }}</span>{{ $prerempli['produit'] ? $prerempli['nb_poches'].' × '.__(config('demandesang.produits.'.$prerempli['produit'])) : '—' }}</div>
                        <div><span>{{ __('Groupe') }}</span>{{ $prerempli['groupe_sanguin'] ?? __('non identifié') }}</div>
                        <div><span>{{ __('Diagnostic') }}</span>{{ $prerempli['diagnostic'] ?: '—' }}</div>
                        <div><span>{{ __('Médecin') }}</span>{{ $prerempli['medecin'] ?? '—' }}</div>
                    </div>
                @endif
                <div class="grille" @if($demandeDmi && $verrou('produit') && $verrou('nb_poches') && $verrou('diagnostic')) style="display:none" @endif>
                    <div class="champ"><label>{{ __('N° de la demande dans Clinisys') }}</label>
                        <input name="num_demande_clinisys" value="{{ $demandeDmi ? $demandeDmi->ID : old('num_demande_clinisys') }}" required inputmode="numeric" @if($demandeDmi) readonly @endif></div>
                    <div class="champ"><label>{{ __('Produit') }}</label>
                        @if($verrou('produit'))
                            <input type="hidden" name="produit" value="{{ $prerempli['produit'] }}"><input value="{{ __(config('demandesang.produits.'.$prerempli['produit'])) }}" readonly>
                        @else
                            <select name="produit" required>@foreach(config('demandesang.produits') as $c => $l)<option value="{{ $c }}" @selected(old('produit') === $c)>{{ __($l) }}</option>@endforeach</select>
                        @endif</div>
                    <div class="champ"><label>{{ __('Nombre de poches') }}</label><input type="number" name="nb_poches" min="1" max="20" value="{{ $verrou('nb_poches') ? $prerempli['nb_poches'] : old('nb_poches') }}" required @if($verrou('nb_poches')) readonly @endif></div>
                    <div class="champ"><label>{{ __('Groupe sanguin (si connu)') }}</label>
                        @if($verrou('groupe_sanguin'))
                            <input name="groupe_sanguin" value="{{ $prerempli['groupe_sanguin'] }}" readonly>
                        @else
                            <select name="groupe_sanguin"><option value="">—</option>@foreach(config('demandesang.groupes') as $g)<option @selected(old('groupe_sanguin') === $g)>{{ $g }}</option>@endforeach</select>
                        @endif</div>
                </div>
                <div class="champ" @if($verrou('diagnostic')) style="display:none" @endif><label>{{ __('Diagnostic') }}</label><input name="diagnostic" value="{{ $verrou('diagnostic') ? $prerempli['diagnostic'] : old('diagnostic') }}" required @if($verrou('diagnostic')) readonly @endif></div>
                <div class="champ" @if($verrou('medecin')) style="display:none" @endif><label>{{ __('Médecin prescripteur') }}</label><input name="medecin" value="{{ $verrou('medecin') ? $prerempli['medecin'] : old('medecin', $patient->medecin ?? '') }}" @if($verrou('medecin')) readonly @endif></div>

                <div class="champ"><label>{!! __("Degré d'urgence") !!}</label>
                    <div class="choix">@foreach(config('demandesang.urgences') as $c => $l)<label><input type="radio" name="urgence" value="{{ $c }}" @checked(old('urgence') === $c) required> {{ __($l) }}</label>@endforeach</div></div>
                <div class="champ"><label>{{ __('Poches au CTS') }}</label>
                    <div class="choix">@foreach(config('demandesang.natures') as $c => $l)<label><input type="radio" name="nature" value="{{ $c }}" @checked(old('nature', 'acheminement') === $c) required> {{ __($l) }}</label>@endforeach</div></div>
                {{-- N° CIN (exigé par la banque de sang) : à saisir quand Clinisys ne l'a pas. --}}
                @if(trim((string) ($patient->NumCIN ?? '')) === '')
                    <div class="champ alerte" style="background:#fff7ed; border-color:#fdba74;">
                        <label>{{ __('N° CIN du patient (exigé par la banque de sang)') }}</label>
                        <input name="cin" value="{{ old('cin') }}" maxlength="20" placeholder="AB123456" autocomplete="off" style="text-transform:uppercase; max-width:220px;" dir="ltr">
                        @error('cin')<div class="erreur">{{ $message }}</div>@enderror
                        <label style="display:block; margin-top:6px;"><input type="checkbox" name="sans_cin" value="1" @checked(old('sans_cin'))> {{ __('Pas de CIN (mineur / étranger) : autre pièce') }}</label>
                        <input name="piece_autre" value="{{ old('piece_autre') }}" maxlength="40" placeholder="{{ __('ex. Passeport n° …') }}" style="max-width:320px;">
                        @error('piece_autre')<div class="erreur">{{ $message }}</div>@enderror
                    </div>
                @endif
                {{-- Cas du patient (diagramme, pages 3 à 5) : déduit du service de soin Clinisys, modifiable. --}}
                @php($externeParDefaut = in_array(strtoupper(trim((string) ($patient->service_code ?? ''))), config('demandesang.services_externes'), true))
                @php($casChoisi = old('cas_patient', $externeParDefaut ? 'externe' : 'hospitalise'))
                <div class="champ"><label>{{ __('Cas du patient') }}</label>
                    <div class="choix">@foreach(config('demandesang.cas_patient') as $c => $l)<label><input type="radio" name="cas_patient" value="{{ $c }}" @checked($casChoisi === $c) required> {{ __($l) }}</label>@endforeach</div>
                    @if($externeParDefaut)<div class="doux petit">{!! __("Patient externe proposé car le patient est en :service.", ['service' => e($patient->service)]) !!}</div>@endif
                    <div class="doux petit">{{ __('Externe et hors séjour : ouverture du dossier (en instance) et paiement du montant prévisionnel.') }}</div>
                    @if(\App\Http\Controllers\DemandeController::hospitalisationEnCours($patient))<div class="doux petit">{{ __('Ce dossier est une hospitalisation en cours : pour une transfusion hors séjour, la demande doit être faite sur un second dossier.') }}</div>@endif
                    @error('cas_patient')<div class="erreur">{{ $message }}</div>@enderror
                </div>
                {{-- Poche disponible : la nouvelle demande exige un motif, régularisation comprise. --}}
                @if($a2['stockBS'] !== [])
                    <div class="alerte rouge">
                        <label style="display:block; font-weight:600;">{{ __('Motif de la nouvelle demande malgré la poche disponible') }}</label>
                        <select name="motif_poche" required style="margin-top:6px;"><option value="">{{ __('Choisir…') }}</option>
                            @foreach(config('demandesang.motifs_poche_disponible') as $c => $l)<option value="{{ $c }}" @selected(old('motif_poche') === $c)>{{ __($l) }}</option>@endforeach</select>
                        <input name="motif_poche_detail" maxlength="255" placeholder="{{ __('Précisions') }}" value="{{ old('motif_poche_detail') }}" style="width:100%; margin-top:6px;">
                        @error('motif_poche')<div class="erreur">{{ $message }}</div>@enderror
                        @error('motif_poche_detail')<div class="erreur">{{ $message }}</div>@enderror
                    </div>
                @endif

                @if($a2['relances'] !== [])
                    {{-- Relance : le CTS a livré une partie des poches, l'équipe redemande le reste. --}}
                    <div class="alerte" style="background:#eff6ff; border-color:#93c5fd;">
                        @foreach($a2['relances'] as $x)
                            <label style="display:block; margin-bottom:4px;"><input type="radio" name="relance_de" value="{{ $x->ID }}" data-motif="{{ __('Relance de la demande DMI n° :id : le CTS a livré :recues/:demandees', ['id' => $x->ID, 'recues' => $x->recues, 'demandees' => $x->demandees]) }}"
                                @checked(old('relance_de') === (string) $x->ID) onchange="relance(this)"> {!! __("C'est une relance de la demande n° :id (le CTS a livré :recues/:demandees)", ['id' => e($x->ID), 'recues' => e($x->recues), 'demandees' => e($x->demandees)]) !!}</label>
                        @endforeach
                        <label style="display:block;"><input type="radio" name="relance_de" value="" @checked(! old('relance_de')) onchange="relance(this)"> {!! __("Non, ce n'est pas une relance") !!}</label>
                    </div>
                @endif
                @php($aRegulariser = $demandeDmi && ! empty($demandeDmi->recue_pharmacie))
                @if($aRegulariser)
                    {{-- Réceptionnée en pharmacie sans inscription : inscription a posteriori, motif obligatoire. --}}
                    <div class="alerte rouge">
                        <strong>{{ __('Régularisation : cette demande DMI est déjà réceptionnée en pharmacie, sans inscription au registre.') }}</strong>
                        <div class="petit" style="margin:4px 0 6px;">{!! __("Les étapes du service, du coursier et du CTS (A3 à C4) resteront « non tracées » : aucune heure n'est inventée.") !!}
                            {{ __('La réception pharmacie (C5) est reprise de Clinisys, puis le circuit continue normalement.') }}</div>
                        <input name="regularisation" required maxlength="255" placeholder="{{ __('Motif (obligatoire), ex. urgence vitale de nuit, demande portée directement au CTS') }}" value="{{ old('regularisation') }}" style="width:100%;">
                        @error('regularisation')<div class="erreur">{{ $message }}</div>@enderror
                    </div>
                @elseif($a2['totalAutres'] > 0)
                    <div class="alerte rouge">
                        <label><input type="checkbox" name="forcer" value="1" @checked(old('forcer'))> {!! __("J'ai vérifié : c'est bien une nouvelle demande") !!}</label>
                        <input name="motif_forcage" placeholder="{{ __('Motif (obligatoire), ex. besoin de PFC en plus des CGR') }}" value="{{ old('motif_forcage') }}" style="width:100%; margin-top:6px;">
                    </div>
                @endif
                <script>
                    // Relance choisie : la vérification est cochée et le motif rempli (modifiables).
                    function relance(r) {
                        const forcer = document.querySelector('input[name=forcer]'), motif = document.querySelector('input[name=motif_forcage]');
                        if (! forcer) return;
                        forcer.checked = !! r.value;
                        if (r.value) { motif.value = r.dataset.motif; } else if (motif.value.startsWith(@json(__('Relance de la demande')))) { motif.value = ''; }
                    }
                </script>
                <div class="champ"><label>{{ __('Commentaire') }}</label><textarea name="commentaire" rows="2" style="width:100%;">{{ old('commentaire') }}</textarea></div>
                <button class="btn large" type="submit">{{ $aRegulariser ? __('Inscrire (régularisation)') : __('Inscrire au registre') }}</button>
            </form>
        @endif
    @endif
@endif
@endsection
