{{--
    Ringkasan Sales (FIX-05-FE-01): Hasil Penjualan and Aktivitas Transaksi side by side when there is room, stacked
    on phones, then the supporting row. Values, comparisons and changes come from performanceSalesSummary
    (resources/js/pages/performance/sales-summary.js); this file only holds the order, titles and date basis.
--}}
@php
    $blocks = [
        [
            'key' => 'sales-result',
            'title' => 'Hasil Penjualan',
            'basis' => 'Berdasarkan tanggal pembayaran',
            'metrics' => [
                ['key' => 'revenue', 'label' => 'Omzet'],
                ['key' => 'success_payments', 'label' => 'Transaksi Berhasil'],
            ],
        ],
        [
            'key' => 'transaction-activity',
            'title' => 'Aktivitas Transaksi',
            'basis' => 'Berdasarkan tanggal transaksi dibuat',
            'metrics' => [
                ['key' => 'total_checkouts', 'label' => 'Total Transaksi'],
                ['key' => 'conversion', 'label' => 'Konversi Transaksi'],
            ],
        ],
    ];

    $supporting = [
        ['key' => 'average_order_value', 'label' => 'Rata-rata Nilai Order'],
        ['key' => 'unique_buyers', 'label' => 'Pembeli Unik'],
        ['key' => 'downloaders', 'label' => 'Downloader'],
    ];
@endphp

<div x-data="performanceSalesSummary" @performance-filter.window="applySalesSummaryFilter($event.detail)" :aria-busy="salesSummaryLoading.toString()">
    <h2 id="dashboard-sales-summary-title" class="text-base font-semibold text-ink">Ringkasan Sales</h2>

    <div x-show="!salesSummaryLoading && salesSummaryError" x-cloak class="mt-3 flex flex-col gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between" role="alert">
        <p class="text-sm text-red-700" x-text="salesSummaryError"></p>
        <button
            type="button"
            class="inline-flex h-9 shrink-0 items-center justify-center self-start rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:self-auto"
            @click="retrySalesSummary()"
        >Coba Lagi</button>
    </div>

    <div x-show="salesSummaryLoading || !salesSummaryError">
        <div class="mt-3 grid gap-4 lg:grid-cols-2">
            @foreach ($blocks as $block)
                <section
                    data-sales-summary-block="{{ $block['key'] }}"
                    class="min-w-0 rounded-panel border border-line bg-surface p-4 shadow-sm"
                    aria-labelledby="sales-summary-{{ $block['key'] }}-title"
                >
                    <h3 id="sales-summary-{{ $block['key'] }}-title" class="text-sm font-semibold text-ink">{{ $block['title'] }}</h3>
                    <p class="mt-0.5 text-xs text-muted">{{ $block['basis'] }}</p>

                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        @foreach ($block['metrics'] as $metric)
                            @include('pages.performance.components.sales-summary-card', ['metric' => $metric])
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

        <div data-sales-summary-block="supporting" class="mt-4 grid gap-3 sm:grid-cols-3">
            @foreach ($supporting as $metric)
                @include('pages.performance.components.sales-summary-card', ['metric' => $metric, 'framed' => true])
            @endforeach
        </div>
    </div>
</div>
