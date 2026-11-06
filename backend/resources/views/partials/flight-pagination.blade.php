@if($paginator->hasPages())
<nav class="d-flex justify-content-between align-items-center gap-3 flex-wrap" aria-label="Phân trang chuyến bay">
    <small class="subtle">Hiển thị {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} trên {{ $paginator->total() }} chuyến bay</small>
    <ul class="pagination pagination-sm mb-0">
        <li class="page-item {{ $paginator->onFirstPage() ? 'disabled' : '' }}">@if($paginator->onFirstPage())<span class="page-link" aria-disabled="true">‹</span>@else<a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Trang trước">‹</a>@endif</li>
        @foreach($elements as $element)
            @if(is_string($element))<li class="page-item disabled"><span class="page-link">{{ $element }}</span></li>@else
                @foreach($element as $page=>$url)<li class="page-item {{ $page===$paginator->currentPage() ? 'active' : '' }}">@if($page===$paginator->currentPage())<span class="page-link" aria-current="page">{{ $page }}</span>@else<a class="page-link" href="{{ $url }}" aria-label="Trang {{ $page }}">{{ $page }}</a>@endif</li>@endforeach
            @endif
        @endforeach
        <li class="page-item {{ !$paginator->hasMorePages() ? 'disabled' : '' }}">@if($paginator->hasMorePages())<a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Trang sau">›</a>@else<span class="page-link" aria-disabled="true">›</span>@endif</li>
    </ul>
</nav>
@endif
