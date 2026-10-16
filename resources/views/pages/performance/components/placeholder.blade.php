{{-- Bentuk kerangka tanpa angka; dipakai untuk loading dan kondisi data belum tersedia. --}}
@switch($type)
    @case('bars-x')
        <div class="space-y-4 py-2">
            @foreach ([92, 78, 64, 52, 40, 28] as $width)
                <div class="flex items-center gap-3">
                    <span class="h-3 w-28 shrink-0 rounded bg-line"></span>
                    <span class="h-7 rounded-lg bg-brand-indigo/25" style="width: {{ $width }}%"></span>
                </div>
            @endforeach
        </div>
        @break

    @case('bars-y')
        <div class="flex h-72 items-end gap-3 border-b border-line px-2 sm:gap-5">
            @foreach ([88, 74, 62, 50, 41, 30, 22] as $height)
                <span class="flex-1 rounded-t-lg bg-brand-blue/25" style="height: {{ $height }}%"></span>
            @endforeach
        </div>
        <div class="mt-3 flex gap-3 px-2 sm:gap-5">
            @foreach (range(1, 7) as $i)
                <span class="h-3 flex-1 rounded bg-line"></span>
            @endforeach
        </div>
        @break

    @case('table')
        <div class="space-y-3 py-2">
            @foreach (range(1, 6) as $i)
                <div class="grid grid-cols-[2fr_repeat(4,1fr)] gap-4">
                    <span class="h-4 rounded bg-line"></span>
                    @foreach (range(1, 4) as $j)
                        <span class="h-4 rounded bg-soft"></span>
                    @endforeach
                </div>
            @endforeach
        </div>
        @break
@endswitch
