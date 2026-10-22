@php
    $columns = [
        ['label' => 'Platform', 'class' => ''],
        ['label' => 'Revenue', 'class' => 'text-right'],
        ['label' => 'Transactions', 'class' => 'text-right'],
        ['label' => 'Users', 'class' => 'text-right'],
        ['label' => 'Contribution (%)', 'class' => 'w-[34%] min-w-56'],
    ];

    $tile = 'rounded-lg border border-line px-4 py-3';
    $rank = 'inline-grid size-7 shrink-0 place-items-center rounded-md bg-primary-soft text-xs font-semibold text-primary-hover';
@endphp

<div
    x-data="performanceContribution"
    @performance-filter.window="loadContribution($event.detail)"
    @scroll.window.passive="hideTip()"
    @resize.window="hideTip()"
    :aria-busy="contributionLoading.toString()"
>
    <div>
        <h2 id="performance-contribution-title" class="text-base font-semibold text-ink">Platform Contribution</h2>
        <p class="mt-1 text-sm text-muted">Peringkat kontribusi revenue setiap platform beserta jumlah transaksi dan user sesuai filter aktif.</p>
    </div>

    <div class="mt-5 border-t border-line"></div>

    {{-- Loading --}}
    <div x-show="contributionState === 'loading'" class="space-y-4 pt-5">
        <div class="grid gap-3 sm:grid-cols-2" aria-hidden="true">
            @foreach (range(1, 2) as $tileIndex)
                @include('pages.performance.components.loading-shimmer', ['class' => 'h-20 rounded-lg'])
            @endforeach
        </div>
        <div class="relative h-56 rounded-lg border border-line">
            @include('pages.performance.components.loading-spinner', ['show' => 'true'])
        </div>
    </div>

    {{-- Empty --}}
    <div x-show="contributionState === 'empty'" x-cloak class="grid min-h-56 place-items-center pt-5 text-center" role="status">
        <div class="flex flex-col items-center gap-2 text-label">
            <p class="text-sm" x-text="contributionMessage"></p>
        </div>
    </div>

    {{-- Error --}}
    <div x-show="contributionState === 'error'" x-cloak class="grid min-h-56 place-items-center pt-5 text-center" role="alert">
        <div class="flex flex-col items-center gap-3">
            <p class="text-sm font-semibold text-red-600" x-text="contributionMessage"></p>
            <button
                type="button"
                class="inline-flex h-9 items-center justify-center rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                @click="retryContribution()"
            >
                Reload
            </button>
        </div>
    </div>

    {{-- Summary + ranking --}}
    <div x-show="contributionState === 'ready'" x-cloak class="pt-5">
        <dl class="grid gap-3 sm:grid-cols-2">
            <div class="{{ $tile }}">
                <dt class="text-xs font-semibold text-label">Total Revenue</dt>
                <dd x-show="!contributionLoading" class="performance-value mt-1 text-xl font-bold text-ink" x-text="contributionRevenue"></dd>
                <dd x-show="contributionLoading" x-cloak class="mt-1.5">
                    @include('pages.performance.components.loading-shimmer', ['class' => 'h-6 w-32 rounded-md'])
                </dd>
                <dd class="mt-0.5 text-xs text-muted">Akumulasi revenue seluruh platform pada filter aktif</dd>
            </div>
            <div class="{{ $tile }}">
                <dt class="text-xs font-semibold text-label">Platforms</dt>
                <dd x-show="!contributionLoading" class="performance-value mt-1 text-xl font-bold text-ink" x-text="contributionPlatformCount"></dd>
                <dd x-show="contributionLoading" x-cloak class="mt-1.5">
                    @include('pages.performance.components.loading-shimmer', ['class' => 'h-6 w-32 rounded-md'])
                </dd>
                <dd class="mt-0.5 text-xs text-muted">Jumlah platform yang tercakup dalam hasil filter</dd>
            </div>
        </dl>

        <div class="relative mt-4 overflow-hidden rounded-lg border border-line">
            @include('pages.performance.components.loading-spinner', ['show' => 'contributionLoading'])

            {{-- Table: tablet and larger --}}
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <caption class="sr-only">Ranking kontribusi revenue per platform</caption>
                    <thead class="bg-soft text-xs font-semibold text-label">
                        <tr>
                            @foreach ($columns as $column)
                                <th scope="col" class="px-4 py-3 {{ $column['class'] }}">{{ $column['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <template x-for="row in contributionTableRows" :key="row.key">
                            <tr class="transition hover:bg-soft/60">
                                <th scope="row" class="px-4 py-3 font-semibold text-ink">
                                    <span class="inline-flex items-center gap-3">
                                        <span class="{{ $rank }}" x-text="row.rank" :aria-label="`Rank ${row.rank}`"></span>
                                        <span x-text="row.name"></span>
                                    </span>
                                </th>
                                <td class="performance-value px-4 py-3 text-right font-semibold text-ink" x-text="row.revenue"></td>
                                <td class="performance-value px-4 py-3 text-right text-muted" x-text="row.transactions"></td>
                                <td class="performance-value px-4 py-3 text-right text-muted" x-text="row.users"></td>
                                <td class="px-4 py-3">
                                    @include('pages.performance.components.performance-contribution-bar')
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            {{-- Ranking list: phones --}}
            <ol class="divide-y divide-line md:hidden" aria-label="Ranking kontribusi revenue per platform">
                <template x-for="row in contributionTableRows" :key="row.key">
                    <li class="space-y-3 px-4 py-4">
                        <div class="flex items-center gap-3">
                            <span class="{{ $rank }}" x-text="row.rank"></span>
                            <span class="min-w-0 font-semibold break-words text-ink" x-text="row.name"></span>
                        </div>
                        @include('pages.performance.components.performance-contribution-bar')
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <div class="col-span-2">
                                <dt class="text-xs font-semibold text-label">Revenue</dt>
                                <dd class="performance-value font-semibold text-ink" x-text="row.revenue"></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold text-label">Transactions</dt>
                                <dd class="performance-value text-muted" x-text="row.transactions"></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold text-label">Users</dt>
                                <dd class="performance-value text-muted" x-text="row.users"></dd>
                            </div>
                        </dl>
                    </li>
                </template>
            </ol>

            <p class="border-t border-line px-4 py-2.5 text-[11px] text-label">User dihitung per platform; satu email yang bertransaksi di dua platform terhitung di keduanya.</p>
        </div>
    </div>

    {{-- Progress bar tooltip --}}
    <template x-teleport="body">
        <div
            x-show="tip"
            x-cloak
            role="tooltip"
            class="pointer-events-none fixed z-50 w-max max-w-64 -translate-x-1/2 -translate-y-full rounded-lg bg-navy px-3 py-2 text-xs text-slate-200 shadow-lg"
            :style="{ left: `${tip?.x ?? 0}px`, top: `${(tip?.y ?? 0) - 8}px` }"
        >
            <p class="font-semibold text-white" x-text="tip?.name"></p>
            <p class="mt-1">Total Revenue: <span class="performance-value font-semibold text-white" x-text="tip?.revenue"></span></p>
            <p>Contribution: <span class="performance-value font-semibold text-white" x-text="tip?.contribution"></span></p>
        </div>
    </template>
</div>
