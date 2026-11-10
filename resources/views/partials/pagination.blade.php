{{-- Pagination de l'appli (remplace la vue Tailwind de Laravel) : Précédent / numéros / Suivant, compacte, RTL. --}}
@if($paginator->hasPages())
    @once
        <style>
            .pagination { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:8px; margin-top:12px; font-size:14px; }
            .pagination .pages { display:flex; flex-wrap:wrap; gap:4px; list-style:none; margin:0; padding:0; }
            .pagination .pages > li > a, .pagination .pages > li > span { display:inline-block; min-width:34px; padding:6px 10px; border:1px solid var(--bord); border-radius:8px;
                background:#fff; color:var(--rouge); text-align:center; text-decoration:none; line-height:1.2; }
            .pagination .pages a:hover { background:#fef2f2; }
            .pagination .pages .courante { background:var(--rouge); border-color:var(--rouge); color:#fff; font-weight:700; }
            .pagination .pages .inactif { color:#a8a29e; background:#fafaf9; }
            .pagination .pages .points { border:none; background:none; color:var(--doux); min-width:0; padding:6px 2px; }
            .pagination .fleche { display:inline-block; } [dir=rtl] .pagination .fleche { transform:scaleX(-1); }
            @media (max-width:640px) { .pagination { justify-content:center; } .pagination .pages .num:not(.courante), .pagination .pages .points { display:none; } }
            @media print { .pagination { display:none; } }
        </style>
    @endonce
    <nav class="pagination" role="navigation" aria-label="{{ __('Navigation entre les pages') }}">
        <span class="doux petit">{{ __('Affichage de :debut à :fin sur :total', ['debut' => $paginator->firstItem(), 'fin' => $paginator->lastItem(), 'total' => $paginator->total()]) }}</span>
        <ul class="pages">
            @if($paginator->onFirstPage())
                <li><span class="inactif" aria-disabled="true"><span class="fleche">&lsaquo;</span> {{ __('pagination.previous') }}</span></li>
            @else
                <li><a href="{{ $paginator->previousPageUrl() }}" rel="prev"><span class="fleche">&lsaquo;</span> {{ __('pagination.previous') }}</a></li>
            @endif
            @foreach($elements as $element)
                @if(is_string($element))
                    <li><span class="points">{{ $element }}</span></li>
                @endif
                @if(is_array($element))
                    @foreach($element as $page => $url)
                        @if($page == $paginator->currentPage())
                            <li><span class="num courante" aria-current="page">{{ $page }}</span></li>
                        @else
                            <li><a class="num" href="{{ $url }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach
            @if($paginator->hasMorePages())
                <li><a href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('pagination.next') }} <span class="fleche">&rsaquo;</span></a></li>
            @else
                <li><span class="inactif" aria-disabled="true">{{ __('pagination.next') }} <span class="fleche">&rsaquo;</span></span></li>
            @endif
        </ul>
    </nav>
@endif
