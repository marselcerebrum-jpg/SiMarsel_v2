{{-- Variabel: $component, $id, $title, $placeholder, $emptyText, $chartLabel --}}
<section x-data="{{ $component }}" class="{{ $card }} min-w-0 p-5" aria-labelledby="{{ $id }}">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        @include('pages.performance.components.card-header')

        <p x-show="!loading && status === 'ready'" x-cloak class="shrink-0 text-xs text-muted sm:pt-1" aria-live="polite">
            <span class="performance-value font-semibold text-ink" x-text="items.length"></span> paket
        </p>
    </div>

    <div class="relative mt-5" :aria-busy="loading.toString()">
        <div x-show="status === 'ready'" x-cloak class="relative h-[480px]">
            <canvas x-ref="canvas" role="img" aria-label="{{ $chartLabel }}"></canvas>

            {{-- Loading saat filter berubah: overlay di atas chart lama --}}
            <div x-show="loading" x-transition.opacity class="absolute inset-0 grid place-items-center rounded-lg bg-surface/80">
                <span class="inline-flex items-center gap-2 rounded-lg border border-line bg-white px-3 py-1.5 text-xs font-semibold text-muted shadow-sm">
                    <span class="size-3.5 animate-spin rounded-full border-2 border-primary border-t-transparent"></span>
                    Memuat data…
                </span>
            </div>
        </div>

        @include('pages.performance.components.card-states')
    </div>
</section>
