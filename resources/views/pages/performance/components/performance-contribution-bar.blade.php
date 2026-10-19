{{-- Horizontal progress bar (0–100%) with the contribution value beside it. Expects `row` in Alpine scope. --}}
<div class="flex items-center gap-3">
    <div
        class="flex-1 cursor-default rounded-full py-1.5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        tabindex="0"
        role="img"
        :aria-label="`${row.name}: Total Revenue ${row.revenue}, Contribution ${row.contribution}`"
        @mouseenter="showTip(row, $event)"
        @mouseleave="hideTip()"
        @focus="showTip(row, $event)"
        @blur="hideTip()"
    >
        <div class="h-2 overflow-hidden rounded-full bg-soft">
            <div class="h-full rounded-full bg-primary" :style="{ width: row.width }"></div>
        </div>
    </div>
    <span class="performance-value w-16 shrink-0 text-right text-sm font-semibold text-ink" x-text="row.contribution"></span>
</div>
