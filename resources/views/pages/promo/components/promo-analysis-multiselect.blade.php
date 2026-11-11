{{-- Checkbox dropdown of the analysis filter. Variable: $select (field, label, options, summary, empty). Uses the classes of promo-analysis-filter. --}}
<div x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open && ((open = false), $refs.trigger.focus())" class="relative">
    <span id="promo-analysis-{{ $select['field'] }}-label" class="{{ $label }}">{{ $select['label'] }}</span>
    <button
        type="button"
        x-ref="trigger"
        class="{{ $control }} relative truncate pr-9 text-left"
        :class="errors.{{ $select['field'] }} && 'border-red-500'"
        :disabled="optionsLoading"
        aria-labelledby="promo-analysis-{{ $select['field'] }}-label promo-analysis-{{ $select['field'] }}-value"
        aria-haspopup="listbox"
        :aria-expanded="open.toString()"
        :aria-invalid="Boolean(errors.{{ $select['field'] }}).toString()"
        aria-describedby="promo-analysis-{{ $select['field'] }}-error"
        @click="open = !open"
    >
        <span id="promo-analysis-{{ $select['field'] }}-value" x-text="optionsLoading ? 'Memuat…' : {{ $select['summary'] }}"></span>
        <x-icon name="chevron-down" class="{{ $chevron }}" />
    </button>
    <div x-show="open" x-cloak class="{{ $menu }}" role="listbox" aria-multiselectable="true" aria-labelledby="promo-analysis-{{ $select['field'] }}-label">
        <template x-for="item in {{ $select['options'] }}" :key="item.value">
            <label class="{{ $option }}">
                <input type="checkbox" class="{{ $checkbox }}" :checked="filter.{{ $select['field'] }}.includes(item.value)" @change="toggle(@js($select['field']), item.value)">
                <span x-text="item.label"></span>
            </label>
        </template>
        <p x-show="{{ $select['options'] }}.length === 0" class="px-2.5 py-2 text-sm text-muted">{{ $select['empty'] }}</p>
    </div>
    <p id="promo-analysis-{{ $select['field'] }}-error" x-show="errors.{{ $select['field'] }}" x-cloak x-text="errors.{{ $select['field'] }}" class="{{ $error }}"></p>
</div>
