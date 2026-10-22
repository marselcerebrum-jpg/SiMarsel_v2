{{-- Kondisi data Board/Table selain siap tampil. Variabel: $skeleton (board|table). --}}
<div x-show="loading && status !== 'ready'" aria-label="Memuat tugas" class="animate-pulse">
    @if ($skeleton === 'board')
        <div class="grid grid-flow-col auto-cols-[minmax(240px,1fr)] gap-3 overflow-hidden">
            @foreach (range(1, 6) as $column)
                <div class="flex flex-col gap-2 rounded-panel bg-soft p-3">
                    <div class="h-4 w-24 rounded bg-line"></div>
                    @foreach (range(1, 3) as $row)
                        <div class="h-24 rounded-lg bg-white"></div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @else
        <div class="flex flex-col gap-2">
            @foreach (range(1, 6) as $row)
                <div class="h-11 rounded-lg bg-soft"></div>
            @endforeach
        </div>
    @endif
</div>

<div x-show="!loading && status === 'unavailable'" x-cloak class="relative">
    <div class="pointer-events-none opacity-40 select-none" aria-hidden="true">
        @if ($skeleton === 'board')
            <div class="grid grid-flow-col auto-cols-[minmax(240px,1fr)] gap-3 overflow-hidden">
                <template x-for="item in statuses" :key="item.key">
                    <div class="flex h-72 flex-col gap-2 rounded-panel bg-soft p-3">
                        <span class="inline-flex items-center gap-2 text-sm font-semibold text-ink">
                            <span class="size-2 rounded-full" :class="item.dot"></span><span x-text="item.label"></span>
                        </span>
                        <div class="h-20 rounded-lg border border-dashed border-line bg-white"></div>
                    </div>
                </template>
            </div>
        @else
            <div class="flex flex-col gap-2">
                @foreach (range(1, 6) as $row)
                    <div class="h-11 rounded-lg bg-soft"></div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="absolute inset-0 grid place-items-center p-4">
        <div class="max-w-sm rounded-lg border border-amber-200 bg-white px-5 py-4 text-center shadow-sm" role="status">
            <p class="text-sm font-semibold text-ink">Daftar tugas menunggu pengerjaan Back End</p>
            <p class="mt-1 text-sm text-muted">Tugas akan tampil otomatis setelah API daftar tugas tersedia.</p>
        </div>
    </div>
</div>

<div x-show="!loading && status === 'error'" x-cloak class="flex flex-col items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-10 text-center" role="alert">
    <p class="text-sm text-red-700" x-text="error"></p>
    <button type="button" class="{{ $button }}" @click="load()">Try Again</button>
</div>

<div x-show="!loading && emptyKind === 'none'" x-cloak class="flex flex-col items-center gap-1 rounded-lg border border-dashed border-line bg-soft px-4 py-12 text-center">
    <p class="text-sm font-semibold text-ink">Belum ada tugas yang tersedia.</p>
    <p x-show="canCreate" class="text-sm text-muted">Buat tugas pertama melalui tombol Create Task.</p>
</div>

<div x-show="emptyKind === 'filtered'" x-cloak class="flex flex-col items-center gap-3 rounded-lg border border-dashed border-line bg-soft px-4 py-12 text-center">
    <p class="text-sm font-semibold text-ink">Tidak ada tugas yang sesuai dengan filter.</p>
    <button type="button" class="{{ $button }}" @click="resetFilters()">Reset Filter</button>
</div>
