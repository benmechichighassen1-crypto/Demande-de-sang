{{-- Pagination simple (sans total) : Précédent / Suivant, même style que partials.pagination. --}}
@if($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="{{ __('Navigation entre les pages') }}" style="display:flex; gap:8px; justify-content:center; margin-top:12px;">
        @if($paginator->onFirstPage())
            <span class="btn sec" style="opacity:.5;" aria-disabled="true">{{ __('pagination.previous') }}</span>
        @else
            <a class="btn sec" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('pagination.previous') }}</a>
        @endif
        @if($paginator->hasMorePages())
            <a class="btn sec" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('pagination.next') }}</a>
        @else
            <span class="btn sec" style="opacity:.5;" aria-disabled="true">{{ __('pagination.next') }}</span>
        @endif
    </nav>
@endif
