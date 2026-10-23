{{-- CRUD table for one promo master. Variables: $type (funnel|source), $label, $description, $placeholder. --}}
@php
    $noun = strtolower($label);
@endphp

<section x-data="promoMasterTable(@js($type), @js($label))" class="{{ $card }} min-w-0 p-5" aria-labelledby="{{ $type }}-list-title">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 id="{{ $type }}-list-title" class="text-base font-semibold text-ink">{{ $label }} List</h2>
            <p class="mt-1 text-sm text-muted">{{ $description }}</p>
        </div>

        <button
            type="button"
            class="inline-flex h-9 shrink-0 items-center justify-center gap-2 self-start rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            @click="openCreate()"
        >Create {{ $label }}</button>
    </div>

    <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center">
        <label class="relative block w-full sm:max-w-xs">
            <span class="sr-only">Search {{ $label }}</span>
            <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-label" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7" />
                <path d="M20 20l-3.5-3.5" />
            </svg>
            <input
                type="search"
                x-model="search"
                placeholder="Cari nama {{ $noun }}…"
                class="h-9 w-full rounded-lg border border-line bg-white pr-3 pl-9 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
            >
        </label>

        <p class="text-xs text-muted sm:ml-auto" aria-live="polite">
            Menampilkan <span class="font-semibold text-ink" x-text="filteredItems.length"></span>
            dari <span x-text="items.length"></span> {{ $noun }}
        </p>
    </div>

    <div class="mt-4 overflow-hidden rounded-lg border border-line">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[420px] text-left text-sm">
                <thead class="bg-soft text-xs font-semibold text-label">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ $label }} Name</th>
                        <th scope="col" class="px-4 py-3">Usage</th>
                        <th scope="col" class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    <template x-for="item in filteredItems" :key="item.id">
                        <tr class="hover:bg-soft/60">
                            <td class="px-4 py-3 font-semibold break-all text-ink" x-text="item.name"></td>
                            <td class="px-4 py-3">
                                <span x-show="item.promo_codes_count" class="text-muted" x-text="item.promo_codes_count + ' kode promo'"></span>
                                <span x-show="!item.promo_codes_count" class="inline-flex items-center rounded-md bg-soft px-2 py-0.5 text-xs font-semibold text-muted">Unused</span>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button
                                    type="button"
                                    class="rounded-md px-2 py-1 font-semibold text-primary hover:text-primary-hover focus-visible:outline-2 focus-visible:outline-primary"
                                    :aria-label="'Edit ' + item.name"
                                    @click="openEdit(item)"
                                >Edit</button>
                                <button
                                    type="button"
                                    class="ml-2 rounded-md px-2 py-1 font-semibold text-red-600 hover:text-red-700 focus-visible:outline-2 focus-visible:outline-red-600"
                                    :aria-label="'Delete ' + item.name"
                                    @click="openDelete(item)"
                                >Delete</button>
                            </td>
                        </tr>
                    </template>

                    <tr x-show="loading" x-cloak>
                        <td colspan="3" class="px-4 py-10 text-center text-sm text-muted">Memuat daftar {{ $noun }}…</td>
                    </tr>

                    <tr x-show="!loading && loadError" x-cloak>
                        <td colspan="3" class="px-4 py-10 text-center">
                            <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
                            <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="fetchItems()">Retry</button>
                        </td>
                    </tr>

                    <tr x-show="!loading && !loadError && items.length === 0" x-cloak>
                        <td colspan="3" class="px-4 py-10 text-center">
                            <p class="text-sm font-medium text-ink">Belum ada {{ $noun }}.</p>
                            <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="openCreate()">Create First {{ $label }}</button>
                        </td>
                    </tr>

                    <tr x-show="!loading && !loadError && items.length > 0 && filteredItems.length === 0" x-cloak>
                        <td colspan="3" class="px-4 py-10 text-center">
                            <p class="text-sm font-medium text-ink">Tidak ada {{ $noun }} yang cocok.</p>
                            <p class="mt-1 text-xs text-muted">Coba ubah kata kunci pencarian.</p>
                            <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="search = ''">Reset Search</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    @include('pages.promo.components.master-form-modal')
    @include('pages.promo.components.master-delete-modal')
</section>
