{{-- Analisis tab: filter → summary → trend → breakdown. Every component stays mounted, so all of them follow the applied filter and answer Salin. --}}
@php
    $breakdownTabs = [
        'top-codes' => 'Top 5 Kode Promo',
        'funnel' => 'Per Funnel',
        'source' => 'Per Source',
        'product-type' => 'Perdana vs Perpanjangan',
        'codes' => 'Rekap per Kode Promo',
    ];
    $breakdownViews = [
        'top-codes' => 'promo-analysis-top-codes',
        'funnel' => 'promo-analysis-funnel',
        'source' => 'promo-analysis-source',
        'product-type' => 'promo-analysis-product-type',
        'codes' => 'promo-analysis-code',
    ];
@endphp

<div x-data="promoAnalysis" @promo-analysis-errors="showServerErrors($event.detail)" class="mt-6 grid min-w-0 gap-4">
    @include('pages.promo.components.promo-analysis-filter')
    @include('pages.promo.components.promo-analysis-summary')
    @include('pages.promo.components.promo-analysis-trend')

    <section
        x-data="promoTabs('promo-breakdown', @js(array_keys($breakdownTabs)))"
        class="{{ $card }} min-w-0 p-5"
        aria-labelledby="promo-breakdown-title"
    >
        <h2 id="promo-breakdown-title" class="text-base font-semibold text-ink">Rincian Performa</h2>

        <div class="mt-4">
            @include('pages.promo.components.promo-subtabs', ['id' => 'promo-breakdown', 'label' => 'Kategori Rincian Performa', 'tabs' => $breakdownTabs])
        </div>

        @foreach ($breakdownViews as $key => $view)
            <div
                id="panel-promo-breakdown-{{ $key }}"
                role="tabpanel"
                aria-labelledby="tab-promo-breakdown-{{ $key }}"
                tabindex="0"
                x-show="tab === @js($key)"
                @if (! $loop->first) x-cloak @endif
                class="mt-5 min-w-0 focus-visible:outline-none"
            >
                @include('pages.promo.components.'.$view)
            </div>
        @endforeach
    </section>
</div>
