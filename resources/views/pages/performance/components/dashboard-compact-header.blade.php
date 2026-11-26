{{-- Compact header shown after the page header scrolls away, plus the Filter panel it opens. Uses dashboardShell and dashboardFilter. --}}
<div class="sticky top-[65px] z-20 h-0 lg:top-0">
    <div
        x-show="compact"
        x-cloak
        x-transition.opacity
        class="flex items-center justify-between gap-3 rounded-panel border border-line bg-surface/95 px-4 py-2.5 shadow-md backdrop-blur"
        data-dashboard-compact-header
    >
        <div class="min-w-0">
            <p class="text-sm font-bold text-ink">Dashboard</p>
            <p class="truncate text-xs text-muted">
                <span class="sr-only">Periode aktif:</span>
                <span x-text="activeRangeLabel || 'Memuat periode…'">Memuat periode…</span>
            </p>
        </div>

        <button
            type="button"
            class="inline-flex h-9 shrink-0 items-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            aria-label="Buka panel filter dashboard"
            aria-haspopup="dialog"
            aria-controls="dashboard-filter-panel"
            :aria-expanded="filterPanelOpen.toString()"
            @click="openFilterPanel()"
        >
            <x-icon name="sliders" class="size-4" />
            Filter
        </button>
    </div>
</div>

<div x-show="filterPanelOpen" x-cloak class="fixed inset-0 z-50">
    <div class="absolute inset-0 bg-slate-900/50" x-transition.opacity @click="closeFilterPanel()"></div>

    <div
        id="dashboard-filter-panel"
        x-dialog="filterPanelOpen"
        role="dialog"
        aria-modal="true"
        aria-labelledby="dashboard-filter-panel-title"
        @keydown.escape.stop="closeFilterPanel()"
        class="absolute inset-x-0 bottom-0 max-h-[85vh] overflow-y-auto rounded-t-panel bg-surface p-5 shadow-xl sm:inset-x-auto sm:top-0 sm:right-0 sm:bottom-0 sm:max-h-none sm:w-96 sm:rounded-none"
    >
        <div class="flex items-center justify-between gap-3">
            <h2 id="dashboard-filter-panel-title" class="text-base font-semibold text-ink">Filter</h2>
            <button
                type="button"
                data-dialog-initial
                class="inline-grid size-9 place-items-center rounded-lg text-muted hover:bg-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                aria-label="Tutup panel filter"
                @click="closeFilterPanel()"
            >
                <x-icon name="close" class="size-5" />
            </button>
        </div>

        <div data-dashboard-slot="filter-panel" class="mt-4">
            @include('pages.performance.components.performance-filter', ['prefix' => 'panel-filter', 'statusShown' => 'filterPanelOpen'])
        </div>
    </div>
</div>
