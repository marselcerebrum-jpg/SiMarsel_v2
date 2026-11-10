{{-- Codes from Markaz transactions without a master for their platform. Not based on any active/inactive status. Shown only while such codes exist. --}}
@php
    $pageButton = 'inline-flex h-10 items-center justify-center gap-1 rounded-lg border border-line bg-white px-3 text-xs font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50';
    $term = 'text-xs font-semibold text-label';
@endphp

<div x-data="promoUnregisteredCodes" @promo-codes-changed.window="refresh()" class="mt-5 min-w-0">
    <p x-show="loadError && pendingTotal === null" x-cloak class="flex flex-wrap items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
        <span x-text="loadError"></span>
        <button type="button" class="font-semibold underline hover:no-underline" @click="fetchItems()">Coba Lagi</button>
    </p>

    <section x-show="visible" x-cloak class="rounded-lg border border-amber-300 bg-amber-50" aria-labelledby="unregistered-title">
        <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex min-w-0 gap-3">
                <x-icon name="alert" class="mt-0.5 size-5 text-amber-700" />
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 id="unregistered-title" class="text-sm font-semibold text-amber-900">Kode transaksi belum terdaftar</h3>
                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold" :class="status.tone" x-text="status.text"></span>
                    </div>
                    <p class="mt-1 text-sm text-amber-900">Ada kode pada transaksi yang belum punya master di platform terkait. Cek kode lalu daftarkan agar pemetaannya dapat dilengkapi.</p>
                </div>
            </div>

            <button
                type="button"
                class="inline-flex h-11 w-full shrink-0 items-center justify-center gap-2 rounded-lg border border-amber-400 bg-white px-4 text-sm font-semibold text-amber-900 hover:bg-amber-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-700 sm:w-auto"
                aria-controls="unregistered-review"
                :aria-expanded="reviewOpen.toString()"
                @click="toggleReview()"
            >
                Lihat kode
                <x-icon name="chevron-down" class="size-4 transition-transform" ::class="reviewOpen && 'rotate-180'" />
            </button>
        </div>

        <div id="unregistered-review" x-ref="review" tabindex="-1" x-show="reviewOpen" x-cloak class="border-t border-amber-200 bg-white p-4 focus-visible:outline-none" aria-label="Daftar kode transaksi belum terdaftar">
            <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                Transaksi dengan kode ini tetap tersimpan dan tetap dihitung dalam analisis. Mendaftarkan kode dari alert juga dapat melengkapi
                penggunaan historis yang sebelumnya belum terpetakan dengan funnel/source yang didaftarkan.
                Hanya penggunaan yang belum terhubung ke kode promo yang diproses; penggunaan yang sudah terhubung tidak diproses ulang meskipun funnel/source-nya kosong. Klasifikasi Funnel/Source yang sudah ada tidak akan ditimpa.
            </p>

            <div class="mt-4 w-full sm:max-w-xs">
                <label for="unregistered-search" class="mb-1.5 block text-xs font-semibold text-label">Cari kode belum terdaftar</label>
                <div class="relative">
                    <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-label" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="7" />
                        <path d="M20 20l-3.5-3.5" />
                    </svg>
                    <input
                        id="unregistered-search"
                        type="search"
                        x-model.debounce.400ms="filter.search"
                        placeholder="Kode atau platform…"
                        maxlength="100"
                        class="h-10 w-full rounded-lg border border-line bg-white pr-3 pl-9 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                    >
                </div>
            </div>

            <p x-show="loading && !items.length" x-cloak class="mt-4 px-4 py-6 text-center text-sm text-muted" role="status">Memeriksa kode promo yang belum terdaftar…</p>

            <div x-show="!loading && loadError" x-cloak class="mt-4 px-4 py-6 text-center" role="alert">
                <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
                <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="fetchItems()">Coba Lagi</button>
            </div>

            <div x-show="!loading && !loadError && !items.length && hasFilter" x-cloak class="mt-4 px-4 py-6 text-center" role="status">
                <p class="text-sm font-medium text-ink">Tidak ada kode belum terdaftar yang cocok.</p>
                <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="filter.search = ''">Atur Ulang Pencarian</button>
            </div>

            <ul x-show="items.length && !loadError" class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3" :class="loading && 'opacity-50'" :aria-busy="loading.toString()">
                <template x-for="item in items" :key="item.platform.id + ':' + item.code">
                    <li class="flex min-w-0 flex-col rounded-lg border border-line bg-surface p-4">
                        <dl class="grid gap-3 text-sm">
                            <div>
                                <dt class="{{ $term }}">Kode asli</dt>
                                <dd class="mt-1 flex flex-wrap gap-1" title="Penulisan asli dari transaksi Markaz">
                                    <template x-for="raw in item.raw_codes" :key="raw">
                                        <span class="rounded-md bg-soft px-1.5 py-0.5 font-mono text-xs font-semibold break-all whitespace-pre-wrap text-ink" x-text="raw"></span>
                                    </template>
                                </dd>
                            </div>
                            <div x-show="item.raw_codes.some((raw) => raw !== item.code)">
                                <dt class="{{ $term }}">Didaftarkan sebagai</dt>
                                <dd class="mt-1 font-mono font-semibold break-all text-ink" x-text="item.code"></dd>
                            </div>
                            <div>
                                <dt class="{{ $term }}">Platform</dt>
                                <dd class="mt-1">
                                    <span class="inline-flex items-center rounded-md bg-primary-soft px-2 py-0.5 text-xs font-semibold text-primary-hover" x-text="item.platform.name"></span>
                                </dd>
                            </div>
                            <div>
                                <dt class="{{ $term }}">Penggunaan</dt>
                                <dd class="mt-1 text-ink">
                                    <span class="font-semibold" x-text="item.usages_count + ' penggunaan'"></span>
                                    <span class="text-muted" x-text="'· ' + item.transactions_count + ' transaksi'"></span>
                                </dd>
                            </div>
                            <div>
                                <dt class="{{ $term }}">Terakhir dipakai</dt>
                                <dd class="mt-1 text-ink">
                                    <span x-text="formatUsedOn(item.last_used_on)"></span>
                                    <span x-show="item.first_used_on && item.first_used_on !== item.last_used_on" class="block text-xs text-muted" x-text="'Sejak ' + formatUsedOn(item.first_used_on)"></span>
                                </dd>
                            </div>
                        </dl>

                        <button
                            type="button"
                            class="mt-4 inline-flex h-11 w-full items-center justify-center rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                            :aria-label="'Daftarkan kode ' + item.code + ' ' + item.platform.name"
                            @click="register(item)"
                        >Daftarkan kode</button>
                    </li>
                </template>
            </ul>

            <nav x-show="meta && meta.last_page > 1" x-cloak class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" aria-label="Navigasi halaman kode belum terdaftar">
                <p class="text-xs text-muted">
                    Menampilkan <span class="font-semibold text-ink" x-text="meta?.from ?? 0"></span>–<span class="font-semibold text-ink" x-text="meta?.to ?? 0"></span>
                    dari <span x-text="meta?.total ?? 0"></span> kode
                </p>

                <div class="flex items-center gap-2">
                    <button type="button" class="{{ $pageButton }}" :disabled="loading || filter.page <= 1" @click="goToPage(filter.page - 1)">
                        <x-icon name="chevron-left" class="size-3.5" />
                        Sebelumnya
                    </button>
                    <span class="text-xs text-muted">
                        Halaman <span class="font-semibold text-ink" x-text="meta?.current_page"></span> dari <span x-text="meta?.last_page"></span>
                    </span>
                    <button type="button" class="{{ $pageButton }}" :disabled="loading || filter.page >= (meta?.last_page ?? 1)" @click="goToPage(filter.page + 1)">
                        Berikutnya
                        <x-icon name="chevron-right" class="size-3.5" />
                    </button>
                </div>
            </nav>
        </div>
    </section>
</div>
