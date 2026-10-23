{{-- Shown before saving an edit that changes Funnel and/or Source. Cancel sends nothing; Continue sends the edit. --}}
<div
    x-show="confirming"
    x-cloak
    class="fixed inset-0 z-[60] flex items-center justify-center p-4"
    role="alertdialog"
    aria-modal="true"
    aria-labelledby="promo-code-confirm-title"
    aria-describedby="promo-code-confirm-desc"
    @keydown.escape.window="confirming && cancelConfirm()"
    x-init="$watch('confirming', (value) => value && $nextTick(() => $refs.confirmCancel.focus()))"
>
    <div x-show="confirming" x-transition.opacity class="absolute inset-0 bg-slate-900/50" @click="cancelConfirm()"></div>

    <div
        x-show="confirming"
        x-transition
        class="relative w-full max-w-sm rounded-panel border border-line bg-surface p-5 shadow-lg"
    >
        <h2 id="promo-code-confirm-title" class="text-base font-semibold text-ink">Confirm Funnel/Source Change</h2>
        <p id="promo-code-confirm-desc" class="mt-1 text-sm text-muted">
            Perubahan Funnel/Sumber berlaku untuk penggunaan setelah perubahan disimpan.
            Transaksi historis yang sudah terklasifikasi tidak akan berubah. Lanjutkan?
        </p>

        <dl class="mt-3 space-y-2 rounded-lg border border-line bg-soft px-3 py-2 text-xs">
            <div x-show="funnelChanged" class="flex flex-wrap gap-x-2">
                <dt class="font-semibold text-label">Funnel</dt>
                <dd class="text-ink">
                    <span x-text="editing?.funnel?.name ?? 'Belum diketahui'"></span>
                    →
                    <span class="font-semibold" x-text="options.funnels.find((funnel) => String(funnel.id) === form.funnel_id)?.name ?? 'Belum diketahui'"></span>
                </dd>
            </div>
            <div x-show="sourceChanged" class="flex flex-wrap gap-x-2">
                <dt class="font-semibold text-label">Source</dt>
                <dd class="text-ink">
                    <span x-text="editing?.source?.name ?? 'Belum diketahui'"></span>
                    →
                    <span class="font-semibold" x-text="options.sources.find((source) => String(source.id) === form.source_id)?.name ?? 'Belum diketahui'"></span>
                </dd>
            </div>
        </dl>

        <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" x-ref="confirmCancel" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="cancelConfirm()">Cancel</button>
            <button type="button" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="save()">Continue</button>
        </div>
    </div>
</div>
