{{-- Formulaire d'une étape. Variables : $d (demande), $code, $retour (null ou 'accueil'), $ref. --}}
@php($def = config("demandesang.etapes.$code"))
<form method="post" action="{{ route('demandes.etape', [$d, $code]) }}" class="carte" id="etape-{{ $code }}" style="border-left:4px solid var(--rouge);">
    @csrf
    @if($retour)<input type="hidden" name="retour" value="{{ $retour }}">@endif
    <h3>{{ $code }} · {{ __($def['libelle']) }}</h3>

    @foreach($def['champs'] as $champ)
        @switch($champ)
            @case('paiement')
                @include('demandes.partials.paiement', ['requis' => true, 'attr' => ''])
                @break
            @case('identite_ok')
                <div class="champ"><label><input type="checkbox" name="identite_ok" value="1" required> {!! __("J'ai vérifié l'identité du patient (étiquette)") !!}</label></div>
                @break
            @case('carte_groupage')
                <div class="champ"><label>{{ __('Le patient a-t-il une carte de groupage ?') }}</label>
                    <div class="choix">
                        <label><input type="radio" name="carte_groupage" value="oui" required> {{ __('Oui : 1 tube') }}</label>
                        <label><input type="radio" name="carte_groupage" value="non"> {{ __('Non : 2 tubes') }}</label>
                    </div></div>
                @break
            @case('pieces')
                <div class="champ"><label>{{ __('Pièces du dossier') }}</label>
                    <div class="choix" style="flex-direction:column; align-items:stretch;">
                        <label><input type="checkbox" name="pieces[demande]" value="1" required> {{ __('Demande papier + fiche Clinisys') }}</label>
                        <label><input type="checkbox" name="pieces[analyses]" value="1"> {{ __('Analyses précédentes') }}</label>
                    </div></div>
                @break
            @case('heure')
                @include('demandes.partials.telephones', ['quoi' => $code === 'A9' ? ['coursier'] : ['surveillant', 'major']])
                <div class="champ"><label>{{ __('Heure (si différente de maintenant)') }}</label><input type="time" name="heure" style="max-width:160px"></div>
                @break
            @case('services')
                <div class="champ"><label>{!! __("Service source (d'où vient le patient)") !!}</label>
                    <select name="service_source" required><option value="">—</option>
                        {{-- Par défaut : le service de soin du patient lu dans Clinisys, sinon celui de la personne connectée. --}}
                        @php($sourceParDefaut = $d->service_source ?? (array_search(mb_strtoupper(trim((string) $d->service_saisie_dmi)), array_map('mb_strtoupper', $ref->services()), true) ?: auth()->user()->premierService()))
                        @foreach($ref->services() as $c => $l)<option value="{{ $c }}" @selected(old('service_source', $sourceParDefaut) === $c)>{{ $l }}</option>@endforeach
                    </select></div>
                <div class="champ"><label>{{ __('Service de destination (où il sera transfusé)') }}</label>
                    <select name="service_destination" required><option value="">—</option>
                        @foreach($ref->services() as $c => $l)<option value="{{ $c }}" @selected(old('service_destination', $d->service_destination) === $c)>{{ $l }}</option>@endforeach
                    </select></div>
                @if($d->service_saisie_dmi)<p class="doux petit">{{ __('Service de soin du patient (Clinisys) :') }} {{ $d->service_saisie_dmi }}</p>@endif
                @break
            @case('etat_cts')
                <div class="choix champ">
                    <label><input type="radio" name="etat_cts" value="pretes" required @checked(old('etat_cts', $d->nature === 'instance' ? 'instance' : 'pretes') === 'pretes')> {{ __('Poches prêtes, je les ramène') }}</label>
                    <label><input type="radio" name="etat_cts" value="instance" @checked(old('etat_cts', $d->nature === 'instance' ? 'instance' : 'pretes') === 'instance')> {{ __('Poches gardées en instance au CTS') }}</label>
                </div>
                <div class="champ"><label>{{ __('Combien de poches le CTS vous a-t-il données ?') }}</label>
                    <input type="number" name="nb_pretes" min="1" max="{{ max($d->nb_poches, 1) }}" value="{{ old('nb_pretes', $d->nb_poches) }}" style="max-width:100px;"> {{ __('sur :n demandée(s)', ['n' => $d->nb_poches]) }}
                    <div class="doux petit">{{ __('Moins que demandé ? Indiquez le nombre réel : la demande sera marquée « servie en partie ».') }}</div></div>
                @break
            @case('conformite')
                <div class="champ"><label>{{ __('Poches conformes à la demande (identité, produit, nombre) ?') }}</label>
                    <div class="choix">
                        <label><input type="radio" name="conforme" value="oui" required> {{ __('Conforme') }}</label>
                        <label><input type="radio" name="conforme" value="non"> {{ __('Non conforme') }}</label>
                    </div></div>
                <div class="champ"><label>{{ __('Motif si non conforme') }}</label><input name="motif_non_conformite" value="{{ old('motif_non_conformite') }}"></div>
                @break
            @case('poches')
                <div class="champ"><label>{{ __('Poches reçues (:n demandée(s))', ['n' => $d->nb_poches]) }}</label>
                    @for($i = 0; $i < max($d->nb_poches, 1); $i++)
                        <div class="ligne" style="margin-bottom:6px;">
                            <input name="poches[{{ $i }}][numero]" placeholder="{{ __('N° de poche (facultatif)') }}" style="flex:2; min-width:140px;" value="{{ old("poches.$i.numero") }}">
                            <select name="poches[{{ $i }}][produit]" style="flex:1;">
                                @foreach(config('demandesang.produits') as $c => $l)<option value="{{ $c }}" @selected($c === $d->produit)>{{ $c }}</option>@endforeach
                            </select>
                            <select name="poches[{{ $i }}][groupe_sanguin]" style="flex:1;"><option value="">{{ __('Groupe') }}</option>
                                @foreach(config('demandesang.groupes') as $g)<option @selected($g === $d->groupe_sanguin)>{{ $g }}</option>@endforeach
                            </select>
                            <input type="date" name="poches[{{ $i }}][peremption]" title="{{ __('Date de péremption') }}" style="flex:1;">
                        </div>
                    @endfor
                    <p class="doux petit">{{ __('N°, groupe et péremption sont facultatifs : sans n°, les poches sont reprises du bon de dépôt Clinisys à la réception en pharmacie (C5). Une poche en moins ? Indiquez-le en commentaire (non conforme).') }}</p>
                </div>
                @break
            @case('bon_depot')
                @php($bonsBd = isset($clinisys) && is_array($clinisys) ? $clinisys['bons'] : [])
                @if($bonsBd !== [])
                    <div class="alerte">{{ __('Bon(s) de dépôt trouvé(s) dans Clinisys pour la demande DMI n° :num :', ['num' => $d->num_demande_clinisys]) }}
                        @foreach($bonsBd as $b)
                            <div><strong>{{ $b->numaffiche }}</strong> {{ __('du') }} {{ \Illuminate\Support\Carbon::parse($b->datbon)->format('d/m/Y') }}
                                @foreach($b->poches as $p)<br>· {{ $p->Identifiant }} {{ $p->desart }}@if($p->DatPer) ({{ __('périme le :date', ['date' => \Illuminate\Support\Carbon::parse($p->DatPer)->format('d/m/Y')]) }})@endif @endforeach</div>
                        @endforeach
                        @php($ec = $clinisys['ecarts'])
                        @if($ec && $ec['manquantes'])<div class="erreur">{{ __('Dans le BD mais pas au contrôle :') }} {{ implode(', ', $ec['manquantes']) }}</div>@endif
                        @if($ec && $ec['en_trop'])<div class="erreur">{{ __('Au contrôle mais pas dans le BD :') }} {{ implode(', ', $ec['en_trop']) }}</div>@endif
                    </div>
                @endif
                <div class="champ"><label>{{ __('N° du bon de dépôt FRS (BD…)') }}</label><input name="bon_depot" placeholder="BD261548" value="{{ old('bon_depot', $bonsBd[0]->numaffiche ?? '') }}" required></div>
                @break
            @case('poches_delivrees')
                <div class="champ"><label>{{ __('Poches délivrées') }}</label>
                    <div class="choix" style="flex-direction:column; align-items:stretch;">
                        @forelse($d->poches->where('statut', 'en_stock') as $p)
                            <label><input type="checkbox" name="poches_delivrees[]" value="{{ $p->id }}" checked> {{ $p->numero }} · {{ $p->produit }} {{ $p->groupe_sanguin }}</label>
                        @empty
                            <span class="erreur">{{ __('Aucune poche en stock pour cette demande.') }}</span>
                        @endforelse
                    </div></div>
                @break
            @case('issue')
                @foreach($d->poches->where('statut', 'delivree') as $p)
                    <div class="champ"><label>{{ __('Poche') }} {{ $p->numero }} ({{ $p->produit }})</label>
                        <div class="choix">
                            <label><input type="radio" name="issue[{{ $p->id }}]" value="transfusee" required> {{ __('Transfusée') }}</label>
                            <label><input type="radio" name="issue[{{ $p->id }}]" value="non_utilisee"> {{ __('Non utilisée') }}</label>
                            <select name="motif[{{ $p->id }}]"><option value="">{{ __('Motif si non utilisée') }}</option>
                                @foreach(config('demandesang.motifs_non_utilisation') as $c => $l)<option value="{{ $c }}">{{ __($l) }}</option>@endforeach
                            </select>
                        </div></div>
                @endforeach
                @break
        @endswitch
    @endforeach

    <details @if($def['champs'] === []) open @endif><summary class="doux petit">{{ __('Commentaire') }}</summary>
        <textarea name="commentaire" rows="2" style="width:100%;">{{ old('commentaire') }}</textarea>
    </details>

    <button class="btn large" type="submit" style="margin-top:8px;">
        @if($def['type'] === 'remise')
            {{ __('Je remets (:receveurs confirmera)', ['receveurs' => implode(' / ', array_map(fn ($r) => mb_strtolower(__(config("demandesang.roles.$r"))), $def['receveur']))]) }}
        @else
            {{ __('Valider :') }} {{ __($def['court']) }}
        @endif
    </button>
</form>
