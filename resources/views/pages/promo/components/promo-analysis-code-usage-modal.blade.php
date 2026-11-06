{{-- Read-only usage detail of the clicked promo code row, using the analysis filter active when it was opened. No import here. --}}
@php
    $pageButton = 'inline-flex h-8 items-center justify-center gap-1 rounded-lg border border-line bg-white px-3 text-xs font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50';
@endphp

<div
    x-data="promoAnalysisCodeUsage"
    @promo-code-usage-open.window="show($event.detail); $nextTick(() => $refs.codeUsageClose.focus())"
    @keydown.escape.window="open && close()"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="promo-code-usage-title"
>
    <div x-show="open" x-transition.opacity class="absolute inset-0 bg-slate-900/50" @click="close()"></div>

    <div x-show="open" x-transition class="relative flex max-h-[90vh] w-full max-w-3xl flex-col rounded-panel border border-line bg-surface shadow-lg">
        <div class="flex items-start justify-between gap-3 border-b border-line p-5">
            <div class="min-w-0">
                <h2 id="promo-code-usage-title" class="text-base font-semibold text-ink">Promo Code Usage Details</h2>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted">
                    <span class="font-mono font-semibold break-all text-ink" x-text="row?.code"></span>
                    <span class="inline-flex items-center rounded-md bg-primary-soft px-2 py-0.5 text-xs font-semibold text-primary-hover" x-text="row?.platform.name"></span>
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <button type="button" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50" :disabled="copying || loading" @click="copy()">
                    <span x-show="copying" x-cloak class="size-3.5 animate-spin rounded-full border-2 border-primary border-t-transparent motion-reduce:animate-none" aria-hidden="true"></span>
                    <x-icon name="clipboard" x-show="!copying" class="size-4" />
                    Copy
                </button>
                <button type="button" x-ref="codeUsageClose" class="rounded-lg p-1.5 text-muted hover:bg-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-primary" aria-label="Close" @click="close()">
                    <x-icon name="close" />
                </button>
            </div>
        </div>

        <div class="overflow-y-auto p-5">
            <p x-show="copyError" x-cloak class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert" x-text="copyError"></p>

            <div class="overflow-hidden rounded-lg border border-line">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[560px] text-left text-sm" :aria-busy="loading.toString()">
                        <thead class="bg-soft text-xs font-semibold text-label">
                            <tr>
                                <th scope="col" class="px-4 py-3">Tanggal</th>
                                <th scope="col" class="px-4 py-3">Platform/Aplikasi</th>
                                <th scope="col" class="px-4 py-3 text-right">Jumlah Penggunaan</th>
                                <th scope="col" class="px-4 py-3 text-right">Revenue</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-line" :class="loading && transactions.length && 'opacity-50'">
                            <template x-for="transaction in transactions" :key="transaction.trx_id">
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap text-ink" x-text="formatDate(transaction.paid_date)"></td>
                                    <td class="px-4 py-3 text-ink" x-text="transaction.platform?.name"></td>
                                    <td class="px-4 py-3 text-right text-ink tabular-nums" x-text="format(transaction.usages, 'number')"></td>
                                    <td class="px-4 py-3 text-right text-ink tabular-nums" x-text="format(transaction.revenue, 'currency')"></td>
                                </tr>
                            </template>

                            <tr x-show="loading && !transactions.length" x-cloak>
                                <td colspan="4" class="px-4 py-10 text-center text-sm text-muted">Memuat detail penggunaan…</td>
                            </tr>

                            <tr x-show="!loading && loadError" x-cloak>
                                <td colspan="4" class="px-4 py-10 text-center" role="alert">
                                    <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
                                    <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="retry()">Try Again</button>
                                </td>
                            </tr>

                            <tr x-show="!loading && !loadError && isEmpty" x-cloak>
                                <td colspan="4" class="px-4 py-10 text-center text-sm font-medium text-ink" role="status" x-text="emptyMessage"></td>
                            </tr>
                        </tbody>

                        <tfoot x-show="totals && !isEmpty" x-cloak class="border-t border-line bg-soft font-semibold text-ink">
                            <tr>
                                <th scope="row" colspan="2" class="px-4 py-3">Total</th>
                                <td class="px-4 py-3 text-right tabular-nums" x-text="format(totals?.usages, 'number')"></td>
                                <td class="px-4 py-3 text-right tabular-nums" x-text="format(totals?.revenue, 'currency')"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <nav x-show="meta && meta.last_page > 1" x-cloak class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" aria-label="Promo Code Usage Pagination">
                <p class="text-xs text-muted">
                    Menampilkan <span class="font-semibold text-ink" x-text="meta?.from ?? 0"></span>–<span class="font-semibold text-ink" x-text="meta?.to ?? 0"></span>
                    dari <span x-text="meta?.total ?? 0"></span> transaksi
                </p>

                <div class="flex items-center gap-2">
                    <button type="button" class="{{ $pageButton }}" :disabled="loading || page <= 1" @click="goToPage(page - 1)">
                        <x-icon name="chevron-left" class="size-3.5" />
                        Previous
                    </button>
                    <span class="text-xs text-muted">
                        Halaman <span class="font-semibold text-ink" x-text="meta?.current_page"></span> dari <span x-text="meta?.last_page"></span>
                    </span>
                    <button type="button" class="{{ $pageButton }}" :disabled="loading || page >= (meta?.last_page ?? 1)" @click="goToPage(page + 1)">
                        Next
                        <x-icon name="chevron-right" class="size-3.5" />
                    </button>
                </div>
            </nav>
        </div>
    </div>
</div>
