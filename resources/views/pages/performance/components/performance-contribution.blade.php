@php
    $columns = ['Platform', 'Revenue', 'Transactions', 'Users', 'Contribution'];
@endphp

<span class="absolute top-0 left-6 h-1 w-16 rounded-b-full bg-linear-to-r from-brand-indigo to-brand-teal" aria-hidden="true"></span>

<div
    x-data="performanceContribution"
    @performance-filter.window="loadContribution($event.detail)"
>
    <div class="flex flex-col gap-1.5 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 id="performance-contribution-title" class="text-2xl leading-tight font-extrabold tracking-tight text-ink">Platform Contribution</h2>
            <p class="mt-1.5 text-sm text-muted">Kontribusi revenue setiap platform sesuai filter utama.</p>
        </div>
        <p x-show="showContribution" x-cloak class="text-xs text-label">
            Total revenue <span class="font-semibold text-ink" x-text="contributionTotalRow.revenue"></span>
        </p>
    </div>

    <div class="mt-6 border-t border-line"></div>

    {{-- Loading --}}
    <div x-show="contributionLoading && !contribution" class="grid gap-6 pt-6 lg:grid-cols-5" aria-hidden="true">
        <div class="grid place-items-center lg:col-span-2">
            <span class="performance-shimmer size-56 rounded-full"></span>
        </div>
        <div class="space-y-3 lg:col-span-3">
            @foreach (range(1, 5) as $row)
                <span class="performance-shimmer block h-10 rounded-xl"></span>
            @endforeach
        </div>
    </div>

    {{-- Empty or failed --}}
    <div x-show="!contributionLoading && !showContribution" x-cloak class="grid min-h-56 place-items-center pt-6 text-center" role="status">
        <div class="flex flex-col items-center gap-2 text-label">
            <x-icon name="chart" class="size-8" />
            <p class="text-sm" x-text="contributionMessage"></p>
        </div>
    </div>

    {{-- Pie chart + table --}}
    <div x-show="showContribution" x-cloak class="grid items-center gap-6 pt-6 transition-opacity lg:grid-cols-5" :class="contributionLoading && 'opacity-60'">
        <div class="relative mx-auto h-64 w-full max-w-xs sm:h-72 lg:col-span-2">
            <canvas x-ref="contributionCanvas" role="img" aria-label="Pie chart kontribusi revenue per platform"></canvas>
        </div>

        <div class="overflow-hidden rounded-2xl border border-line bg-white/70 lg:col-span-3 dark:bg-white/40">
            <div class="max-h-[22rem] overflow-auto">
                <table class="w-full min-w-[560px] text-left text-sm">
                    <thead class="sticky top-0 bg-[#eaf0f8] text-[11px] font-bold tracking-widest text-label uppercase dark:bg-[#dbe3ee]">
                        <tr>
                            @foreach ($columns as $column)
                                <th scope="col" @class(['px-4 py-3.5', 'text-right' => ! $loop->first])>{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <template x-for="row in contributionTableRows" :key="row.key">
                            <tr class="transition hover:bg-soft/70">
                                <th scope="row" class="px-4 py-3 font-semibold text-ink">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="size-2.5 shrink-0 rounded-full" :style="{ backgroundColor: row.color }" aria-hidden="true"></span>
                                        <span x-text="row.name"></span>
                                    </span>
                                </th>
                                <td class="performance-value px-4 py-3 text-right font-semibold text-ink" x-text="row.revenue"></td>
                                <td class="performance-value px-4 py-3 text-right text-muted" x-text="row.transactions"></td>
                                <td class="performance-value px-4 py-3 text-right text-muted" x-text="row.users"></td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        <span class="hidden h-1.5 w-16 overflow-hidden rounded-full bg-soft sm:block" aria-hidden="true">
                                            <span class="block h-full rounded-full transition-[width] duration-700" :style="{ width: `${row.share}%`, backgroundColor: row.color }"></span>
                                        </span>
                                        <span class="performance-value w-14 text-right font-semibold text-ink" x-text="row.contribution"></span>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="border-t border-line bg-soft/70 text-ink">
                        <tr>
                            <th scope="row" class="px-4 py-3 text-xs font-bold tracking-widest uppercase">Total</th>
                            <td class="performance-value px-4 py-3 text-right font-bold" x-text="contributionTotalRow.revenue"></td>
                            <td class="performance-value px-4 py-3 text-right font-bold" x-text="contributionTotalRow.transactions"></td>
                            <td class="performance-value px-4 py-3 text-right font-bold" x-text="contributionTotalRow.users"></td>
                            <td class="px-4 py-3 text-right font-bold">100%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="border-t border-line px-4 py-2.5 text-[11px] text-label">User dihitung per platform; satu email yang bertransaksi di dua platform terhitung di keduanya.</p>
        </div>
    </div>
</div>
