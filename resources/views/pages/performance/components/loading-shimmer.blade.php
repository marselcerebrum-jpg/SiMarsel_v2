{{-- Placeholder bar with a sheen sweeping across it, shown in place of a card value while it loads. Variable: $class (size and radius). --}}
<span class="relative block overflow-hidden bg-line/70 {{ $class }}" aria-hidden="true">
    <span class="absolute inset-0 -translate-x-full animate-shimmer bg-linear-to-r from-transparent via-white/90 to-transparent motion-reduce:hidden"></span>
</span>
