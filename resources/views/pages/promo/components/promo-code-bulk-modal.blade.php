{{-- Spreadsheet-style Bulk Entry. Columns follow Single Entry; there is no code type or active status by design. --}}
@php
    $cellClass = 'h-9 w-full rounded-md border border-line bg-white px-2.5 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:cursor-not-allowed disabled:bg-soft disabled:text-muted';
    $cellError = 'mt-1 text-xs text-red-600';
    $secondaryButton = 'inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50';
    $columns = [
        ['field' => 'platform_id', 'label' => 'Platform', 'options' => 'platforms', 'empty' => 'Pilih platform'],
        ['field' => 'funnel_id', 'label' => 'Funnel', 'options' => 'funnels', 'empty' => 'Belum diketahui'],
        ['field' => 'source_id', 'label' => 'Source', 'options' => 'sources', 'empty' => 'Belum diketahui'],
    ];
@endphp

<div
    x-data="promoCodeBulkEntry"
    @promo-bulk-open.window="open()"
    x-show="isOpen"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="promo-bulk-title"
    @keydown.escape.window="isOpen && close()"
>
    <div x-show="isOpen" x-transition.opacity class="absolute inset-0 bg-slate-900/50" @click="close()"></div>

    <div
        x-show="isOpen"
        x-transition
        class="relative flex max-h-[calc(100vh-2rem)] w-full max-w-6xl flex-col rounded-panel border border-line bg-surface shadow-lg"
    >
        <div class="border-b border-line p-5">
            <h2 id="promo-bulk-title" class="text-base font-semibold text-ink">Bulk Entry</h2>
            <p class="mt-1 text-sm text-muted">
                Daftarkan banyak kode promo sekaligus. Kode Promo dan Platform wajib diisi, Funnel dan Source boleh dikosongkan.
                Salin sel dari Excel/Google Sheets (urutan kolom: Kode, Platform, Funnel, Source) lalu tempel di kolom Promo Code.
            </p>

            <p x-show="optionsError" class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700" role="alert">
                <span x-text="optionsError"></span>
                <button type="button" class="ml-1 font-semibold underline" @click="fetchOptions()">Retry</button>
            </p>

            @include('pages.promo.components.promo-code-bulk-summary')
        </div>

        <div class="min-h-0 flex-1 overflow-auto">
            <table class="w-full min-w-[880px] text-left text-sm">
                <thead class="sticky top-0 z-10 bg-soft text-xs font-semibold text-label">
                    <tr>
                        <th scope="col" class="w-12 px-3 py-3 text-center">#</th>
                        <th scope="col" class="px-2 py-3">Promo Code <span class="text-red-500" aria-hidden="true">*</span></th>
                        <th scope="col" class="px-2 py-3">Platform <span class="text-red-500" aria-hidden="true">*</span></th>
                        <th scope="col" class="px-2 py-3">Funnel</th>
                        <th scope="col" class="px-2 py-3">Source</th>
                        <th scope="col" class="w-28 px-2 py-3">Status</th>
                        <th scope="col" class="w-12 px-2 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    <template x-for="(row, index) in rows" :key="row.key">
                        <tr class="align-top" :class="{ 'bg-emerald-50/60': row.status === 'created', 'bg-red-50/60': row.status === 'failed' }">
                            <td class="px-3 py-2.5 text-center text-xs font-semibold text-label" x-text="index + 1"></td>

                            <td class="px-2 py-2">
                                <input
                                    type="text"
                                    x-model="row.code"
                                    @input="clearError(row, 'code')"
                                    @paste="paste($event, index)"
                                    class="{{ $cellClass }} font-mono uppercase"
                                    :class="row.errors.code && 'border-red-500!'"
                                    :disabled="row.status === 'created' || saving"
                                    :aria-label="'Promo Code baris ' + (index + 1)"
                                    :aria-invalid="Boolean(row.errors.code)"
                                    placeholder="CPNS26"
                                    autocomplete="off"
                                    spellcheck="false"
                                    maxlength="255"
                                >
                                <p x-show="row.errors.code" x-text="row.errors.code" class="{{ $cellError }}"></p>
                            </td>

                            @foreach ($columns as $column)
                                <td class="px-2 py-2">
                                    <select
                                        x-model="row.{{ $column['field'] }}"
                                        @change="clearError(row, '{{ $column['field'] }}')"
                                        class="{{ $cellClass }}"
                                        :class="row.errors.{{ $column['field'] }} && 'border-red-500!'"
                                        :disabled="row.status === 'created' || saving"
                                        :aria-label="'{{ $column['label'] }} baris ' + (index + 1)"
                                        :aria-invalid="Boolean(row.errors.{{ $column['field'] }})"
                                    >
                                        <option value="">{{ $column['empty'] }}</option>
                                        <template x-for="option in options.{{ $column['options'] }}" :key="option.id">
                                            <option :value="String(option.id)" x-text="option.name" :selected="String(option.id) === row.{{ $column['field'] }}"></option>
                                        </template>
                                    </select>
                                    <p x-show="row.errors.{{ $column['field'] }}" x-text="row.errors.{{ $column['field'] }}" class="{{ $cellError }}"></p>
                                </td>
                            @endforeach

                            <td class="px-2 py-2.5">
                                <span x-show="row.status === 'created'" class="inline-flex items-center rounded-md bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Saved</span>
                                <span x-show="row.status === 'failed'" class="inline-flex items-center rounded-md bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">Failed</span>
                                <span x-show="!row.status" class="text-xs text-label">—</span>
                            </td>

                            <td class="px-2 py-2 text-right">
                                <button
                                    type="button"
                                    class="inline-grid size-9 place-items-center rounded-md text-label hover:bg-soft hover:text-red-600 focus-visible:outline-2 focus-visible:outline-red-600 disabled:cursor-not-allowed disabled:opacity-40"
                                    :aria-label="'Hapus baris ' + (index + 1)"
                                    title="Hapus baris"
                                    :disabled="saving"
                                    @click="removeRow(index)"
                                >
                                    <x-icon name="close" />
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="flex flex-col gap-3 border-t border-line p-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="{{ $secondaryButton }}" :disabled="saving || !canAddRows" @click="addRows(1)">Add Row</button>
                <button type="button" class="{{ $secondaryButton }}" :disabled="saving || !canAddRows" @click="addRows(5)">Add 5 Rows</button>
                <p class="text-xs text-muted">
                    <span class="font-semibold text-ink" x-text="pendingRows.length"></span> baris siap disimpan,
                    maksimal <span x-text="maxRows"></span> baris.
                </p>
            </div>

            <div class="flex flex-col gap-2 lg:items-end">
                <p x-show="formError" x-text="formError" class="text-xs text-red-600" role="alert"></p>
                <div class="flex flex-col-reverse gap-2 sm:flex-row">
                    <button type="button" class="{{ $secondaryButton }}" :disabled="saving" @click="close()">Close</button>
                    <button
                        type="button"
                        class="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="saving || (optionsLoading && !options.platforms.length)"
                        @click="submit()"
                    >
                        <span x-text="saving ? 'Saving…' : 'Save All'">Save All</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
