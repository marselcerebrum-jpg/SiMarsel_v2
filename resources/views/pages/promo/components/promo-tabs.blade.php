<div class="mt-6 border-b border-line">
    <div
        role="tablist"
        aria-label="Promo Management"
        class="-mb-px flex gap-6 overflow-x-auto"
        @keydown.arrow-right.prevent="move(1)"
        @keydown.arrow-left.prevent="move(-1)"
    >
        @foreach ($tabs as $key => $label)
            <button
                type="button"
                role="tab"
                id="tab-{{ $key }}"
                aria-controls="panel-{{ $key }}"
                :aria-selected="tab === @js($key)"
                :tabindex="tab === @js($key) ? 0 : -1"
                class="border-b-2 px-1 pb-3 text-sm font-semibold whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                :class="tab === @js($key) ? 'border-primary text-primary' : 'border-transparent text-muted hover:text-ink'"
                @click="select(@js($key))"
            >{{ $label }}</button>
        @endforeach
    </div>
</div>
