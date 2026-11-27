{{--
    Jam Ramai Pembelian (FIX-12-FE-01): Success payments per WIB payment hour (24 slots), with a local day choice and
    an optional comparison. Slots, values and availability come from performancePurchaseTime
    (resources/js/pages/performance/purchase-time.js), which reads `hourly` of /api/performance/purchase-times.
--}}
@php
    $control = 'h-9 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50';
@endphp

<div
    x-data="performancePurchaseTime"
    @performance-filter.window="applyFilter($event.detail)"
    :aria-busy="hourlyLoading.toString()"
>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <h2 id="performance-purchase-time-title" class="text-base font-semibold text-ink">Jam Ramai Pembelian</h2>
            <p class="mt-1 text-sm text-muted">Jumlah pembayaran berhasil per jam bayar (WIB) sesuai filter aktif.</p>
        </div>

        {{-- Local choices: the day applies to the active period and the comparison; the day shown goes to the export (FIX-14). --}}
        <div class="flex flex-wrap items-center gap-2">
            <label x-show="daySelectable" x-cloak class="inline-flex items-center gap-2 text-sm text-muted">
                <span>Hari</span>
                <select
                    class="{{ $control }} pr-8"
                    :value="dayOfWeek"
                    :disabled="hourlyLoading"
                    @change="setDay($event.target.value)"
                >
                    <template x-for="option in dayOptions" :key="option.value">
                        <option :value="option.value" x-text="option.label" :selected="option.value === dayOfWeek"></option>
                    </template>
                </select>
            </label>

            <button
                type="button"
                role="switch"
                class="{{ $control }} inline-flex items-center gap-2"
                :aria-checked="comparisonShown.toString()"
                :disabled="!comparisonAvailable"
                :title="comparisonNotice || null"
                @click="toggleComparison()"
            >
                <span class="relative inline-flex h-4 w-7 shrink-0 rounded-full transition-colors" :class="comparisonShown ? 'bg-primary' : 'bg-line'" aria-hidden="true">
                    <span class="absolute top-0.5 size-3 rounded-full bg-white shadow transition-all" :class="comparisonShown ? 'left-3.5' : 'left-0.5'"></span>
                </span>
                Tampilkan Pembanding
            </button>
        </div>
    </div>

    {{-- Summary of the shown day; the comparison notice explains a disabled switch in text, not only by its state. --}}
    <div x-show="hourly" x-cloak class="mt-4 flex flex-col gap-1 text-sm text-muted sm:flex-row sm:flex-wrap sm:gap-x-5">
        <p><span class="font-semibold text-ink" x-text="dayLabel"></span> · <span class="performance-value" x-text="activeTotals"></span></p>
        <p x-show="peakHour">Jam teramai: <span class="performance-value font-semibold text-ink" x-text="peakHour"></span></p>
        <p x-show="comparisonShown">Pembanding: <span class="performance-value" x-text="comparisonTotals"></span></p>
        <p x-show="comparisonNotice" class="text-label" x-text="comparisonNotice"></p>
    </div>

    {{-- Legend: the comparison bar is outlined, so the two series are not told apart by color alone. --}}
    <ul x-show="hasSlots" x-cloak class="mt-4 flex flex-wrap gap-4 text-xs font-semibold text-ink" aria-label="Legenda jam ramai pembelian">
        <template x-for="item in legend" :key="item.key">
            <li class="inline-flex items-center gap-2">
                <span class="block h-3 w-3 rounded-sm" :style="`background-color: ${item.color}; border: ${item.pattern === 'outline' ? 1.5 : 0}px solid ${item.border}`" aria-hidden="true"></span>
                <span x-text="item.label"></span>
            </li>
        </template>
        <li x-show="unavailableHours" class="inline-flex items-center gap-2 text-label">
            <span class="block h-3 w-3 rounded-sm bg-slate-400/20 ring-1 ring-slate-300" aria-hidden="true"></span>
            <span>Belum Tersedia</span>
        </li>
    </ul>

    {{-- Chart: hover, tap, or focus and use the arrow keys to open a slot. --}}
    <div class="relative mt-4">
        <div
            class="relative h-72 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary sm:h-80"
            :tabindex="hasSlots ? 0 : -1"
            role="group"
            :aria-label="chartLabel"
            @focus="hasSlots && slotIndex === null && firstSlot()"
            @blur="closeSlot()"
            @keydown.arrow-right.prevent="moveSlot(1)"
            @keydown.arrow-left.prevent="moveSlot(-1)"
            @keydown.home.prevent="firstSlot()"
            @keydown.end.prevent="lastSlot()"
            @keydown.escape="closeSlot()"
        >
            <canvas x-ref="canvas" aria-hidden="true" :class="hourlyLoading && hasSlots && 'opacity-50'"></canvas>

            <div x-show="hourlyLoading && !hasSlots" class="absolute inset-0 animate-pulse rounded-lg bg-soft" aria-hidden="true"></div>

            <div x-show="!hourlyLoading && !hasSlots" x-cloak class="absolute inset-0 grid place-items-center text-center">
                <div class="flex flex-col items-center gap-3">
                    <p class="max-w-md text-sm" :class="hourlyError ? 'font-semibold text-red-600' : 'text-label'" :role="hourlyError ? 'alert' : 'status'" x-text="hourlyPlaceholder"></p>
                    <button x-show="hourlyError" type="button" class="{{ $control }}" @click="retry()">Muat Ulang</button>
                </div>
            </div>

            {{-- Slot detail --}}
            <div
                x-show="detail"
                x-cloak
                class="pointer-events-none absolute top-2 z-10 w-60 max-w-full rounded-lg bg-navy p-3 text-xs text-slate-200 shadow-lg"
                :style="`left: ${detailLeft}px`"
                aria-live="polite"
            >
                <template x-if="detail">
                    <div>
                        <p class="font-semibold text-white" x-text="detail.time"></p>
                        <ul class="mt-2 space-y-2 border-t border-navy-line pt-2">
                            <template x-for="row in detail.rows" :key="row.key">
                                <li>
                                    <span class="inline-flex items-center gap-2 text-slate-400">
                                        <span class="block h-2.5 w-2.5 rounded-sm" :style="`background-color: ${row.color}; border: ${row.pattern === 'outline' ? 1.5 : 0}px solid ${row.border}`" aria-hidden="true"></span>
                                        <span x-text="row.label"></span>
                                    </span>
                                    <dl class="mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
                                        <dt>Jumlah</dt>
                                        <dd class="performance-value text-right font-semibold text-white" x-text="row.payments"></dd>
                                        <dt>Omzet</dt>
                                        <dd class="performance-value text-right font-semibold text-white" x-text="row.revenue"></dd>
                                    </dl>
                                    <p x-show="row.note" class="mt-0.5 text-slate-400" x-text="row.note"></p>
                                </li>
                            </template>
                        </ul>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <p x-show="unavailableNote" x-cloak class="mt-3 text-xs text-label" x-text="unavailableNote"></p>

    {{-- Every slot as text: the same values as the detail, readable without the chart. --}}
    <details x-show="hasSlots" x-cloak class="mt-4 rounded-lg border border-line">
        <summary class="cursor-pointer px-4 py-2.5 text-sm font-semibold text-ink focus-visible:outline-2 focus-visible:outline-primary">Lihat tabel per jam</summary>
        <div class="overflow-x-auto border-t border-line">
            <table class="w-full min-w-[420px] text-left text-sm">
                <caption class="sr-only">Pembayaran berhasil dan omzet per jam WIB</caption>
                <thead class="bg-soft text-xs font-semibold text-label">
                    <tr>
                        <th scope="col" class="px-4 py-2.5">Jam (WIB)</th>
                        <template x-for="item in legend" :key="item.key">
                            <th scope="col" class="px-4 py-2.5 text-right" x-text="`${item.label}: Jumlah · Omzet`"></th>
                        </template>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <template x-for="row in rows" :key="row.hour">
                        <tr>
                            <th scope="row" class="performance-value px-4 py-2 font-semibold text-ink" x-text="row.time"></th>
                            <template x-for="cell in row.rows" :key="cell.key">
                                <td class="performance-value px-4 py-2 text-right text-muted">
                                    <span x-text="cell.payments"></span>
                                    <span x-show="cell.known" x-text="` · ${cell.revenue}`"></span>
                                </td>
                            </template>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p class="border-t border-line px-4 py-2.5 text-[11px] text-label">
            Jam diambil dari tanggal dan jam bayar transaksi berhasil (WIB). Pembayaran tanpa jam bayar yang valid tidak masuk jam mana pun.
        </p>
    </details>
</div>
