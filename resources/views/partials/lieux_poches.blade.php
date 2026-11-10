{{-- Où est chaque poche d'une demande (Clinisys, lecture seule) : une ligne par poche reçue (bon BD), puis
     « pas encore arrivée » pour les poches demandées non reçues. Variables : $lieux (lignes de Pharmacie::lieuxDesPoches),
     $demandees (nombre demandé, facultatif), $compact (sans marge, pour les listes). --}}
@php($lieux = $lieux ?? [])
@php($manquantes = max(0, (int) ($demandees ?? 0) - count($lieux)))
@php($j = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m') : '')
@if($lieux !== [] || $manquantes > 0)
    <ul class="petit lieux-poches" style="list-style:none; padding:0; margin:{{ ($compact ?? false) ? '2px 0 0' : '6px 0' }};">
        @foreach($lieux as $p)
            @php($perimee = ! empty($p->DatPer) && \Illuminate\Support\Carbon::parse($p->DatPer)->startOfDay()->lte(today()))
            <li style="margin:1px 0;">
                <a class="loupe" href="{{ route('stock', ['q' => $p->numero, 'vue' => ! empty($p->pa_bon) ? 'bs_pa' : (! empty($p->pr_bon) ? 'bs_pr' : 'bs_stock')]) }}" style="font-family:Consolas, monospace;">{{ $p->numero }}</a> · {{ $p->desart }} {{ strtoupper(trim((string) ($p->memop ?? ''))) }} ·
                @if(! empty($p->pa_bon))
                    <span style="color:#15803d;">✅ {{ __('Délivrée au service le :date (:bon:service)', ['date' => $j($p->pa_date), 'bon' => $p->pa_bon, 'service' => ! empty($p->pa_service) ? ', '.$p->pa_service : '']) }}</span>
                @elseif(! empty($p->pr_bon))
                    <span style="color:#6b21a8;">↩ {{ __('Retournée au fournisseur le :date (:bon)', ['date' => $j($p->pr_date), 'bon' => $p->pr_bon]) }}</span>
                @elseif(! empty($p->en_stock))
                    <span style="color:{{ $perimee ? '#b91c1c' : '#c2410c' }}; font-weight:600;">🏥 {{ __('En stock pharmacie depuis le :date (bon :bon)', ['date' => $j($p->date_bd), 'bon' => $p->bon_bd]) }}</span>
                    @if(! empty($p->DatPer)) · {{ __($perimee ? 'périmée le :date' : 'périme le :date', ['date' => \Illuminate\Support\Carbon::parse($p->DatPer)->format('d/m/Y')]) }}@endif
                @else
                    <span class="doux">{{ __('Sortie du stock (bon :bon du :date), sans sortie patient ni retour trouvés', ['bon' => $p->bon_bd, 'date' => $j($p->date_bd)]) }}</span>
                @endif
            </li>
        @endforeach
        @for($i = 0; $i < $manquantes; $i++)
            <li class="doux" style="margin:1px 0;">⏳ {{ __('Pas encore arrivée à la pharmacie') }}</li>
        @endfor
    </ul>
@endif
