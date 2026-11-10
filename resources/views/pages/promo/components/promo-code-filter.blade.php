<div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end">
    <div class="w-full sm:max-w-xs">
        <label for="promo-code-search" class="mb-1.5 block text-xs font-semibold text-label">Cari Kode Promo</label>
        <div class="relative">
            <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-label" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7" />
                <path d="M20 20l-3.5-3.5" />
            </svg>
            <input
                id="promo-code-search"
                type="search"
                x-model.debounce.400ms="filter.search"
                placeholder="Cari kode, platform, funnel, atau source…"
                maxlength="100"
                class="h-10 w-full rounded-lg border border-line bg-white pr-3 pl-9 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
            >
        </div>
    </div>

    <div class="w-full sm:w-48">
        <label for="promo-code-platform-filter" class="mb-1.5 block text-xs font-semibold text-label">Platform</label>
        <select
            id="promo-code-platform-filter"
            x-model="filter.platform_id"
            @change="reload()"
            class="h-10 w-full rounded-lg border border-line bg-white px-3 text-sm text-ink focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
        >
            <option value="">Semua Platform</option>
            <template x-for="platform in options.platforms" :key="platform.id">
                <option :value="String(platform.id)" x-text="platform.name"></option>
            </template>
        </select>
    </div>

    <p class="text-xs text-muted sm:ml-auto sm:pb-3" aria-live="polite" x-show="meta" x-cloak>
        Total <span class="font-semibold text-ink" x-text="meta?.total ?? 0"></span> kode promo
    </p>
</div>
