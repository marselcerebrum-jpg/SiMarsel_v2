{{-- Segmented subtabs inside a tab. Variables: $id (id prefix), $label (accessible name), $tabs (key => label). Used inside promoTabs. --}}
<div
    role="tablist"
    aria-label="{{ $label }}"
    class="flex max-w-full gap-1 overflow-x-auto rounded-lg border border-line bg-soft p-1"
    @keydown.arrow-right.prevent="move(1)"
    @keydown.arrow-left.prevent="move(-1)"
>
    @foreach ($tabs as $key => $tabLabel)
        <button
            type="button"
            role="tab"
            id="tab-{{ $id }}-{{ $key }}"
            aria-controls="panel-{{ $id }}-{{ $key }}"
            :aria-selected="(tab === @js($key)).toString()"
            :tabindex="tab === @js($key) ? 0 : -1"
            class="inline-flex min-h-11 shrink-0 items-center rounded-md px-3 text-sm font-semibold whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            :class="tab === @js($key) ? 'bg-white text-primary shadow-sm ring-1 ring-line' : 'text-muted hover:text-ink'"
            @click="select(@js($key))"
        >{{ $tabLabel }}</button>
    @endforeach
</div>
