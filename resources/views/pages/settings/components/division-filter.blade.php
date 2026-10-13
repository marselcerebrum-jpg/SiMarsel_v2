<div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center">
    <label class="relative block w-full sm:max-w-xs">
        <span class="sr-only">Cari divisi</span>
        <svg class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-label" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7" />
            <path d="M20 20l-3.5-3.5" />
        </svg>
        <input
            type="search"
            x-model="search"
            placeholder="Cari kode atau nama divisi…"
            class="h-11 w-full rounded-xl border border-line bg-soft pr-4 pl-11 text-sm text-ink placeholder:text-label focus:border-brand-indigo focus:bg-white focus:ring-3 focus:ring-brand-indigo/15 focus:outline-none"
        >
    </label>

    <p class="text-xs text-muted sm:ml-auto" aria-live="polite">
        Menampilkan <span class="font-semibold text-ink" x-text="filteredDivisions.length"></span>
        dari <span x-text="divisions.length"></span> divisi
    </p>
</div>
