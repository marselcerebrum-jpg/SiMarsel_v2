@php
    $labelClass = 'mb-1.5 block pl-1 text-[11px] font-bold tracking-widest text-label uppercase';
    $inputClass = 'h-11 w-full rounded-xl border border-line bg-soft px-4 text-sm font-medium text-ink placeholder:font-normal placeholder:text-label focus:border-brand-indigo focus:bg-white focus:ring-3 focus:ring-brand-indigo/15 focus:outline-none';
    $errorClass = 'mt-1 pl-1 text-xs text-red-600';
@endphp

<div
    x-show="modal === 'edit'"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="edit-division-title"
    @keydown.escape.window="modal === 'edit' && closeModal()"
    x-init="$watch('modal', (value) => value === 'edit' && $nextTick(() => $refs.editCode.focus()))"
>
    <div x-show="modal === 'edit'" x-transition.opacity class="absolute inset-0 bg-slate-900/45 backdrop-blur-sm" @click="closeModal()"></div>

    <div
        x-show="modal === 'edit'"
        x-transition
        class="relative max-h-[calc(100vh-2rem)] w-full max-w-md overflow-y-auto rounded-3xl border border-white/60 bg-surface p-6 shadow-2xl sm:p-7 dark:bg-surface-dim"
    >
        <h2 id="edit-division-title" class="text-xl font-bold tracking-tight">Edit Divisi<span class="text-brand-indigo">.</span></h2>
        <p class="mt-1 text-sm text-muted">Perbarui kode atau nama divisi. Anggota divisi tidak berubah.</p>

        <form class="mt-5 flex flex-col gap-4" novalidate @submit.prevent="submitEdit()">
            <div>
                <label for="edit-code" class="{{ $labelClass }}">Kode Divisi</label>
                <input id="edit-code" x-ref="editCode" type="text" x-model="form.code_division" @input="errors.code_division = null"
                    class="{{ $inputClass }}" :class="errors.code_division && 'border-red-500!'" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="50">
                <p x-show="errors.code_division" x-text="errors.code_division" class="{{ $errorClass }}"></p>
            </div>

            <div>
                <label for="edit-division-name" class="{{ $labelClass }}">Nama Divisi</label>
                <input id="edit-division-name" type="text" x-model="form.name" @input="errors.name = null"
                    class="{{ $inputClass }}" :class="errors.name && 'border-red-500!'" autocomplete="off" maxlength="25">
                <p x-show="errors.name" x-text="errors.name" class="{{ $errorClass }}"></p>
            </div>

            <p x-show="formError" x-text="formError" class="text-xs text-red-600"></p>

            <div class="mt-2 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="h-11 rounded-xl border border-line bg-soft px-5 text-sm font-medium text-ink hover:border-brand-indigo" @click="closeModal()">Batal</button>
                <button type="submit" :disabled="saving" class="h-11 rounded-xl bg-linear-135 from-brand-indigo via-brand-blue to-brand-teal px-5 text-xs font-bold tracking-widest text-white uppercase shadow-[0_8px_18px_rgba(93,96,162,0.3)] [text-shadow:0_1px_2px_rgba(20,30,50,0.35)] hover:brightness-95 disabled:opacity-60" x-text="saving ? 'Menyimpan…' : 'Simpan Perubahan'"></button>
            </div>
        </form>
    </div>
</div>
