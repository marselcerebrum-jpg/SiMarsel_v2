<div class="border-l-4 border-brand-indigo pl-4">
    <p class="text-[11px] font-bold tracking-[.2em] text-label uppercase">{{ $eyebrow }}</p>
    <h2 id="{{ $id }}" class="mt-1 flex items-center gap-2 text-xl font-bold tracking-tight text-ink">
        <x-icon :name="$icon" class="size-5 shrink-0 text-brand-indigo" />
        {{ $title }}
    </h2>
    <p class="mt-1 text-sm text-muted" x-text="periodLabel"></p>
</div>
