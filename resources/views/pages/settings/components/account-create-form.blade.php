@php
    $labelClass = 'mb-1.5 block pl-1 text-[11px] font-bold tracking-widest text-label uppercase';
    $inputClass = 'h-12 w-full rounded-xl border border-line bg-soft px-4 text-sm font-medium text-ink placeholder:font-normal placeholder:text-label focus:border-brand-indigo focus:bg-white focus:ring-3 focus:ring-brand-indigo/15 focus:outline-none';
    $errorClass = 'mt-1 pl-1 text-xs text-red-600';
    $required = '<span class="text-red-500" aria-hidden="true">*</span>';
@endphp

<div class="border-l-4 border-brand-indigo pl-4">
    <p class="text-[11px] font-bold tracking-[.2em] text-label uppercase">Akun Baru</p>
    <h2 class="mt-1 flex items-center gap-2 text-xl font-bold tracking-tight text-ink">
        <x-icon name="user-plus" class="size-5 text-brand-indigo" />
        Buat Akun
    </h2>
    <p class="mt-1 text-sm text-muted">Daftarkan akun baru untuk anggota tim. Hanya manager yang bisa membuat akun.</p>
</div>

<form class="mt-6 grid gap-5 sm:grid-cols-2" novalidate @submit.prevent="submitCreate()">
    <div>
        <label for="create-name" class="{{ $labelClass }}">Nama Lengkap {!! $required !!}</label>
        <input id="create-name" type="text" x-model="draft.fullname" @input="draftErrors.fullname = null"
            class="{{ $inputClass }}" :class="draftErrors.fullname && 'border-red-500!'" placeholder="Budi Santoso" autocomplete="off">
        <p x-show="draftErrors.fullname" x-text="draftErrors.fullname" class="{{ $errorClass }}"></p>
    </div>

    <div>
        <label for="create-role" class="{{ $labelClass }}">Role {!! $required !!}</label>
        <select id="create-role" x-model="draft.role_id" @change="draftErrors.role_id = null"
            class="{{ $inputClass }}" :class="draftErrors.role_id && 'border-red-500!'">
            <template x-for="role in roles" :key="role.id">
                <option :value="role.id" x-text="role.name" :selected="role.id == draft.role_id"></option>
            </template>
        </select>
        <p x-show="draftErrors.role_id" x-text="draftErrors.role_id" class="{{ $errorClass }}"></p>
    </div>

    <div class="sm:col-span-2">
        <label for="create-division" class="{{ $labelClass }}">Divisi</label>
        <select id="create-division" x-model="draft.division_id" @change="draftErrors.division_id = null"
            class="{{ $inputClass }}" :class="draftErrors.division_id && 'border-red-500!'">
            <option value="">— Tanpa divisi (lintas divisi) —</option>
            <template x-for="division in divisions" :key="division.id">
                <option :value="division.id" x-text="division.name" :selected="division.id == draft.division_id"></option>
            </template>
        </select>
        <p x-show="!draftErrors.division_id" class="mt-1 pl-1 text-xs text-muted">Opsional — kosongkan untuk akun yang tidak terikat pada satu divisi.</p>
        <p x-show="draftErrors.division_id" x-text="draftErrors.division_id" class="{{ $errorClass }}"></p>
    </div>

    <div>
        <label for="create-username" class="{{ $labelClass }}">Username {!! $required !!}</label>
        <input id="create-username" type="text" x-model="draft.username" @input="draftErrors.username = null"
            class="{{ $inputClass }}" :class="draftErrors.username && 'border-red-500!'" placeholder="budi.santoso" autocomplete="off" autocapitalize="none" spellcheck="false">
        <p x-show="!draftErrors.username" class="mt-1 pl-1 text-xs text-muted">4-25 karakter: huruf, angka, titik, atau underscore.</p>
        <p x-show="draftErrors.username" x-text="draftErrors.username" class="{{ $errorClass }}"></p>
    </div>

    <div>
        <label for="create-password" class="{{ $labelClass }}">Password {!! $required !!}</label>
        <div class="relative">
            <input id="create-password" :type="showDraftPassword ? 'text' : 'password'" x-model="draft.password" @input="draftErrors.password = null"
                class="{{ $inputClass }} pr-20" :class="draftErrors.password && 'border-red-500!'" placeholder="Minimal 8 karakter" autocomplete="new-password">
            <button type="button" class="absolute top-1/2 right-2 -translate-y-1/2 rounded-md px-2 py-1 text-xs font-medium text-label hover:text-ink"
                @click="showDraftPassword = !showDraftPassword" x-text="showDraftPassword ? 'Sembunyi' : 'Lihat'" :aria-pressed="showDraftPassword.toString()"></button>
        </div>
        <p x-show="draftErrors.password" x-text="draftErrors.password" class="{{ $errorClass }}"></p>
    </div>

    <p x-show="draftError" x-text="draftError" class="text-xs text-red-600 sm:col-span-2"></p>

    <div class="flex flex-col-reverse gap-2 border-t border-line pt-5 sm:col-span-2 sm:flex-row sm:justify-end">
        <button type="button" class="h-11 rounded-xl border border-line bg-soft px-5 text-sm font-medium text-ink hover:border-brand-indigo disabled:opacity-60" :disabled="creating" @click="resetDraft()">Reset</button>
        <button type="submit" :disabled="creating" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-linear-135 from-brand-indigo via-brand-blue to-brand-teal px-6 text-xs font-bold tracking-widest text-white uppercase shadow-[0_8px_18px_rgba(93,96,162,0.3)] [text-shadow:0_1px_2px_rgba(20,30,50,0.35)] hover:brightness-95 disabled:opacity-60">
            <x-icon name="user-plus" class="size-4" />
            <span x-text="creating ? 'Menyimpan…' : 'Buat Akun'">Buat Akun</span>
        </button>
    </div>
</form>
