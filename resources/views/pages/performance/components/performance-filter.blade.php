{{--
    Global Dashboard filter controls and the resolved period (FIX-04-FE-01). Uses dashboardFilter.
    Rendered in the Filter section and in the compact header panel; both share one filter state.
    Variables: $prefix (unique id prefix per placement), $statusShown (Alpine expression; only one status region is live).
--}}
@php
    $id = fn (string $name) => "{$prefix}-{$name}";

    $label = 'mb-1.5 block text-xs font-semibold text-label';
    $control = 'h-9 w-full rounded-lg border border-line bg-white px-3 text-sm text-ink focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none';
    $chevron = 'pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-label';
    $menu = 'absolute z-30 mt-1 max-h-64 w-full min-w-48 overflow-y-auto rounded-lg border border-line bg-white p-1 shadow-lg';
    $option = 'flex h-9 cursor-pointer items-center gap-2.5 rounded-md px-2.5 text-sm text-ink hover:bg-soft focus-within:bg-soft';
    $checkbox = 'size-4 rounded border-line accent-primary';
    $error = 'mt-1.5 text-xs text-red-600';
    $rangeGroup = 'grid gap-4 rounded-lg border border-line p-4 sm:grid-cols-2';
    $legend = 'px-1 text-xs font-semibold text-ink';

    $dateFields = [
        'date_range' => [
            'show' => 'isDateRange',
            'legend' => 'Rentang Tanggal',
            'fields' => [
                ['key' => 'date_from', 'name' => 'start', 'label' => 'Tanggal Mulai'],
                ['key' => 'date_to', 'name' => 'end', 'label' => 'Tanggal Akhir'],
            ],
        ],
        'comparison' => [
            'show' => 'isCustomComparison',
            'legend' => 'Rentang Pembanding Khusus',
            'fields' => [
                ['key' => 'comparison_from', 'name' => 'comparison-start', 'label' => 'Tanggal Mulai Pembanding'],
                ['key' => 'comparison_to', 'name' => 'comparison-end', 'label' => 'Tanggal Akhir Pembanding'],
            ],
        ],
    ];
@endphp

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
    {{-- Platform: one, several, or all. Tab moves through the checkboxes, Space toggles, Escape closes. --}}
    <div
        x-data="{ open: false }"
        @click.outside="open = false"
        @keydown.escape="if (open) { open = false; $event.stopPropagation(); $refs.platformButton.focus(); }"
        class="relative"
    >
        <span id="{{ $id('platforms-label') }}" class="{{ $label }}">Platform</span>
        <button
            type="button"
            x-ref="platformButton"
            class="{{ $control }} relative truncate pr-9 text-left"
            :class="filterErrors.platforms && 'border-red-500'"
            aria-labelledby="{{ $id('platforms-label') }} {{ $id('platforms-value') }}"
            aria-controls="{{ $id('platforms-options') }}"
            aria-describedby="{{ $id('platforms-error') }}"
            :aria-expanded="open.toString()"
            :aria-invalid="Boolean(filterErrors.platforms).toString()"
            @click="open = !open"
        >
            <span id="{{ $id('platforms-value') }}" x-text="platformSummary">Semua Platform</span>
            <x-icon name="chevron-down" class="{{ $chevron }}" />
        </button>
        <fieldset id="{{ $id('platforms-options') }}" x-show="open" x-cloak class="{{ $menu }}">
            <legend class="sr-only">Pilih platform</legend>
            <label class="{{ $option }}">
                <input type="checkbox" class="{{ $checkbox }}" :checked="allPlatformsSelected" @change="selectAllPlatforms()">
                <span>Semua Platform</span>
            </label>
            <template x-for="platform in platforms" :key="platform.value">
                <label class="{{ $option }}">
                    <input type="checkbox" class="{{ $checkbox }}" :value="platform.value" :checked="isPlatformSelected(platform.value)" @change="togglePlatform(platform.value)">
                    <span x-text="platform.label"></span>
                </label>
            </template>
        </fieldset>
        <p id="{{ $id('platforms-error') }}" x-show="filterErrors.platforms" x-cloak x-text="filterErrors.platforms" class="{{ $error }}"></p>
    </div>

    {{-- Period preset --}}
    <div>
        <label for="{{ $id('preset') }}" class="{{ $label }}">Periode</label>
        <div class="relative">
            <select
                id="{{ $id('preset') }}"
                x-model="filter.preset"
                @change="changePreset()"
                class="{{ $control }} appearance-none pr-9"
                :class="filterErrors.preset && 'border-red-500'"
                aria-describedby="{{ $id('preset-error') }}"
                :aria-invalid="Boolean(filterErrors.preset).toString()"
            >
                <template x-for="preset in presets" :key="preset.value">
                    <option :value="preset.value" x-text="preset.label" :selected="preset.value === filter.preset"></option>
                </template>
            </select>
            <x-icon name="chevron-down" class="{{ $chevron }}" />
        </div>
        <p id="{{ $id('preset-error') }}" x-show="filterErrors.preset" x-cloak x-text="filterErrors.preset" class="{{ $error }}"></p>
    </div>

    {{-- Comparison: changes only the comparison period --}}
    <div>
        <label for="{{ $id('comparison') }}" class="{{ $label }}">Pembanding</label>
        <div class="relative">
            <select
                id="{{ $id('comparison') }}"
                x-model="filter.comparison"
                @change="changeComparison()"
                class="{{ $control }} appearance-none pr-9"
                :class="filterErrors.comparison && 'border-red-500'"
                aria-describedby="{{ $id('comparison-error') }}"
                :aria-invalid="Boolean(filterErrors.comparison).toString()"
            >
                <template x-for="comparison in comparisons" :key="comparison.value">
                    <option :value="comparison.value" x-text="comparison.label" :selected="comparison.value === filter.comparison"></option>
                </template>
            </select>
            <x-icon name="chevron-down" class="{{ $chevron }}" />
        </div>
        <p id="{{ $id('comparison-error') }}" x-show="filterErrors.comparison" x-cloak x-text="filterErrors.comparison" class="{{ $error }}"></p>
    </div>
</div>

{{-- Custom ranges: shown only for Rentang Tanggal and Rentang Pembanding Khusus. Typed values are never cleared. --}}
@foreach ($dateFields as $group)
    <template x-if="{{ $group['show'] }}">
        <fieldset class="mt-4 {{ $rangeGroup }}">
            <legend class="{{ $legend }}">{{ $group['legend'] }}</legend>
            @foreach ($group['fields'] as $field)
                <div>
                    <label for="{{ $id($field['name']) }}" class="{{ $label }}">{{ $field['label'] }}</label>
                    <input
                        id="{{ $id($field['name']) }}"
                        type="date"
                        x-model="filter.{{ $field['key'] }}"
                        @change="applyGlobalFilter()"
                        class="{{ $control }}"
                        :class="filterErrors.{{ $field['key'] }} && 'border-red-500'"
                        aria-describedby="{{ $id($field['name'].'-error') }}"
                        :aria-invalid="Boolean(filterErrors.{{ $field['key'] }}).toString()"
                    >
                    <p id="{{ $id($field['name'].'-error') }}" x-show="filterErrors.{{ $field['key'] }}" x-cloak x-text="filterErrors.{{ $field['key'] }}" class="{{ $error }}"></p>
                </div>
            @endforeach
        </fieldset>
    </template>
@endforeach

<p x-show="filterErrors.filter" x-cloak x-text="filterErrors.filter" class="{{ $error }}"></p>

{{-- Success or failure in text. Only one placement keeps a live region, so a change is announced once. --}}
<p
    role="status"
    aria-live="polite"
    x-text="{{ $statusShown }} ? filterStatus : ''"
    class="mt-4 text-xs empty:mt-0"
    :class="filterFailed ? 'text-red-600' : 'text-muted'"
></p>

{{-- Resolved period: every date, hour and boundary comes from the resolver response. --}}
<dl class="mt-4 grid gap-x-6 gap-y-3 border-t border-line pt-4 text-xs sm:grid-cols-2" :aria-busy="periodLoading.toString()">
    <div>
        <dt class="font-semibold text-label">Rentang aktif</dt>
        <dd class="mt-0.5 flex flex-wrap items-center gap-2 text-ink">
            <span x-text="periodInfo?.active ?? 'Memuat periode…'">Memuat periode…</span>
            <span
                x-show="periodInfo?.provisional"
                x-cloak
                x-text="periodInfo?.provisionalLabel"
                class="rounded-full border border-amber-300 bg-amber-50 px-2 py-0.5 font-semibold text-amber-800"
            ></span>
        </dd>
    </div>
    <div>
        <dt class="font-semibold text-label">Rentang pembanding</dt>
        <dd class="mt-0.5 text-ink" x-text="periodInfo?.comparison ?? 'Memuat periode…'">Memuat periode…</dd>
    </div>
    <div>
        <dt class="font-semibold text-label">Batas lengkap</dt>
        <dd class="mt-0.5 text-ink" x-text="periodInfo?.completeUntil ?? 'Memuat periode…'">Memuat periode…</dd>
    </div>
    <div>
        <dt class="font-semibold text-label">Catatan pembanding</dt>
        <dd class="mt-0.5 text-ink" x-text="periodInfo?.snapshot ?? 'Memuat periode…'">Memuat periode…</dd>
    </div>
    <template x-if="periodInfo?.change">
        <div class="sm:col-span-2">
            <dt class="font-semibold text-label">Rentang yang dipakai untuk perubahan</dt>
            <dd class="mt-0.5 text-ink">
                <span x-text="periodInfo.change.active"></span>
                <span class="text-muted">dibanding</span>
                <span x-text="periodInfo.change.comparison"></span>
            </dd>
        </div>
    </template>
    <template x-if="periodInfo?.durations">
        <div class="sm:col-span-2">
            <dt class="font-semibold text-label">Durasi</dt>
            <dd class="mt-0.5 text-ink">
                Aktif <span x-text="periodInfo.durations.active"></span>
                · Pembanding <span x-text="periodInfo.durations.comparison"></span>
            </dd>
        </div>
    </template>
</dl>
