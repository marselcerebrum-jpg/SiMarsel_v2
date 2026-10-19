<div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center">
    <label class="relative block w-full sm:max-w-xs">
        <span class="sr-only">Search Accounts</span>
        <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-label" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7" />
            <path d="M20 20l-3.5-3.5" />
        </svg>
        <input
            type="search"
            x-model="search"
            placeholder="Cari nama atau username…"
            class="h-9 w-full rounded-lg border border-line bg-white pr-3 pl-9 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
        >
    </label>

    <label class="relative block w-full sm:w-52">
        <span class="sr-only">Filter Division</span>
        <select
            x-model="filterDivision"
            class="h-9 w-full appearance-none rounded-lg border border-line bg-white px-3 pr-9 text-sm text-ink focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
        >
            <option value="">All Divisions</option>
            <template x-for="division in divisions" :key="division.id">
                <option :value="division.code_division" x-text="division.name"></option>
            </template>
        </select>
        <svg class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-label" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M6 9l6 6 6-6" />
        </svg>
    </label>

    <p class="text-xs text-muted sm:ml-auto" aria-live="polite">
        Menampilkan <span class="font-semibold text-ink" x-text="filteredUsers.length"></span>
        dari <span x-text="users.length"></span> akun
    </p>
</div>
