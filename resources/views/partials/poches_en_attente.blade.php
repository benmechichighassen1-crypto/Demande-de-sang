{{-- Poches déjà en pharmacie pour un patient qui a une autre demande en cours (App\Services\PochesEnAttente). --}}
@if(($pochesEnAttente ?? []) !== [])
    <div class="alerte" style="border-color:#f97316; background:#fff7ed;">
        <strong>⚠ {{ __(':n poche(s) attendent déjà en pharmacie pour un patient qui a une nouvelle demande en cours', ['n' => count($pochesEnAttente)]) }}</strong>
        <ul style="margin:6px 0 0;">
            @foreach($pochesEnAttente as $x)
                @php($p = $x['poche'])
                <li>{{ $p->numDemande ? __('Poche de la demande n° :dmi', ['dmi' => $p->numDemande]) : __('Poche') }}
                    <a class="loupe" href="{{ route('stock', ['q' => trim((string) $p->Identifiant), 'vue' => 'bs_stock']) }}">{{ $p->Identifiant }}</a>
                    ({{ $p->desart }} {{ $p->memop }}@if($p->DatPer), {{ __('périme le :date', ['date' => \Illuminate\Support\Carbon::parse($p->DatPer)->format('d/m')]) }}@endif)
                    · {{ __('dossier :n', ['n' => $x['demande']->num_doss]) }} ·
                    <a class="loupe" href="{{ route('demandes.show', $x['demande']) }}">{{ __('nouvelle demande :id', ['id' => $x['demande']->id]) }}</a>
                    · <strong>{{ __('À utiliser avant de redemander.') }}</strong></li>
            @endforeach
        </ul>
    </div>
@endif
