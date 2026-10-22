{{-- Round spinner over a table while it loads. Variable: $show (Alpine expression). The parent needs `relative`. --}}
<div
    x-show="{{ $show }}"
    x-transition.opacity
    class="absolute inset-0 z-10 grid place-items-center bg-surface/70 backdrop-blur-[1px]"
    role="status"
>
    <span class="flex flex-col items-center gap-2 text-xs font-semibold text-label">
        <span class="size-8 animate-spin rounded-full border-[3px] border-primary/20 border-t-primary motion-reduce:animate-none" aria-hidden="true"></span>
        Memuat data…
    </span>
</div>
