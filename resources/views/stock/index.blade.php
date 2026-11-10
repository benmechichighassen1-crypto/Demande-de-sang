@extends('layouts.app')
@section('titre', __('Stock banque de sang'))
@section('contenu')
<h1>{{ __('Stock banque de sang') }}</h1>
@php($clinisysVue = array_key_exists($vue, \App\Http\Controllers\StockController::VUES_CLINISYS))
{{-- N° cherché, normalisé : la ligne de cette poche est surlignée avec son lieu. --}}
@php($q = \App\Clinisys\Pharmacie::normaliser((string) ($f['q'] ?? '')))
@php($tuile = fn (bool $actif) => 'text-decoration:none; color:inherit; '.($actif ? 'background:var(--rouge); color:#fff; border-color:var(--rouge); box-shadow:0 4px 0 -1px #450a0a;' : ''))
{{-- Pastilles et filtres restent visibles en défilant (réduits en une ligne compacte). --}}
<div class="collant">
@if($listesBs !== [])
    <p class="doux petit masque-reduit" style="margin-top:-6px;">Clinisys · {{ __('dépôt :depot', ['depot' => $depot]) }} · {{ __('lecture seule') }}</p>
    {{-- Pastilles Clinisys : un clic affiche la liste correspondante (le nombre = les lignes de la liste). --}}
    <div class="cartes" style="grid-template-columns:repeat(auto-fill, minmax(160px, 1fr));">
        @foreach(\App\Http\Controllers\StockController::VUES_CLINISYS as $c => $l)
            @php($actif = $vue === $c)
            <a class="carte" href="{{ request()->fullUrlWithQuery(['vue' => $c]) }}" style="{{ $tuile($actif) }} {{ ! $actif && $c === 'bs_perimees' && $listesBs[$c]->isNotEmpty() ? 'background:#fef2f2;' : '' }}{{ ! $actif && $c === 'bs_bientot' && $listesBs[$c]->isNotEmpty() ? 'background:#fff7ed;' : '' }}">
                <div style="font-size:26px; font-weight:700;">{{ $listesBs[$c]->count() }}</div>
                <div class="petit" style="{{ $actif ? '' : 'color:var(--doux);' }}">{{ $actif ? '● ' : '' }}{{ __($l, ['x' => $jours]) }}</div>
                <div class="petit" style="opacity:.75;">{{ in_array($c, ['bs_stock', 'bs_perimees', 'bs_bientot'], true) ? __('à ce jour') : ($historique ? __('tout l\'historique') : __('du :du au :au', ['du' => $periode[0]->format('d/m'), 'au' => $periode[1]->format('d/m')])) }}</div>
            </a>
        @endforeach
    </div>
    <h2 class="masque-reduit">{!! __("Suivi dans l'appli") !!}</h2>
@elseif($erreurClinisys)
    <div class="alerte rouge">{{ $erreurClinisys }}</div>
@endif
@php($libelles = ['en_stock' => 'En stock', 'consommees' => 'Consommées (sortie patient)', 'retournees' => 'Retournées / non consommées', 'attente' => 'Arrivées, non reçues en pharmacie'])
<div class="cartes" style="grid-template-columns:repeat(auto-fill, minmax(160px, 1fr));">
    @foreach($libelles as $c => $l)
        <a class="carte" href="{{ request()->fullUrlWithQuery(['vue' => $c]) }}" style="{{ $tuile($vue === $c) }}">
            <div style="font-size:26px; font-weight:700;">{{ $compteurs[$c] }}</div><div class="petit" style="{{ $vue === $c ? '' : 'color:var(--doux);' }}">{{ $vue === $c ? '● ' : '' }}{{ __($l) }}</div>
        </a>
    @endforeach
</div>

{{-- Filtres : la période (30 derniers jours par défaut) sert aux mouvements Clinisys et au suivi de l'appli ;
     service et motif n'existent que dans l'appli, groupe et produit valent pour les deux. --}}
<form class="carte filtres" method="get">
    <input type="hidden" name="vue" value="{{ $vue }}">
    {{-- Recherche sur toutes les listes : n° poche, dossier, patient, n° demande, n° de bon. --}}
    @include('partials.champ_recherche', ['placeholder' => __('N° poche, n° dossier, nom patient, n° demande, n° bon…')])
    @if($vue === 'bs_bientot')
        <label class="petit">{{ __('Périment dans') }} <input type="number" name="jours" min="1" max="90" value="{{ $jours }}" style="width:80px;"> {{ __('jours') }}</label>
    @endif
    @unless(in_array($vue, ['bs_stock', 'bs_perimees', 'bs_bientot'], true))
        <label class="petit">{{ __('Du') }} <input type="date" name="du" value="{{ $f['du'] ?? $periode[0]->format('Y-m-d') }}"></label>
        <label class="petit">{{ __('au') }} <input type="date" name="au" value="{{ $f['au'] ?? $periode[1]->format('Y-m-d') }}"></label>
    @endunless
    @unless($clinisysVue)
        <select name="service_source"><option value="">{{ __('Service source') }}</option>@foreach($ref->services() as $c => $l)<option value="{{ $c }}" @selected(($f['service_source'] ?? '') === $c)>{{ $l }}</option>@endforeach</select>
        <select name="service_destination"><option value="">{{ __('Service destination') }}</option>@foreach($ref->services() as $c => $l)<option value="{{ $c }}" @selected(($f['service_destination'] ?? '') === $c)>{{ $l }}</option>@endforeach</select>
    @endunless
    <select name="groupe"><option value="">{{ __('Groupe') }}</option>@foreach(config('demandesang.groupes') as $g)<option @selected(($f['groupe'] ?? '') === $g)>{{ $g }}</option>@endforeach</select>
    <select name="produit"><option value="">{{ __('Produit') }}</option>@foreach(config('demandesang.produits') as $c => $l)<option value="{{ $c }}" @selected(($f['produit'] ?? '') === $c)>{{ $c }}</option>@endforeach</select>
    @unless($clinisysVue)
        <select name="motif"><option value="">{{ __('Motif') }}</option>@foreach(config('demandesang.motifs_non_utilisation') as $c => $l)<option value="{{ $c }}" @selected(($f['motif'] ?? '') === $c)>{{ __($l) }}</option>@endforeach</select>
    @endunless
    <button class="btn sec" type="submit">{{ __('Filtrer') }}</button>
    @include('partials.effacer_filtres', ['garder' => ['vue']])
</form>
</div>
@php($nbListe = $clinisysVue ? $listesBs[$vue]->count() : count($poches))
@include('partials.filtres_actifs', ['garder' => ['vue'], 'resultat' => __(':n poche(s)', ['n' => $nbListe]), 'actifs' => [
    'du' => __('Du :date', ['date' => (! empty($f['du']) ? \Illuminate\Support\Carbon::parse($f['du'])->format('d/m/Y') : '')]),
    'au' => __('Au :date', ['date' => (! empty($f['au']) ? \Illuminate\Support\Carbon::parse($f['au'])->format('d/m/Y') : '')]),
    'service_source' => __('Source : :v', ['v' => ($ref->services()[$f['service_source'] ?? ''] ?? '')]),
    'service_destination' => __('Destination : :v', ['v' => ($ref->services()[$f['service_destination'] ?? ''] ?? '')]),
    'groupe' => __('Groupe : :v', ['v' => ($f['groupe'] ?? '')]),
    'produit' => __('Produit : :v', ['v' => ($f['produit'] ?? '')]),
    'motif' => __('Motif : :v', ['v' => __(config('demandesang.motifs_non_utilisation.'.($f['motif'] ?? ''), '') ?? '')]),
    'q' => __('Recherche : :v', ['v' => ($f['q'] ?? '')]),
]])

@if(! empty($f['q']))
    {{-- Où se trouve ce qu'on cherche : chaque liste avec son nombre de lignes, en lien. --}}
    @php($partout = collect(\App\Http\Controllers\StockController::VUES_CLINISYS)->filter(fn ($l, $c) => isset($listesBs[$c]))->map(fn ($l, $c) => ['libelle' => __($l, ['x' => $jours]), 'n' => $listesBs[$c]->count()])
        ->merge(collect($libelles)->map(fn ($l, $c) => ['libelle' => __("Suivi dans l'appli").' · '.__($l), 'n' => $compteurs[$c]])->filter(fn ($x, $c) => $x['n'] > 0 || $c === $vue)))
    <p class="petit" style="margin:4px 0 8px;"><strong>{{ __('« :q » trouvé dans :', ['q' => $f['q']]) }}</strong>
        @foreach($partout as $c => $x)
            @if($c === $vue)<strong>{{ $x['libelle'] }} ({{ $x['n'] }})</strong>@else<a class="loupe" href="{{ request()->fullUrlWithQuery(['vue' => $c]) }}" style="{{ $x['n'] ? 'font-weight:600;' : 'color:var(--doux);' }}">{{ $x['libelle'] }} ({{ $x['n'] }})</a>@endif{{ $loop->last ? '' : ' · ' }}
        @endforeach
        @if($historique)<span class="doux"> — {{ __('réceptions, sorties et prélèvements : tout l\'historique pour ce numéro') }}</span>@endif
    </p>
@endif

{{-- Quelle liste est affichée. --}}
<h2 style="margin-top:6px;">{{ $clinisysVue ? 'Clinisys · '.__(\App\Http\Controllers\StockController::VUES_CLINISYS[$vue], ['x' => $jours]) : __("Suivi dans l'appli").' · '.__($libelles[$vue]) }} ({{ $nbListe }})</h2>

@if(in_array($vue, ['bs_bd', 'bs_pa', 'bs_pr'], true))
    {{-- Mouvements Clinisys de la période : une ligne par poche. --}}
    <div class="carte table-defile">
        <table class="petit">
            <thead><tr><th>{{ __('N° poche') }}</th><th>{{ __('Produit') }}</th><th>{{ __('Groupe') }}</th><th>{{ __('Péremption') }}</th><th>{{ __('Date') }}</th><th>{{ __('N° de bon') }}</th><th>{{ __('Patient') }}</th><th>{{ __('Service') }}</th><th>{{ __('Demande DMI') }}</th></tr></thead>
            <tbody>
            @forelse($listesBs[$vue] as $l)
                @php($cherchee = $q !== '' && \App\Clinisys\Pharmacie::normaliser((string) $l->numero) === $q)
                <tr @if($cherchee) style="background:#fef9c3" @endif>
                    <td>{{ $l->numero }}@if($cherchee)<div class="petit" style="font-weight:600;">{{ ['bs_bd' => '📥 '.__('Reçue en pharmacie le :date', ['date' => \App\Clinisys\Pharmacie::horodatage($l)->format('d/m')]), 'bs_pa' => '✅ '.__('Délivrée au service le :date (:bon:service)', ['date' => \App\Clinisys\Pharmacie::horodatage($l)->format('d/m'), 'bon' => $l->bon, 'service' => $l->service ? ', '.$l->service : '']), 'bs_pr' => '↩ '.__('Retournée au fournisseur le :date (:bon)', ['date' => \App\Clinisys\Pharmacie::horodatage($l)->format('d/m'), 'bon' => $l->bon])][$vue] }}</div>@endif</td><td>{{ $l->desart }}@if(($l->quantite + 0) != 1) <span class="doux">× {{ $l->quantite + 0 }}</span>@endif</td>
                    <td>{{ strtoupper(trim((string) $l->memop)) }}</td>
                    <td>{{ $l->DatPer ? \Illuminate\Support\Carbon::parse($l->DatPer)->format('d/m/Y') : '' }}</td>
                    <td style="white-space:nowrap;">{{ \App\Clinisys\Pharmacie::horodatage($l)->format('d/m/Y H:i') }}</td>
                    <td>{{ $l->bon }}@if($l->bon_origine)<div class="doux">{{ __('BD d\'origine : :bon', ['bon' => $l->bon_origine]) }}</div>@endif</td>
                    <td>@if($l->NumDoss){{ trim(($l->NomCli ?? '').' '.($l->Prenom ?? '')) }}<div class="doux">{{ $l->NumDoss }}</div>@else<span class="doux">{{ __('non affectée') }}</span>@endif</td>
                    <td>{{ $l->service ?? '' }}</td>
                    <td>{{ $l->numDemande ?: '' }}@if($l->demande_appli ?? null) · <a class="loupe" href="{{ route('demandes.show', $l->demande_appli) }}">{{ __('fiche') }}</a>@elseif(! empty($l->hors_registre)) · <span style="color:#b91c1c; font-weight:600;">{{ __('hors registre') }}</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="9" class="doux">{{ __('Aucune poche.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endif

@unless($clinisysVue)
<div class="carte table-defile">
    <table>
        <thead><tr><th>{{ __('N° poche') }}</th><th>{{ __('Produit') }}</th><th>{{ __('Groupe') }}</th><th>{{ __('Péremption') }}</th><th>{{ __('Patient') }}</th><th class="masque-mobile">{{ __('Services') }}</th><th>{{ __('État') }}</th><th></th></tr></thead>
        <tbody>
        @forelse($poches as $p)
            @php($proche = $p->peremption && $p->statut === 'en_stock' && $p->peremption->lte(now()->addDays((int) $ref->parametre('peremption_jours', config('demandesang.peremption_jours')))))
            <tr @if($proche) style="background:#fef2f2" @endif>
                <td>{{ $p->numero }}<div class="doux petit">{{ $p->bon_depot }}</div></td><td>{{ $p->produit }}</td><td>{{ $p->groupe_sanguin }}</td>
                <td>{{ $p->peremption?->format('d/m/Y') }}</td>
                <td><a class="loupe" href="{{ route('demandes.show', $p->demande_id) }}">{{ $p->nom_patient }}</a><div class="doux petit">{{ $p->num_doss }}</div></td>
                <td class="masque-mobile">{{ $ref->service($p->service_source) }} → {{ $ref->service($p->service_destination) }}</td>
                <td>{{ __(\App\Models\Poche::STATUTS[$p->statut]) }}@if($p->motif)<div class="doux petit">{{ __(config("demandesang.motifs_non_utilisation.{$p->motif}") ?? '') }} {{ $p->bon_prelevement }}</div>@endif
                    <div class="doux petit">{{ $p->statut_le?->format('d/m H:i') }}</div></td>
                <td>
                    @if(in_array($p->statut, ['en_stock', 'a_retourner'], true) && auth()->user()->a('pharmacie') && ! config('demandesang.clinisys_actif'))
                        <details><summary class="petit">{{ __('Prélèvement fournisseur') }}</summary>
                            <form method="post" action="{{ route('poches.prelever', $p) }}">@csrf
                                <select name="motif" required style="width:100%; margin-bottom:4px;"><option value="">{{ __('Motif') }}</option>
                                    @foreach(config('demandesang.motifs_non_utilisation') as $c => $l)<option value="{{ $c }}" @selected($p->motif === $c)>{{ __($l) }}</option>@endforeach</select>
                                <input name="bon_prelevement" placeholder="{{ __('Bon PR…') }}" style="width:100%; margin-bottom:4px;">
                                <input name="commentaire" placeholder="{{ __('Commentaire') }}" style="width:100%; margin-bottom:4px;">
                                <button class="btn petit" type="submit">{{ __('Enregistrer') }}</button>
                            </form>
                        </details>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="doux">{{ __('Aucune poche.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    <p class="doux petit">{!! __("Stock = Réceptions − Sorties patient − Prélèvements fournisseur. Ces poches viennent des saisies de l'appli (contrôle de conformité et réception en pharmacie).") !!}</p>
</div>
@endunless

@if($stockClinisys !== null && in_array($vue, ['bs_stock', 'bs_perimees', 'bs_bientot'], true))
    {{-- État des articles en dépôt fournisseur (comme l'état Clinisys), enrichi du dossier et du patient. --}}
    @php($valides = collect($stockClinisys)->where('perimee', 0)->values())
    @php($perimees = collect($stockClinisys)->where('perimee', 1)->values())
    @php($total = count($stockClinisys))
    @php($compteur = $mouvements ? (int) $mouvements->en_stock + (int) $mouvements->perimees : null)
    <div class="carte table-defile" id="etat-bs">
        <div class="ligne espace">
            <h2 style="margin:0;">{{ __('État du dépôt :depot (Clinisys, enrichi)', ['depot' => $depot]) }}</h2>
            <button class="btn gris non-imprime" type="button" onclick="document.querySelectorAll('#etat-bs details').forEach(d => d.open = true); document.body.classList.add('imprime-etat'); window.print(); document.body.classList.remove('imprime-etat');">{!! __("Imprimer l'état") !!}</button>
        </div>
        {{-- Contrôle et totaux du dépôt : repliés pendant une recherche (la liste trouvée passe en premier), toujours imprimés. --}}
        <details class="resume-etat" @if(empty($f['q'])) open @endif>
        <summary class="petit">{{ __('Contrôle et totaux du dépôt (:n poche(s))', ['n' => $total]) }}</summary>
        <p class="doux petit">{{ __('Édité le :date à :heure · même contenu que « État des articles en dépôt fournisseur » de Clinisys, avec le dossier et le patient de chaque poche.', ['date' => now()->format('d/m/Y'), 'heure' => now()->format('H:i')]) }}</p>
        @if($erreurClinisys)<div class="alerte rouge">{{ $erreurClinisys }}</div>@endif

        @if($compteur !== null && $compteur !== $total)
            <div class="alerte rouge">{{ __('Contrôle : liste :total poche(s) ≠ compteur Clinisys :compteur : écart de :ecart.', ['total' => $total, 'compteur' => $compteur, 'ecart' => abs($total - $compteur)]) }}</div>
        @else
            <div class="alerte" style="background:#dcfce7; border-color:#86efac;">✔ {!! __("Contrôle : :total poche(s) en stock = total Clinisys · :perimees périmée(s) · :valides non périmée(s) (périmée = péremption au plus tard aujourd'hui, comme Clinisys).", ['total' => e($total), 'perimees' => e($perimees->count()), 'valides' => e($valides->count())]) !!}</div>
        @endif
        @if($ecarts->isNotEmpty())
            <div class="alerte rouge">{!! __(":n poche(s) « en stock » dans l'appli mais absente(s) du stock Clinisys :", ['n' => e($ecarts->count())]) !!}
                @foreach($ecarts as $e)<a class="loupe" href="{{ route('demandes.show', $e->demande_id) }}">{{ $e->numero }}</a>@if(! $loop->last), @endif @endforeach</div>
        @endif

        <table class="petit" style="margin-bottom:10px;">
            <thead><tr><th>{{ __('Désignation') }}</th><th>{{ __('Total') }}</th><th>{{ __('Non périmées') }}</th><th>{{ __('Périmées') }}</th></tr></thead>
            @foreach(collect($stockClinisys)->groupBy(fn ($p) => $p->desart ?? $p->codart)->sortKeys() as $des => $lignes)
                <tr><td>{{ $des }}</td><td>{{ $lignes->sum('qte') + 0 }}</td><td>{{ $lignes->where('perimee', 0)->sum('qte') + 0 }}</td><td>{{ $lignes->where('perimee', 1)->sum('qte') + 0 }}</td></tr>
            @endforeach
            <tr><th>{{ __('Total') }}</th><th>{{ collect($stockClinisys)->sum('qte') + 0 }}</th><th>{{ $valides->sum('qte') + 0 }}</th><th>{{ $perimees->sum('qte') + 0 }}</th></tr>
        </table>
        </details>
        <script>
            // Sans recherche : le résumé garde l'état choisi (ouvert / replié) sur ce poste.
            (function () {
                const d = document.querySelector('#etat-bs details.resume-etat');
                if (! d || @json(! empty($f['q']))) return;
                try { const v = localStorage.getItem('stock.resumeEtat'); if (v !== null) d.open = v === '1'; } catch (e) {}
                d.addEventListener('toggle', () => { try { localStorage.setItem('stock.resumeEtat', d.open ? '1' : '0'); } catch (e) {} });
            })();
        </script>

        {{-- À l'écran : la liste de la pastille choisie ; à l'impression : l'état complet (périmés, à périmer dans X jours, non périmés). --}}
        @php($alerteJours = (int) $ref->parametre('peremption_jours', config('demandesang.peremption_jours')))
        @foreach(['bs_perimees' => 'Articles périmés', 'bs_bientot' => 'À périmer dans :x jours', 'bs_stock' => 'Articles non périmés'] as $cleListe => $titre)
            @php($liste = $listesBs[$cleListe])
            <section class="{{ $cleListe === $vue ? '' : 'imprime-seulement' }}">
            <h3 style="margin-top:12px; {{ $cleListe === 'bs_perimees' ? 'color:#b91c1c;' : ($cleListe === 'bs_bientot' ? 'color:#c2410c;' : '') }}">{{ __($titre, ['x' => $jours]) }} ({{ $liste->count() }})</h3>
            <table class="petit">
                <thead><tr><th>{{ __('Référence (n° poche)') }}</th><th>{{ __('Produit') }}</th><th>{{ __('Groupe') }}</th><th>{{ __('Péremption') }}</th><th>{{ __('Date réception') }}</th><th>{{ __('Bon dépôt') }}</th><th>{{ __('Patient') }}</th><th>{{ __('Service') }}</th><th>{{ __('Demande DMI') }}</th></tr></thead>
                <tbody>
                @forelse($liste as $p)
                    @php($per = $p->DatPer ? \Illuminate\Support\Carbon::parse($p->DatPer) : null)
                    @php($cherchee = $q !== '' && in_array($q, [\App\Clinisys\Pharmacie::normaliser((string) $p->Identifiant), \App\Clinisys\Pharmacie::normaliser((string) ($p->Reference ?? ''))], true))
                    <tr @if($cherchee) style="background:#fef9c3" @elseif($per && $per->lte(now()->addDays($alerteJours))) style="background:#fef2f2" @endif>
                        <td>{{ $p->Reference ?? $p->Identifiant }}@if($cherchee)<div class="petit" style="font-weight:600; color:#c2410c;">🏥 {{ __('En stock pharmacie depuis le :date (bon :bon)', ['date' => ! empty($p->datbon) ? \Illuminate\Support\Carbon::parse($p->datbon)->format('d/m') : '', 'bon' => $p->bon_depot]) }}</div>@endif
                        @if(($p->Reference ?? null) !== null && trim($p->Reference) !== trim((string) $p->Identifiant) && $p->Identifiant) <span class="doux">({{ __('ident. :id', ['id' => $p->Identifiant]) }})</span>@endif</td>
                        <td>{{ $p->desart }}@if(($p->qte + 0) != 1) <span class="doux">× {{ $p->qte + 0 }}</span>@endif<div class="doux">{{ $p->codart }}</div></td>
                        <td>{{ strtoupper(trim((string) ($p->memop ?? ''))) }}</td>
                        <td style="white-space:nowrap;">{{ $per?->format('d/m/Y') }}@if($cleListe === 'bs_bientot' && $per) <span class="badge" style="background:#c2410c;">J-{{ (int) today()->diffInDays($per->copy()->startOfDay()) }}</span>@endif</td>
                        <td>{{ ! empty($p->datbon) ? \Illuminate\Support\Carbon::parse($p->datbon)->format('d/m/Y') : '' }}</td>
                        <td>{{ $p->bon_depot }}</td>
                        <td>@if($p->NumDoss){{ trim($p->NomCli.' '.$p->Prenom) }}@if(($p->source_patient ?? null) === 'appli') <span class="doux">({{ __('appli') }})</span>@endif<div class="doux">{{ $p->NumDoss }}</div>@else<span class="doux">{{ __('non affectée') }}</span>@endif</td>
                        <td>{{ $p->service ?? '' }}</td>
                        <td>{{ $p->numDemande ?: '' }}@if($p->demande_appli ?? null) · <a class="non-imprime loupe" href="{{ route('demandes.show', $p->demande_appli) }}">{{ __('fiche') }}</a>@elseif(! empty($p->hors_registre)) · <span style="color:#b91c1c; font-weight:600;">{{ __('hors registre') }}</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="doux">{{ __('Aucun article.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
            </section>
        @endforeach
        <p class="doux petit">{!! __("Dossier et patient retrouvés par le panier dépôt fournisseur (même bon et même poche), sinon par la demande DMI du bon de dépôt, sinon par la poche suivie dans l'appli ; « non affectée » quand aucun lien n'existe.") !!}</p>
    </div>
    {{-- Impression de l'état seul, en A4 paysage, avec toutes ses sections. --}}
    <style>.imprime-seulement { display:none; }
        @media print { body.imprime-etat main > *:not(#etat-bs) { display:none !important; } body.imprime-etat .imprime-seulement { display:block; } @page { size: A4 landscape; margin: 10mm; } }</style>
@endif
@endsection
