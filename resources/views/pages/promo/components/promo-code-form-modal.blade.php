{{-- Single Entry and edit share one form. There is no code type, active status, or effective date input by design. --}}
@php
    $labelClass = 'mb-1.5 block text-xs font-semibold text-label';
    $inputClass = 'h-9 w-full rounded-lg border border-line bg-white px-3 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:cursor-not-allowed disabled:bg-soft disabled:text-muted';
    $errorClass = 'mt-1.5 text-xs text-red-600';
    $helperClass = 'mt-1.5 text-xs text-muted';
    $required = '<span class="text-red-500" aria-hidden="true">*</span>';
@endphp

<div
    x-show="modal === 'form'"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="promo-code-form-title"
    @keydown.escape.window="modal === 'form' && !confirming && closeModal()"
    x-init="$watch('modal', (value) => value === 'form' && $nextTick(() => (isLocked ? $refs.funnelSelect : $refs.codeInput).focus()))"
>
    <div x-show="modal === 'form'" x-transition.opacity class="absolute inset-0 bg-slate-900/50" @click="!confirming && closeModal()"></div>

    <div
        x-show="modal === 'form'"
        x-transition
        class="relative max-h-[calc(100vh-2rem)] w-full max-w-md overflow-y-auto rounded-panel border border-line bg-surface p-5 shadow-lg"
    >
        <h2 id="promo-code-form-title" class="text-base font-semibold text-ink" x-text="editing ? 'Edit Promo Code' : 'Add Promo Code'">Add Promo Code</h2>
        <p x-show="!editing" class="mt-1 text-sm text-muted">Daftarkan satu kode promo beserta platform, funnel, dan source-nya.</p>
        <p x-show="editing" class="mt-1 text-sm text-muted">Perubahan disimpan sebagai versi baru yang berlaku sejak disimpan.</p>

        <p x-show="optionsError" class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700" role="alert">
            <span x-text="optionsError"></span>
            <button type="button" class="ml-1 font-semibold underline" @click="fetchOptions()">Retry</button>
        </p>

        <form class="mt-5 flex flex-col gap-4" novalidate @submit.prevent="submit()">
            <div>
                <label for="promo-code-code" class="{{ $labelClass }}">Promo Code {!! $required !!}</label>
                <input
                    id="promo-code-code"
                    x-ref="codeInput"
                    type="text"
                    x-model="form.code"
                    @input="errors.code = null"
                    class="{{ $inputClass }} font-mono uppercase"
                    :class="errors.code && 'border-red-500!'"
                    :disabled="isLocked"
                    :aria-invalid="Boolean(errors.code)"
                    placeholder="CPNS26"
                    autocomplete="off"
                    autocapitalize="characters"
                    spellcheck="false"
                    maxlength="255"
                >
                <p x-show="errors.code" x-text="errors.code" class="{{ $errorClass }}" role="alert"></p>
            </div>

            <div>
                <label for="promo-code-platform" class="{{ $labelClass }}">Platform {!! $required !!}</label>
                <select
                    id="promo-code-platform"
                    x-model="form.platform_id"
                    @change="errors.platform_id = null; errors.code = null"
                    class="{{ $inputClass }}"
                    :class="errors.platform_id && 'border-red-500!'"
                    :disabled="isLocked"
                    :aria-invalid="Boolean(errors.platform_id)"
                >
                    <option value="">Pilih platform</option>
                    <template x-for="platform in options.platforms" :key="platform.id">
                        <option :value="String(platform.id)" x-text="platform.name" :selected="String(platform.id) === form.platform_id"></option>
                    </template>
                </select>
                <p x-show="errors.platform_id" x-text="errors.platform_id" class="{{ $errorClass }}" role="alert"></p>
                <p x-show="!errors.platform_id && !isLocked" class="{{ $helperClass }}">Kode yang sama boleh dipakai di platform berbeda.</p>
            </div>

            <p x-show="isLocked" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                Kode dan platform tidak dapat diubah karena sudah digunakan oleh
                <span class="font-semibold" x-text="editing?.usages_count + ' transaksi'"></span>.
                Funnel dan source tetap bisa diubah.
            </p>

            <div>
                <label for="promo-code-funnel" class="{{ $labelClass }}">Funnel</label>
                <select
                    id="promo-code-funnel"
                    x-ref="funnelSelect"
                    x-model="form.funnel_id"
                    @change="errors.funnel_id = null"
                    class="{{ $inputClass }}"
                    :class="errors.funnel_id && 'border-red-500!'"
                    :aria-invalid="Boolean(errors.funnel_id)"
                >
                    <option value="">Belum diketahui</option>
                    <template x-for="funnel in options.funnels" :key="funnel.id">
                        <option :value="String(funnel.id)" x-text="funnel.name" :selected="String(funnel.id) === form.funnel_id"></option>
                    </template>
                </select>
                <p x-show="errors.funnel_id" x-text="errors.funnel_id" class="{{ $errorClass }}" role="alert"></p>
                <p x-show="!errors.funnel_id" class="{{ $helperClass }}">Opsional, boleh dikosongkan jika belum diketahui.</p>
            </div>

            <div>
                <label for="promo-code-source" class="{{ $labelClass }}">Source</label>
                <select
                    id="promo-code-source"
                    x-model="form.source_id"
                    @change="errors.source_id = null"
                    class="{{ $inputClass }}"
                    :class="errors.source_id && 'border-red-500!'"
                    :aria-invalid="Boolean(errors.source_id)"
                >
                    <option value="">Belum diketahui</option>
                    <template x-for="source in options.sources" :key="source.id">
                        <option :value="String(source.id)" x-text="source.name" :selected="String(source.id) === form.source_id"></option>
                    </template>
                </select>
                <p x-show="errors.source_id" x-text="errors.source_id" class="{{ $errorClass }}" role="alert"></p>
                <p x-show="!errors.source_id" class="{{ $helperClass }}">Opsional, boleh dikosongkan jika belum diketahui.</p>
            </div>

            <p x-show="formError" x-text="formError" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700" role="alert"></p>

            <div class="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                <button type="button" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-line bg-white px-3 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50" :disabled="saving" @click="closeModal()">Cancel</button>
                <button type="submit" :disabled="saving || (optionsLoading && !options.platforms.length)" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50">
                    <span x-text="saving ? 'Saving…' : (editing ? 'Save Changes' : 'Save Promo Code')">Save Promo Code</span>
                </button>
            </div>
        </form>
    </div>
</div>
