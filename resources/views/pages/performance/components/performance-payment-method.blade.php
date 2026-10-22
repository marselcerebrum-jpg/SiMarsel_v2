@php
    $columns = [
        ['label' => 'Payment Method', 'class' => ''],
        ['label' => 'Transactions', 'class' => 'text-right'],
        ['label' => 'Revenue', 'class' => 'text-right'],
        ['label' => 'Contribution (%)', 'class' => 'text-right'],
    ];

    $group = 'inline-flex h-9 max-w-full shrink-0 items-center gap-0.5 overflow-x-auto rounded-lg border border-line bg-soft p-0.5';
    $pill = 'inline-flex h-8 shrink-0 items-center rounded-md px-3 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-40';
    $pillActive = "'bg-white text-ink shadow-sm'";
    $pillIdle = "'text-muted hover:text-ink'";
    $dot = 'size-3 shrink-0 rounded-full';
@endphp

<div
    x-data="performancePaymentMethod(@js($paymentMethod['parameters']))"
    @performance-filter.window="loadPayment($event.detail)"
    :aria-busy="paymentLoading.toString()"
>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h2 id="performance-payment-method-title" class="text-base font-semibold text-ink">Payment Methods</h2>
            <p class="mt-1 text-sm text-muted">Distribusi metode pembayaran beserta jumlah transaksi, revenue, dan kontribusinya sesuai filter aktif.</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <div class="{{ $group }}" role="radiogroup" aria-label="Payment Method Parameter">
                @foreach ($paymentMethod['parameters'] as $option)
                    <button
                        type="button"
                        role="radio"
                        class="{{ $pill }}"
                        :class="paymentParameter === @js($option['value']) ? {{ $pillActive }} : {{ $pillIdle }}"
                        :aria-checked="(paymentParameter === @js($option['value'])).toString()"
                        @click="setPaymentParameter(@js($option['value']))"
                    >{{ $option['label'] }}</button>
                @endforeach
            </div>
        </div>
    </div>

    <div class="mt-5 border-t border-line"></div>

    {{-- Loading --}}
    <div x-show="paymentState === 'loading'" class="grid gap-6 pt-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:items-center">
        <span class="mx-auto block size-56 animate-pulse rounded-full bg-soft sm:size-64" aria-hidden="true"></span>
        <div class="relative h-56 rounded-lg border border-line">
            @include('pages.performance.components.loading-spinner', ['show' => 'true'])
        </div>
    </div>

    {{-- Empty --}}
    <div x-show="paymentState === 'empty'" x-cloak class="grid min-h-56 place-items-center pt-5 text-center" role="status">
        <div class="flex max-w-md flex-col items-center gap-2 text-label">
            <p class="text-sm" x-text="paymentMessage"></p>
        </div>
    </div>

    {{-- Error --}}
    <div x-show="paymentState === 'error'" x-cloak class="grid min-h-56 place-items-center pt-5 text-center" role="alert">
        <div class="flex flex-col items-center gap-3">
            <p class="text-sm font-semibold text-red-600" x-text="paymentMessage"></p>
            <button
                type="button"
                class="inline-flex h-9 items-center justify-center rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                @click="retryPayment()"
            >
                Reload
            </button>
        </div>
    </div>

    {{-- Pie chart + legend --}}
    <div
        x-show="paymentState === 'ready'"
        x-cloak
        class="grid gap-6 pt-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:items-center"
    >
        <div class="relative mx-auto h-64 w-full max-w-sm transition-opacity sm:h-72" :class="paymentLoading && 'opacity-50'">
            <canvas x-ref="paymentCanvas" role="img" :aria-label="paymentChartLabel"></canvas>
        </div>

        <div class="relative overflow-hidden rounded-lg border border-line">
            @include('pages.performance.components.loading-spinner', ['show' => 'paymentLoading'])

            {{-- Table: tablet and larger --}}
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[520px] text-left text-sm">
                    <caption class="sr-only">Distribusi transaksi dan revenue per metode pembayaran</caption>
                    <thead class="bg-soft text-xs font-semibold text-label">
                        <tr>
                            @foreach ($columns as $column)
                                <th scope="col" class="px-4 py-3 {{ $column['class'] }}">{{ $column['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <template x-for="(row, index) in paymentRows" :key="row.key">
                            <tr
                                class="transition hover:bg-soft/60 focus-visible:bg-soft/70 focus-visible:outline-none"
                                tabindex="0"
                                @mouseenter="highlightPayment(index)"
                                @mouseleave="highlightPayment(null)"
                                @focus="highlightPayment(index)"
                                @blur="highlightPayment(null)"
                            >
                                <th scope="row" class="px-4 py-3 font-semibold text-ink">
                                    <span class="inline-flex items-center gap-3">
                                        <span class="{{ $dot }}" :style="{ backgroundColor: row.color }" aria-hidden="true"></span>
                                        <span class="break-words" x-text="row.name"></span>
                                    </span>
                                </th>
                                <td class="performance-value px-4 py-3 text-right text-muted" x-text="row.transactions"></td>
                                <td class="performance-value px-4 py-3 text-right text-muted" x-text="row.revenue"></td>
                                <td class="performance-value px-4 py-3 text-right font-bold text-ink" x-text="row.percentage"></td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="border-t border-line bg-soft/60 font-bold text-ink">
                        <tr>
                            <th scope="row" class="px-4 py-3">Total</th>
                            <td class="performance-value px-4 py-3 text-right" x-text="paymentTotals.transactions"></td>
                            <td class="performance-value px-4 py-3 text-right" x-text="paymentTotals.revenue"></td>
                            <td class="performance-value px-4 py-3 text-right">100%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- Legend list: phones --}}
            <ul class="divide-y divide-line md:hidden" aria-label="Distribusi metode pembayaran">
                <template x-for="(row, index) in paymentRows" :key="row.key">
                    <li class="space-y-2 px-4 py-3" @click="highlightPayment(index)">
                        <div class="flex items-center gap-3">
                            <span class="{{ $dot }}" :style="{ backgroundColor: row.color }" aria-hidden="true"></span>
                            <span class="min-w-0 flex-1 font-semibold break-words text-ink" x-text="row.name"></span>
                            <span class="performance-value shrink-0 text-sm font-bold text-ink" x-text="row.percentage"></span>
                        </div>
                        <dl class="grid grid-cols-2 gap-x-4 pl-6 text-sm">
                            <div>
                                <dt class="text-xs font-semibold text-label">Transactions</dt>
                                <dd class="performance-value text-muted" x-text="row.transactions"></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold text-label">Revenue</dt>
                                <dd class="performance-value text-muted" x-text="row.revenue"></dd>
                            </div>
                        </dl>
                    </li>
                </template>
                <li class="bg-soft/60 px-4 py-3">
                    <dl class="grid grid-cols-2 gap-x-4 text-sm font-bold text-ink">
                        <div>
                            <dt class="text-xs text-label">Total Transactions</dt>
                            <dd class="performance-value" x-text="paymentTotals.transactions"></dd>
                        </div>
                        <div>
                            <dt class="text-xs text-label">Total Revenue</dt>
                            <dd class="performance-value" x-text="paymentTotals.revenue"></dd>
                        </div>
                    </dl>
                </li>
            </ul>

            <p class="border-t border-line px-4 py-2.5 text-[11px] text-label">
                Kontribusi dihitung dari <span class="font-semibold" x-text="paymentMeta.label"></span> pada filter aktif. Karena pembulatan, total persentase bisa sedikit berbeda dari 100%.
            </p>
        </div>
    </div>
</div>
