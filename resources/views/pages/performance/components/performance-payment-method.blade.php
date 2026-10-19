@php
    $columns = [
        ['label' => 'Payment Method', 'class' => ''],
        ['label' => 'Transactions', 'class' => 'text-right'],
        ['label' => 'Revenue', 'class' => 'text-right'],
        ['label' => 'Contribution (%)', 'class' => 'text-right'],
    ];

    $group = 'inline-flex max-w-full shrink-0 items-center gap-1 overflow-x-auto rounded-2xl border border-line bg-soft p-1.5';
    $pill = 'inline-flex h-10 shrink-0 items-center gap-1.5 rounded-xl px-4 text-xs font-bold tracking-wider uppercase transition duration-200 focus-visible:outline-2 focus-visible:outline-brand-indigo';
    $pillActive = "'bg-linear-135 from-brand-indigo via-brand-blue to-brand-teal text-white shadow-md shadow-brand-indigo/30'";
    $pillIdle = "'text-muted hover:bg-white hover:text-brand-indigo'";
    $dot = 'size-3 shrink-0 rounded-full';
@endphp

<span class="absolute top-0 left-6 h-1 w-16 rounded-b-full bg-linear-to-r from-brand-indigo to-brand-teal" aria-hidden="true"></span>

<div
    x-data="performancePaymentMethod(@js($paymentMethod['parameters']))"
    @performance-filter.window="loadPayment($event.detail)"
    :aria-busy="paymentLoading.toString()"
>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h2 id="performance-payment-method-title" class="text-2xl leading-tight font-extrabold tracking-tight text-ink">Payment Methods</h2>
            <p class="mt-1.5 text-sm text-muted">Distribusi metode pembayaran beserta jumlah transaksi, revenue, dan kontribusinya sesuai filter aktif.</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <p x-show="paymentLoading && paymentState === 'ready'" x-cloak class="inline-flex items-center gap-2 text-xs font-semibold text-label" role="status">
                <span class="size-3.5 animate-spin rounded-full border-2 border-brand-indigo/25 border-t-brand-indigo" aria-hidden="true"></span>
                Memuat data…
            </p>

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

    <div class="mt-6 border-t border-line"></div>

    {{-- Loading --}}
    <div x-show="paymentState === 'loading'" class="grid gap-6 pt-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:items-center" aria-hidden="true">
        <span class="performance-shimmer mx-auto block size-56 rounded-full sm:size-64"></span>
        <div class="space-y-3">
            @foreach (range(1, 4) as $row)
                <span class="performance-shimmer block h-12 rounded-xl"></span>
            @endforeach
        </div>
    </div>

    {{-- Empty --}}
    <div x-show="paymentState === 'empty'" x-cloak class="grid min-h-56 place-items-center pt-6 text-center" role="status">
        <div class="flex max-w-md flex-col items-center gap-2 text-label">
            <x-icon name="chart" class="size-8" />
            <p class="text-sm" x-text="paymentMessage"></p>
        </div>
    </div>

    {{-- Backend not integrated yet: blurred outline without numbers --}}
    <div x-show="paymentState === 'unavailable'" x-cloak class="relative pt-6">
        <div class="pointer-events-none grid gap-6 blur-[3px] select-none lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:items-center" aria-hidden="true">
            <span class="mx-auto block size-56 rounded-full bg-conic from-brand-indigo/30 via-brand-teal/30 to-brand-indigo/30 sm:size-64"></span>
            <div class="space-y-3">
                @foreach (range(1, 4) as $row)
                    <div class="flex items-center gap-3 rounded-xl border border-line px-4 py-3.5">
                        <span class="{{ $dot }} bg-brand-indigo/30"></span>
                        <span class="h-3 flex-1 rounded bg-line"></span>
                        <span class="h-3 w-12 rounded bg-soft"></span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="absolute inset-0 grid place-items-center p-4">
            <div class="max-w-sm rounded-2xl border border-line bg-white/90 px-5 py-4 text-center shadow-lg backdrop-blur" role="status">
                <span class="mx-auto grid size-10 place-items-center rounded-xl bg-brand-indigo/10 text-brand-indigo">
                    <x-icon name="clipboard" class="size-5" />
                </span>
                <p class="mt-2 text-sm font-bold text-ink">Data belum tersedia</p>
                <p class="mt-1 text-[13px] text-muted">Data akan tampil otomatis setelah API backend terhubung.</p>
            </div>
        </div>
    </div>

    {{-- Error --}}
    <div x-show="paymentState === 'error'" x-cloak class="grid min-h-56 place-items-center pt-6 text-center" role="alert">
        <div class="flex flex-col items-center gap-3">
            <span class="grid size-12 place-items-center rounded-2xl bg-red-50 text-red-600">
                <x-icon name="chart" class="size-6" />
            </span>
            <p class="text-sm font-semibold text-red-600" x-text="paymentMessage"></p>
            <button
                type="button"
                class="inline-flex h-10 items-center gap-2 rounded-xl border border-line bg-white px-4 text-xs font-bold tracking-wider text-brand-indigo uppercase transition hover:bg-soft focus-visible:outline-2 focus-visible:outline-brand-indigo"
                @click="retryPayment()"
            >
                <x-icon name="repeat" class="size-4" />
                Reload
            </button>
        </div>
    </div>

    {{-- Pie chart + legend --}}
    <div
        x-show="paymentState === 'ready'"
        x-cloak
        class="grid gap-6 pt-6 transition-opacity lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:items-center"
        :class="paymentLoading && 'opacity-60'"
    >
        <div class="relative mx-auto h-64 w-full max-w-sm sm:h-72">
            <canvas x-ref="paymentCanvas" role="img" :aria-label="paymentChartLabel"></canvas>
        </div>

        <div class="overflow-hidden rounded-2xl border border-line bg-white/70 dark:bg-white/40">
            {{-- Table: tablet and larger --}}
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[520px] text-left text-sm">
                    <caption class="sr-only">Distribusi transaksi dan revenue per metode pembayaran</caption>
                    <thead class="bg-[#eaf0f8] text-[11px] font-bold tracking-widest text-label uppercase dark:bg-[#dbe3ee]">
                        <tr>
                            @foreach ($columns as $column)
                                <th scope="col" class="px-4 py-3.5 {{ $column['class'] }}">{{ $column['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <template x-for="(row, index) in paymentRows" :key="row.key">
                            <tr
                                class="transition hover:bg-soft/70 focus-visible:bg-soft/70 focus-visible:outline-none"
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
                    <li class="space-y-2 px-4 py-3.5" @click="highlightPayment(index)">
                        <div class="flex items-center gap-3">
                            <span class="{{ $dot }}" :style="{ backgroundColor: row.color }" aria-hidden="true"></span>
                            <span class="min-w-0 flex-1 font-semibold break-words text-ink" x-text="row.name"></span>
                            <span class="performance-value shrink-0 text-sm font-bold text-ink" x-text="row.percentage"></span>
                        </div>
                        <dl class="grid grid-cols-2 gap-x-4 pl-6 text-sm">
                            <div>
                                <dt class="text-[11px] font-bold tracking-widest text-label uppercase">Transactions</dt>
                                <dd class="performance-value text-muted" x-text="row.transactions"></dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-bold tracking-widest text-label uppercase">Revenue</dt>
                                <dd class="performance-value text-muted" x-text="row.revenue"></dd>
                            </div>
                        </dl>
                    </li>
                </template>
                <li class="bg-soft/60 px-4 py-3.5">
                    <dl class="grid grid-cols-2 gap-x-4 text-sm font-bold text-ink">
                        <div>
                            <dt class="text-[11px] tracking-widest text-label uppercase">Total Transactions</dt>
                            <dd class="performance-value" x-text="paymentTotals.transactions"></dd>
                        </div>
                        <div>
                            <dt class="text-[11px] tracking-widest text-label uppercase">Total Revenue</dt>
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
