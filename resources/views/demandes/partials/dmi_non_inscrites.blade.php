{{-- Demandes DMI pas encore inscrites dans l'appli : non réceptionnées (D), ou déjà réceptionnées en pharmacie (V, « hors registre », à régulariser). Variables : $dmiNonInscrites, $dmiJours, $dmiMinutes. --}}
@if($dmiNonInscrites !== [])
    @if($replie ?? false)<details class="carte" style="border-left:4px solid #f59e0b;" @if($ouvert ?? false) open @endif><summary><strong>{{ __('Dans Clinisys, pas encore inscrites') }} ({{ count($dmiNonInscrites) }})</strong> <span class="doux petit">· {{ __('afficher') }}</span></summary>@endif
    <div class="carte table-defile" style="border-left:4px solid #f59e0b;">
        @unless($replie ?? false)<h2>{{ __('Dans Clinisys, pas encore inscrites') }} ({{ count($dmiNonInscrites) }})</h2>@endunless
        @unless(request()->routeIs('demandes.index')) @include('demandes.partials.recherche') @endunless
        <p class="doux petit">{!! __("Demandes de sang saisies dans le DMI depuis :jours jour(s), sans demande dans l'appli. En rouge : plus de :minutes min, ou déjà réceptionnée en pharmacie sans inscription (à régulariser).", ['jours' => e($dmiJours), 'minutes' => e($dmiMinutes)]) !!}</p>
        <table>
            <tr><th>{{ __('N° DMI') }}</th><th>{{ __('Saisie le') }}</th><th>{{ __('Patient') }}</th><th>{{ __('Service de soin') }}</th><th>{{ __('Produits') }}</th><th class="masque-mobile">{{ __('Diagnostic') }}</th><th></th></tr>
            @foreach($dmiNonInscrites as $x)
                @php($pr = \App\Clinisys\DemandesDmi::preremplissage($x))
                @php($depuis = \App\Services\DmiNonInscrites::depuis($x))
                <tr data-roles="{{ ! empty($x->hors_registre) ? 'infirmiere major surveillant' : 'infirmiere major' }}" @if(! empty($x->hors_registre) || $depuis->diffInMinutes(now()) > $dmiMinutes) style="background:#fef2f2" @endif>
                    <td>{{ $x->ID }}@if(! empty($x->hors_registre))<div class="petit" style="color:#b91c1c; font-weight:600;">{{ __('Réceptionnée en pharmacie sans inscription') }}</div>@endif</td>
                    <td>{{ $depuis->format('d/m H:i') }}<div class="doux petit">{{ $x->user_create }}</div></td>
                    <td>{{ trim(($x->NomCli ?? '').' '.($x->Prenom ?? '')) ?: '—' }}<div class="doux petit">{{ trim($x->NumDoss) }}</div></td>
                    <td>{{ trim((string) ($x->service ?? '')) ?: '—' }}</td>
                    <td>{{ $pr['produit'] ? __(\App\Clinisys\DemandesDmi::libelleProduits(array_filter([$pr['produit'] => $pr['nb_poches']] + $pr['autres_produits']))) : '—' }}</td>
                    <td class="masque-mobile">{{ $x->Diagnostic }}</td>
                    <td>@if(! empty($x->hors_registre) && auth()->user()->a('infirmiere', 'major', 'surveillant'))<a class="btn petit" href="{{ route('demandes.create', ['num_demande' => $x->ID]) }}">{{ __('Inscrire (régularisation)') }}</a>@elseif(empty($x->hors_registre) && auth()->user()->a('infirmiere', 'major'))<a class="btn petit" href="{{ route('demandes.create', ['num_demande' => $x->ID]) }}">{{ __('Inscrire') }}</a>@endif</td>
                </tr>
            @endforeach
        </table>
    </div>
    @if($replie ?? false)</details>@endif
@endif
