@php
    $labelClass = 'mb-1.5 block pl-1 text-[11px] font-bold tracking-widest text-label uppercase';
    $inputClass = 'h-11 w-full rounded-xl border border-line bg-soft px-4 text-sm font-medium text-ink placeholder:font-normal placeholder:text-label focus:border-brand-indigo focus:bg-white focus:ring-3 focus:ring-brand-indigo/15 focus:outline-none';
    $errorClass = 'mt-1 pl-1 text-xs text-red-600';
    $required = '<span class="text-red-500" aria-hidden="true">*</span>';
@endphp

<div
    x-show="modal === 'create'"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="create-division-title"
    @keydown.escape.window="modal === 'create' && closeModal()"
    x-init="$watch('modal', (value) => value === 'create' && $nextTick(() => $refs.createCode.focus()))"
>
    <div x-show="modal === 'create'" x-transition.opacity class="absolute inset-0 bg-slate-900/45 backdrop-blur-sm" @click="closeModal()"></div>

    <div
        x-show="modal === 'create'"
        x-transition
        class="relative max-h-[calc(100vh-2rem)] w-full max-w-md overflow-y-auto rounded-3xl border border-white/60 bg-surface p-6 shadow-2xl sm:p-7 dark:bg-surface-dim"
    >
        <h2 id="create-division-title" class="text-xl font-bold tracking-tight">Buat Divisi<span class="text-brand-indigo">.</span></h2>
        <p class="mt-1 text-sm text-muted">Tambahkan divisi baru. Divisi bisa dipilih saat membuat atau mengubah akun.</p>

        <form class="mt-5 flex flex-col gap-4" novalidate @submit.prevent="submitCreate()">
            <div>
                <label for="create-code" class="{{ $labelClass }}">Kode Divisi {!! $required !!}</label>
                <input id="create-code" x-ref="createCode" type="text" x-model="draft.code_division" @input="draftErrors.code_division = null"
                    class="{{ $inputClass }}" :class="draftErrors.code_division && 'border-red-500!'" placeholder="MKT" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="50">
                <p x-show="!draftErrors.code_division" class="mt-1 pl-1 text-xs text-muted">Kode singkat yang unik, maksimal 50 karakter.</p>
                <p x-show="draftErrors.code_division" x-text="draftErrors.code_division" class="{{ $errorClass }}"></p>
            </div>

            <div>
                <label for="create-division-name" class="{{ $labelClass }}">Nama Divisi {!! $required !!}</label>
                <input id="create-division-name" type="text" x-model="draft.name" @input="draftErrors.name = null"
                    class="{{ $inputClass }}" :class="draftErrors.name && 'border-red-500!'" placeholder="Marketing" autocomplete="off" maxlength="25">
                <p x-show="!draftErrors.name" class="mt-1 pl-1 text-xs text-muted">Nama yang unik, maksimal 25 karakter.</p>
                <p x-show="draftErrors.name" x-text="draftErrors.name" class="{{ $errorClass }}"></p>
            </div>

            <p x-show="draftError" x-text="draftError" class="text-xs text-red-600"></p>

            <div class="mt-2 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="h-11 rounded-xl border border-line bg-soft px-5 text-sm font-medium text-ink hover:border-brand-indigo disabled:opacity-60" :disabled="creating" @click="closeModal()">Batal</button>
                <button type="submit" :disabled="creating" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-linear-135 from-brand-indigo via-brand-blue to-brand-teal px-5 text-xs font-bold tracking-widest text-white uppercase shadow-[0_8px_18px_rgba(93,96,162,0.3)] [text-shadow:0_1px_2px_rgba(20,30,50,0.35)] hover:brightness-95 disabled:opacity-60">
                    <x-icon name="building" class="size-4" />
                    <span x-text="creating ? 'Menyimpan…' : 'Simpan Divisi'">Simpan Divisi</span>
                </button>
            </div>
        </form>
    </div>
</div>
