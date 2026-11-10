@extends('layouts.app')
@section('titre', 'Registre')
@push('tete')<style>@media print { @page { size: A4 landscape; margin: 10mm; } table.petit { font-size: 10px; } }</style>@endpush
@push('tete')<meta name="suivi-empreinte" data-url="{{ route('suivi.empreinte') }}" content="{{ \App\Support\Clinisys::essayer(fn () => app(\App\Services\EtatEcran::class)->empreinte(), '') }}">@endpush
@section('contenu')
<div class="ligne espace">
    <h1>{{ __('Registre des demandes de sang') }}</h1>
    <button class="btn gris non-imprime" onclick="window.print()">{{ __('Imprimer') }}</button>
</div>
<p class="doux petit">{{ __('Remplace le registre papier PQ-ORG-017-002. Période du :du au :au.', ['du' => \Illuminate\Support\Carbon::parse($f['du'])->format('d/m/Y'), 'au' => \Illuminate\Support\Carbon::parse($f['au'])->format('d/m/Y')]) }}
    {{ __('Remises de main à main : « Remis » = heure et personne qui remet, « Reçu » = heure et personne qui confirme.') }}</p>
@include('suivi._filtres')
{{-- Cellule d'une remise de main à main : remis par / reçu par, ou en attente / refusée. --}}
@php($remise = function ($r) {
    if (! $r) {
        return '';
    }
    $l = '<div>'.e(__('Remis')).' '.e($r->remis_le->format('d/m H:i')).'<br><span class="doux">'.e($r->nom_donneur).'</span></div>';
    return $l.match ($r->reponse) {
        'confirmee' => '<div style="margin-top:3px;">'.e(__('Reçu')).' '.e($r->repondu_le->format('d/m H:i')).'<br><span class="doux">'.e($r->nom_receveur).'</span></div>',
        'refusee' => '<div style="color:#b91c1c; margin-top:3px;">'.e(__('Refusée :date par :nom', ['date' => $r->repondu_le?->format('d/m H:i'), 'nom' => $r->nom_receveur])).'</div>',
        default => '<div style="color:#c2410c; margin-top:3px;">'.e(__('En attente de confirmation')).'</div>',
    };
})
<div class="carte table-defile">
    <table class="petit">
        <tr><th>{{ __('Date / heure') }}</th><th>{{ __('Patient') }}</th><th>{{ __('Dossier') }}</th><th>{{ __('Service') }}</th><th>{{ __('Produit') }}</th><th>{{ __('Poches au CTS') }}</th><th>{{ __('Inscrite par') }}</th>
            <th>{{ __('Coursier (A11)') }}</th><th>{{ __('Dépôt CTS') }}</th><th>{{ __('Retour clinique') }}</th><th>{{ __('Surveillant (C2)') }}</th><th>{{ __('Pharmacie (C4 · C5)') }}</th><th>{{ __('Service (C8 · C9)') }}</th><th>{{ __('Statut') }}</th></tr>
        @forelse($demandes as $d)
            @php($h = $heures[$d->id])
            @php($r = $remises[$d->id])
            @php($fmt = fn ($k) => isset($h[$k]) ? $h[$k]->format('d/m H:i') : '')
            <tr>
                <td>{{ $d->cree_le->format('d/m/Y H:i') }}</td>
                <td><a class="loupe" href="{{ route('demandes.show', $d) }}">{{ $d->nom_patient }}</a>@if($d->libelleCas())<div class="doux">{{ $d->libelleCas() }} @if($paiement = $d->paiement())· {{ $paiement }}@endif</div>@endif</td>
                <td>{{ $d->num_doss }}@if($d->cin)<div class="doux">{{ __('CIN') }} {{ $d->cin }}</div>@endif</td>
                <td>{{ $ref->service($d->service_source) }} → {{ $ref->service($d->service_destination) }}</td>
                <td>{{ $d->nb_poches }} {{ $d->produit }}<div class="doux">{{ __('reçues :ratio', ['ratio' => $d->ratioPoches()]) }}</div>
                    @if($d->num_demande_clinisys)
                        @include('partials.lieux_poches', ['lieux' => $lieux[(string) $d->num_demande_clinisys] ?? [], 'demandees' => $d->enCours() ? $d->nb_poches : 0, 'compact' => true])
                    @endif
                    @if($d->demande_relancee_id)<div class="doux">{{ __('relance de n° :n', ['n' => $d->demande_relancee_id]) }}</div>@endif</td>
                <td>{{ $d->nature === 'instance' ? __('En instance') : __('À ramener') }}</td>
                <td>{{ $d->cree_par }}</td>
                <td>{!! $remise($r['A11'] ?? null) !!}</td>
                <td>{{ $fmt('B1') }}</td>
                <td>{{ $fmt('C1') }}</td>
                <td>{!! $remise($r['C2'] ?? null) !!}</td>
                <td>{!! $remise($r['C4'] ?? null) !!}@if($fmt('C5'))<div style="margin-top:3px;">{{ __('Réception') }} {{ $fmt('C5') }}</div>@endif</td>
                <td>{!! $remise($r['C8'] ?? null) !!}@if($fmt('C9'))<div style="margin-top:3px;">{{ __('Accusé') }} {{ $fmt('C9') }}</div>@endif</td>
                <td>{{ $d->libelleEtape() }}@if($d->serviePartiellement())<div style="color:#c2410c;">{{ __('servie partiellement :ratio', ['ratio' => $d->ratioPoches()]) }}</div>@endif</td>
            </tr>
        @empty
            <tr><td colspan="14" class="doux">{{ __('Aucune demande sur la période.') }}</td></tr>
        @endforelse
    </table>
</div>
@endsection
