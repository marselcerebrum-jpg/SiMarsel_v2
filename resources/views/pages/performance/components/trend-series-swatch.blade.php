{{--
    Series swatch for the trend legend and point detail: a solid line, a dashed line, or a bar.
    Variables: $pattern and $color (Alpine expressions).
--}}
<span class="inline-flex h-3 w-5 shrink-0 items-center" aria-hidden="true">
    <span x-show="{{ $pattern }} === 'solid'" class="block w-full border-t-2" :style="`border-color: ${ {{ $color }} }`"></span>
    <span x-show="{{ $pattern }} === 'dashed'" class="block w-full border-t-2 border-dashed" :style="`border-color: ${ {{ $color }} }`"></span>
    <span x-show="{{ $pattern }} === 'bar'" class="mx-auto block h-3 w-2.5 rounded-sm" :style="`background-color: ${ {{ $color }} }`"></span>
</span>
