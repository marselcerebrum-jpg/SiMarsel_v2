{{-- Variabel: $component, $id, $eyebrow, $title, $icon, $placeholder, $emptyText, $chartLabel --}}
<section x-data="{{ $component }}" class="{{ $card }} min-w-0 p-5 sm:p-8" aria-labelledby="{{ $id }}">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        @include('pages.performance.components.card-header')

        <p x-show="!loading && status === 'ready'" x-cloak class="shrink-0 text-xs text-muted sm:pt-1" aria-live="polite">
            <span class="font-semibold text-ink" x-text="items.length"></span> paket
        </p>
    </div>

    <div class="relative mt-6" :aria-busy="loading.toString()">
        <div x-show="status === 'ready'" x-cloak class="relative" :style="{ height: chartHeight }">
            <canvas x-ref="canvas" role="img" aria-label="{{ $chartLabel }}"></canvas>

            {{-- Loading saat filter berubah: overlay di atas chart lama --}}
            <div x-show="loading" x-transition.opacity class="absolute inset-0 grid place-items-center rounded-2xl bg-surface/70 backdrop-blur-[1px]">
                <span class="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 text-xs font-semibold text-muted shadow">
                    <span class="size-3.5 animate-spin rounded-full border-2 border-brand-indigo border-t-transparent"></span>
                    Memuat data…
                </span>
            </div>
        </div>

        @include('pages.performance.components.card-states')
    </div>
</section>
