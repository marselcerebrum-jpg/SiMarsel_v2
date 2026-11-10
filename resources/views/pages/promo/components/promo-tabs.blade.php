{{-- Main tabs of the promo page. Variables: $id (id prefix), $label (accessible name), $tabs (key => label). Used inside promoTabs. --}}
<div class="mt-6 border-b border-line">
    <div
        role="tablist"
        aria-label="{{ $label }}"
        class="-mb-px flex gap-6 overflow-x-auto"
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
                class="inline-flex min-h-11 items-center border-b-2 px-1 text-sm font-semibold whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                :class="tab === @js($key) ? 'border-primary text-primary' : 'border-transparent text-muted hover:text-ink'"
                @click="select(@js($key))"
            >{{ $tabLabel }}</button>
        @endforeach
    </div>
</div>
