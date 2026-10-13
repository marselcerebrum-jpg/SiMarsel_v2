<div class="border-l-4 border-brand-indigo pl-4">
    <p class="text-[11px] font-bold tracking-[.2em] text-label uppercase">Growth</p>
    <h2 id="performance-comparison-title" class="mt-1 flex items-center gap-2 text-xl font-bold tracking-tight text-ink">
        <x-icon name="table" class="size-5 text-brand-indigo" />
        Period Comparison
    </h2>
    <p class="mt-1 text-sm text-muted">Nilai setiap indikator pada periode terpilih dibandingkan dengan periode sebelumnya yang sama panjang.</p>
</div>

<div class="mt-4 overflow-hidden rounded-2xl border border-line bg-white/70 dark:bg-white/40">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-left text-sm">
            <thead class="bg-[#eaf0f8] text-[11px] font-bold tracking-widest text-label uppercase dark:bg-[#dbe3ee]">
                <tr>
                    <th scope="col" class="px-5 py-3.5">Indicator</th>
                    <th scope="col" class="px-5 py-3.5 text-right">
                        Current Period
                        <span class="block text-[10px] font-semibold tracking-normal normal-case" x-text="periodLabel"></span>
                    </th>
                    <th scope="col" class="px-5 py-3.5 text-right">
                        Previous Period
                        <span class="block text-[10px] font-semibold tracking-normal normal-case" x-text="previousPeriodLabel"></span>
                    </th>
                    <th scope="col" class="px-5 py-3.5 text-right">Status</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-line transition-opacity" :class="loading && 'opacity-60'">
                <template x-for="row in rows" :key="row.key">
                    <tr class="transition hover:bg-soft/70">
                        <th scope="row" class="px-5 py-3.5 font-semibold text-ink" x-text="row.label"></th>
                        <td class="performance-value px-5 py-3.5 text-right font-semibold text-ink" x-text="row.current"></td>
                        <td class="performance-value px-5 py-3.5 text-right text-muted" x-text="row.previous"></td>
                        <td class="px-5 py-3.5 text-right">
                            <span x-show="summary" class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-bold whitespace-nowrap" :class="row.trend.tone" x-text="row.trend.text"></span>
                            <span x-show="!summary" class="text-label">—</span>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>
