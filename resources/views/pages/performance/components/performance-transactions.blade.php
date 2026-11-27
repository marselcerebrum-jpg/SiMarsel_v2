{{--
    Daftar Transaksi (FIX-13-FE-01): the paginated list with its local filters, Filter Lainnya, and the detail panel.
    Rows, options and the detail come from performanceTransactions (resources/js/pages/performance/transactions.js).
    Ekspor Daftar Transaksi stays reserved for FIX-14-FE-01.
--}}
@php
    $control = 'h-9 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50';
    $group = 'inline-flex h-9 max-w-full shrink-0 items-center gap-0.5 rounded-lg border border-line bg-soft p-0.5';
    $pill = 'inline-flex h-8 shrink-0 items-center rounded-md px-3 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-primary';
    $checkbox = 'size-4 shrink-0 rounded border-line text-primary focus-visible:outline-2 focus-visible:outline-primary';
    $option = 'flex cursor-pointer items-center gap-2.5 rounded-md px-2.5 py-2 text-sm text-ink hover:bg-soft';
    $closeButton = 'inline-grid size-9 place-items-center rounded-lg text-muted hover:bg-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
    $th = 'px-4 py-3 whitespace-nowrap';
    $td = 'px-4 py-3 text-muted';

    $columns = ['Tanggal Dibuat', 'Tanggal Dibayar', 'Platform', 'Produk', 'Customer', 'Metode Pembayaran'];

    $badge = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap ring-1 ring-inset';
@endphp

<div x-data="performanceTransactions" @performance-filter.window="applyFilter($event.detail)" class="min-w-0">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 id="dashboard-transactions-title" class="text-base font-semibold text-ink">Daftar Transaksi</h2>
            <p class="mt-1 text-sm text-muted">
                Periode dan platform mengikuti filter utama<span x-show="periodLabel" x-cloak> (<span x-text="periodLabel"></span>)</span>.
                Filter daftar tidak mengubah angka ringkasan.
            </p>
        </div>

        <div data-dashboard-slot="transactions-export" class="flex flex-wrap items-center gap-2">
            <button type="button" disabled class="inline-flex h-9 cursor-not-allowed items-center justify-center rounded-lg border border-line bg-white px-3 text-sm font-semibold text-label opacity-60">
                Ekspor Daftar Transaksi
            </button>
        </div>
    </div>

    {{-- Local filters: Pencarian, Status and Dasar Tanggal above the table; the rest in Filter Lainnya. --}}
    <div class="mt-4 flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-center" role="search" aria-label="Filter Daftar Transaksi">
        <label class="relative block w-full lg:max-w-sm">
            <span class="sr-only">Cari transaksi</span>
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-label" />
            <input
                type="search"
                maxlength="100"
                class="h-9 w-full rounded-lg border border-line bg-white pr-3 pl-9 text-sm text-ink placeholder:text-label focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                :class="listErrors.search && 'border-red-500'"
                placeholder="Cari ID, nama, email, atau telepon"
                x-model="local.search"
                @input.debounce.400ms="applyLocal()"
                @keydown.enter.prevent="applyLocal()"
            >
        </label>

        <div class="flex flex-wrap items-center gap-2">
            {{-- Status: several report statuses, with the Tidak Berhasil shortcut. --}}
            <div x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open && ((open = false), $refs.statusTrigger.focus())" class="relative">
                <button
                    type="button"
                    x-ref="statusTrigger"
                    class="{{ $control }} inline-flex items-center gap-2"
                    aria-controls="transaction-status-options"
                    :aria-expanded="open.toString()"
                    @click="open = !open"
                >
                    <span class="text-label">Status:</span>
                    <span x-text="statusSummary"></span>
                    <x-icon name="chevron-down" class="size-4 text-label" />
                </button>
                <div id="transaction-status-options" x-show="open" x-cloak class="absolute left-0 z-20 mt-1 w-60 rounded-lg border border-line bg-white p-1.5 shadow-lg" role="group" aria-label="Status">
                    <button
                        type="button"
                        class="mb-1 w-full rounded-md px-2.5 py-2 text-left text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-primary"
                        :aria-pressed="unsuccessfulSelected.toString()"
                        @click="toggleUnsuccessful()"
                    >
                        Tidak Berhasil <span class="font-normal text-label">(Expired, Canceled, Failed)</span>
                    </button>
                    <div class="border-t border-line pt-1">
                        <template x-for="item in statusOptions" :key="item.value">
                            <label class="{{ $option }}">
                                <input type="checkbox" class="{{ $checkbox }}" :checked="local.statuses.includes(item.value)" @change="toggleStatus(item.value)">
                                <span x-text="item.label"></span>
                            </label>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Dasar Tanggal: also sets the order, newest first. --}}
            <div class="{{ $group }}" role="radiogroup" aria-label="Dasar Tanggal">
                <template x-for="option in dateBases" :key="option.value">
                    <button
                        type="button"
                        role="radio"
                        class="{{ $pill }}"
                        :class="local.date_basis === option.value ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink'"
                        :aria-checked="(local.date_basis === option.value).toString()"
                        @click="setDateBasis(option.value)"
                        x-text="option.label"
                    ></button>
                </template>
            </div>

            <button
                type="button"
                class="{{ $control }} inline-flex items-center gap-2"
                aria-haspopup="dialog"
                aria-controls="transaction-more-filters"
                :aria-expanded="moreOpen.toString()"
                @click="openMore()"
            >
                <x-icon name="sliders" class="size-4" />
                Filter Lainnya
                <span x-show="moreFilterCount" x-cloak class="inline-grid min-w-5 place-items-center rounded-full bg-primary px-1.5 text-[11px] text-white" x-text="moreFilterCount"></span>
            </button>

            <button type="button" x-show="hasLocalFilters" x-cloak class="{{ $control }}" @click="resetLocal()">Reset Filter Daftar</button>
        </div>
    </div>

    <p x-show="Object.keys(listErrors).length" x-cloak class="mt-2 text-sm text-red-600" role="alert" x-text="Object.values(listErrors).join(' ')"></p>

    {{-- Table: the ID column stays visible while the table scrolls sideways. --}}
    <div class="relative mt-4 min-h-40 overflow-hidden rounded-lg border border-line" :aria-busy="listLoading.toString()">
        @include('pages.performance.components.loading-spinner', ['show' => 'listLoading'])

        <div x-show="hasRows" x-cloak class="overflow-x-auto">
            <table class="w-full min-w-[1100px] text-left text-sm">
                <caption class="sr-only" x-text="`Daftar transaksi, urut terbaru menurut ${dateBasisLabel}`"></caption>
                <thead class="bg-soft text-xs font-semibold text-label">
                    <tr>
                        <th scope="col" class="{{ $th }} sticky left-0 z-[1] bg-soft">ID Transaksi</th>
                        @foreach ($columns as $column)
                            <th scope="col" class="{{ $th }}">{{ $column }}</th>
                        @endforeach
                        <th scope="col" class="{{ $th }} text-right">Nominal</th>
                        <th scope="col" class="{{ $th }}">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <template x-for="row in rows" :key="row.id">
                        <tr class="group hover:bg-soft/60">
                            <th scope="row" class="sticky left-0 z-[1] bg-surface px-4 py-3 font-semibold group-hover:bg-soft">
                                <button
                                    type="button"
                                    class="performance-value text-left text-primary underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                                    aria-haspopup="dialog"
                                    :aria-label="`Lihat detail transaksi ${row.trxId}`"
                                    @click="openDetail(row.id)"
                                    x-text="row.trxId"
                                ></button>
                                <span class="mt-1 flex flex-wrap gap-1">
                                    <span x-show="row.isClaim" class="{{ $badge }} bg-soft text-label ring-line">Klaim</span>
                                    <span x-show="row.groupMembers" class="{{ $badge }} bg-soft text-label ring-line" x-text="`Grup · ${row.groupMembers} anggota`"></span>
                                </span>
                            </th>
                            <td class="{{ $td }} performance-value whitespace-nowrap" x-text="row.createdAt"></td>
                            <td class="{{ $td }} performance-value whitespace-nowrap" x-text="row.paidAt"></td>
                            <td class="{{ $td }} whitespace-nowrap" x-text="row.platform"></td>
                            <td class="{{ $td }} max-w-64 break-words text-ink" x-text="row.product"></td>
                            <td class="{{ $td }} break-words" x-text="row.customer"></td>
                            <td class="{{ $td }} whitespace-nowrap" x-text="row.paymentMethod"></td>
                            <td class="{{ $td }} performance-value text-right font-semibold whitespace-nowrap text-ink" x-text="row.nominal"></td>
                            <td class="{{ $td }}">
                                <span class="{{ $badge }}" :class="toneClass(row.statusTone)" x-text="row.status"></span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        {{-- Empty: the search and filters stay as typed. --}}
        <div x-show="isEmpty" x-cloak class="grid min-h-40 place-items-center px-4 py-8 text-center" role="status">
            <div class="flex flex-col items-center gap-3">
                <p class="max-w-md text-sm text-label" x-text="emptyMessage"></p>
                <button type="button" x-show="hasLocalFilters" class="{{ $control }}" @click="resetLocal()">Reset Filter Daftar</button>
            </div>
        </div>

        <div x-show="!listLoading && listError" x-cloak class="grid min-h-40 place-items-center px-4 py-8 text-center" role="alert">
            <div class="flex flex-col items-center gap-3">
                <p class="text-sm font-semibold text-red-600" x-text="listError"></p>
                <button type="button" class="{{ $control }}" @click="retry()">Muat Ulang</button>
            </div>
        </div>
    </div>

    {{-- Pagination --}}
    <div x-show="hasRows" x-cloak class="mt-3 flex flex-col gap-2 text-sm text-muted sm:flex-row sm:items-center sm:justify-between">
        <p class="performance-value" x-text="pageSummary"></p>
        <nav class="flex items-center gap-2" aria-label="Halaman daftar transaksi">
            <button type="button" class="{{ $control }} inline-flex items-center gap-1" :disabled="listLoading || page <= 1" @click="goToPage(page - 1)">
                <x-icon name="chevron-left" class="size-4" /> Sebelumnya
            </button>
            <span>Halaman <span class="font-semibold text-ink" x-text="page"></span> dari <span x-text="lastPage"></span></span>
            <button type="button" class="{{ $control }} inline-flex items-center gap-1" :disabled="listLoading || page >= lastPage" @click="goToPage(page + 1)">
                Berikutnya <x-icon name="chevron-right" class="size-4" />
            </button>
        </nav>
    </div>

    {{-- Filter Lainnya --}}
    <div x-show="moreOpen" x-cloak class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-slate-900/50" x-transition.opacity @click="closeMore()"></div>
        <div
            id="transaction-more-filters"
            x-dialog="moreOpen"
            role="dialog"
            aria-modal="true"
            aria-labelledby="transaction-more-filters-title"
            @keydown.escape.stop="closeMore()"
            class="absolute inset-x-0 bottom-0 max-h-[85vh] overflow-y-auto rounded-t-panel bg-surface p-5 shadow-xl sm:inset-x-auto sm:top-0 sm:right-0 sm:bottom-0 sm:max-h-none sm:w-96 sm:rounded-none"
        >
            <div class="flex items-center justify-between gap-3">
                <h2 id="transaction-more-filters-title" class="text-base font-semibold text-ink">Filter Lainnya</h2>
                <button type="button" data-dialog-initial class="{{ $closeButton }}" aria-label="Tutup Filter Lainnya" @click="closeMore()">
                    <x-icon name="close" class="size-5" />
                </button>
            </div>

            <p x-show="optionsLoading" class="mt-3 text-sm text-label" role="status">Memuat pilihan…</p>
            <p x-show="optionsError" x-cloak class="mt-3 text-sm text-red-600" role="alert" x-text="optionsError"></p>

            <template x-for="filter in moreFilters" :key="filter.key">
                <fieldset class="mt-5">
                    <legend class="text-sm font-semibold text-ink" x-text="filter.label"></legend>
                    <div class="mt-2 max-h-48 overflow-y-auto rounded-lg border border-line p-1">
                        <template x-for="value in optionValues(filter.key)" :key="value">
                            <label class="{{ $option }}">
                                <input
                                    type="checkbox"
                                    class="{{ $checkbox }}"
                                    :checked="isSelected(filter.key, value)"
                                    :disabled="filter.key === 'promo_codes' && local.promo === 'without'"
                                    @change="toggleMore(filter.key, value)"
                                >
                                <span class="break-words" x-text="value"></span>
                            </label>
                        </template>
                        <p x-show="!optionsLoading && optionValues(filter.key).length === 0" class="px-2.5 py-2 text-sm text-label">Tidak ada pilihan pada periode ini.</p>
                    </div>
                </fieldset>
            </template>

            <fieldset class="mt-5">
                <legend class="text-sm font-semibold text-ink">Promo</legend>
                <div class="mt-2 flex flex-wrap gap-2">
                    <template x-for="option in promoOptions" :key="option.value">
                        <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm text-ink has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                            <input type="radio" name="transaction-promo" class="size-4 text-primary" :value="option.value" :checked="local.promo === option.value" @change="setPromo(option.value)">
                            <span x-text="option.label"></span>
                        </label>
                    </template>
                </div>
                <p class="mt-2 text-xs text-label">Tanpa Promo tidak dapat digabung dengan kode promo.</p>
            </fieldset>

            <div class="mt-6 flex flex-wrap justify-end gap-2">
                <button type="button" x-show="hasLocalFilters" class="{{ $control }}" @click="resetLocal()">Reset Filter Daftar</button>
                <button type="button" class="{{ $control }} border-primary bg-primary text-white hover:bg-primary/90" @click="closeMore()">Selesai</button>
            </div>
        </div>
    </div>

    {{-- Detail Transaksi: takes focus when opened and gives it back to the ID when closed (x-dialog). --}}
    <div x-show="detailOpen" x-cloak class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-slate-900/50" x-transition.opacity @click="closeDetail()"></div>
        <div
            id="transaction-detail"
            x-dialog="detailOpen"
            role="dialog"
            aria-modal="true"
            aria-labelledby="transaction-detail-title"
            @keydown.escape.stop="closeDetail()"
            class="absolute inset-x-0 bottom-0 max-h-[90vh] overflow-y-auto rounded-t-panel bg-surface p-5 shadow-xl sm:inset-x-auto sm:top-0 sm:right-0 sm:bottom-0 sm:max-h-none sm:w-[28rem] sm:rounded-none"
            :aria-busy="detailLoading.toString()"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 id="transaction-detail-title" class="text-base font-semibold text-ink">Detail Transaksi</h2>
                    <p class="performance-value mt-0.5 text-sm break-all text-muted" x-text="detail?.trxId ?? ''"></p>
                </div>
                <button type="button" data-dialog-initial class="{{ $closeButton }}" aria-label="Tutup detail transaksi" @click="closeDetail()">
                    <x-icon name="close" class="size-5" />
                </button>
            </div>

            <div x-show="detailLoading" class="relative mt-6 h-40">
                @include('pages.performance.components.loading-spinner', ['show' => 'detailLoading'])
            </div>

            {{-- Failure: the list and its filters stay; close or try again. --}}
            <div x-show="!detailLoading && detailError" x-cloak class="mt-6 rounded-lg border border-red-200 bg-red-50 p-4" role="alert">
                <p class="text-sm font-semibold text-red-700" x-text="detailError"></p>
                <div class="mt-3 flex gap-2">
                    <button type="button" class="{{ $control }}" @click="retryDetail()">Coba Lagi</button>
                    <button type="button" class="{{ $control }}" @click="closeDetail()">Tutup</button>
                </div>
            </div>

            <template x-if="detail && !detailLoading">
                <div class="mt-4 space-y-5">
                    <div class="flex flex-wrap gap-2">
                        <span class="{{ $badge }}" :class="toneClass(detail.statusTone)" x-text="detail.status"></span>
                        <span x-show="detail.isClaim" class="{{ $badge }} bg-soft text-label ring-line">Klaim Kupon</span>
                        <span x-show="detail.group.label" class="{{ $badge }} bg-soft text-label ring-line" x-text="detail.group.label"></span>
                    </div>

                    <section aria-labelledby="transaction-detail-info">
                        <h3 id="transaction-detail-info" class="text-sm font-semibold text-ink">Transaksi</h3>
                        <dl class="mt-2 divide-y divide-line rounded-lg border border-line text-sm">
                            <template x-for="item in detail.info" :key="item.label">
                                <div class="grid grid-cols-[9rem_1fr] gap-3 px-3 py-2">
                                    <dt class="text-label" x-text="item.label"></dt>
                                    <dd class="performance-value break-words text-ink" x-text="item.value"></dd>
                                </div>
                            </template>
                        </dl>
                    </section>

                    <section aria-labelledby="transaction-detail-customer">
                        <h3 id="transaction-detail-customer" class="text-sm font-semibold text-ink">Customer</h3>
                        <dl class="mt-2 divide-y divide-line rounded-lg border border-line text-sm">
                            <template x-for="item in detail.customer" :key="item.label">
                                <div class="grid grid-cols-[9rem_1fr] gap-3 px-3 py-2">
                                    <dt class="text-label" x-text="item.label"></dt>
                                    <dd class="break-all text-ink" x-text="item.value"></dd>
                                </div>
                            </template>
                        </dl>
                    </section>

                    <section aria-labelledby="transaction-detail-financials">
                        <h3 id="transaction-detail-financials" class="text-sm font-semibold text-ink">Rincian Keuangan</h3>
                        <dl class="mt-2 divide-y divide-line rounded-lg border border-line text-sm">
                            <template x-for="item in detail.financials" :key="item.key">
                                <div class="flex items-center justify-between gap-3 px-3 py-2" :class="item.key === 'revenue_contribution' && 'bg-soft/60 font-semibold'">
                                    <dt class="text-label" x-text="item.label"></dt>
                                    <dd class="performance-value text-right text-ink" x-text="item.value"></dd>
                                </div>
                            </template>
                        </dl>
                        <p class="mt-1.5 text-xs text-label">Kontribusi Omzet hanya untuk pembelian Success dengan Tanggal Dibayar valid, dan masuk Omzet pada tanggal itu.</p>
                    </section>

                    <section x-show="detail.group.role" aria-labelledby="transaction-detail-group">
                        <h3 id="transaction-detail-group" class="text-sm font-semibold text-ink" x-text="detail.group.role === 'member' ? 'Pembeli Grup' : 'Anggota Grup'"></h3>
                        <ul class="mt-2 divide-y divide-line rounded-lg border border-line text-sm">
                            <template x-for="person in (detail.group.role === 'member' ? (detail.group.buyer ? [detail.group.buyer] : []) : detail.group.members)" :key="person.id ?? person.trxId">
                                <li class="px-3 py-2.5">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <button
                                            type="button"
                                            class="performance-value text-left font-semibold text-primary underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-primary disabled:text-ink disabled:no-underline"
                                            :disabled="person.id === null"
                                            :aria-label="`Lihat detail transaksi ${person.trxId}`"
                                            @click="openRelated(person.id)"
                                            x-text="person.trxId"
                                        ></button>
                                        <span class="flex flex-wrap gap-1">
                                            <span x-show="person.isClaim" class="{{ $badge }} bg-soft text-label ring-line">Klaim</span>
                                            <span class="{{ $badge }}" :class="toneClass(person.statusTone)" x-text="person.status"></span>
                                        </span>
                                    </div>
                                    <p class="mt-1 text-ink" x-text="person.name"></p>
                                    <p class="text-xs break-all text-label" x-text="`${person.email} · ${person.phone}`"></p>
                                </li>
                            </template>
                        </ul>
                    </section>
                </div>
            </template>
        </div>
    </div>
</div>
