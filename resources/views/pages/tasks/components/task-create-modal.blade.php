{{-- Create Task (TASK-02): hanya untuk Admin dan Manajer. --}}
@php
    $labelClass = 'mb-1.5 block text-xs font-semibold text-label';
    $inputClass = 'h-9 w-full rounded-lg border border-line bg-white px-3 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:bg-soft disabled:text-muted';
    $errorClass = 'mt-1.5 text-xs text-red-600';
    $helperClass = 'mt-1.5 text-xs text-muted';
    $required = '<span class="text-red-500" aria-hidden="true">*</span>';
@endphp

<div x-data="taskCreateForm" x-show="createOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="create-task-title"
    @keydown.escape.window="createOpen && !saving && (createOpen = false)">
    <div x-show="createOpen" x-transition.opacity class="absolute inset-0 bg-slate-900/50" @click="!saving && (createOpen = false)"></div>

    <div x-show="createOpen" x-transition class="relative max-h-[calc(100vh-2rem)] w-full max-w-2xl overflow-y-auto rounded-panel border border-line bg-surface p-5 shadow-lg">
        <h2 id="create-task-title" class="text-base font-semibold text-ink">Create Task</h2>
        <p class="mt-1 text-sm text-muted">Buat tugas baru dan tentukan penanggung jawabnya. Pembuat tugas dicatat otomatis dari akun Anda.</p>

        <form class="mt-5 flex flex-col gap-5" novalidate @submit.prevent="submit()">
            {{-- Informasi pekerjaan --}}
            <fieldset class="grid gap-4 sm:grid-cols-2">
                <legend class="mb-3 text-xs font-bold tracking-wide text-label uppercase">Informasi Pekerjaan</legend>

                <div class="sm:col-span-2">
                    <label for="create-task-title-input" class="{{ $labelClass }}">Judul Tugas {!! $required !!}</label>
                    <input id="create-task-title-input" x-ref="createTitle" type="text" x-model="form.title" maxlength="150" placeholder="Contoh: Desain banner promo Oktober"
                        class="{{ $inputClass }}" :class="errors.title && 'border-red-500!'" @input="errors.title = null">
                    <p x-show="errors.title" x-text="errors.title" class="{{ $errorClass }}"></p>
                </div>

                <div class="sm:col-span-2">
                    <label for="create-task-description" class="{{ $labelClass }}">Deskripsi</label>
                    <textarea id="create-task-description" x-model="form.description" rows="3" placeholder="Jelaskan kebutuhan dan hasil yang diharapkan"
                        class="w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"></textarea>
                </div>

                <div>
                    <label for="create-task-category" class="{{ $labelClass }}">Kategori / Jenis Tugas</label>
                    <select id="create-task-category" x-model="form.category_id" class="{{ $inputClass }}" :disabled="!categories.length">
                        <option value="">Pilih kategori</option>
                        <template x-for="category in categories" :key="category.id">
                            <option :value="category.id" x-text="category.name"></option>
                        </template>
                    </select>
                    <p x-show="!categories.length" class="{{ $helperClass }} text-amber-800">Menunggu Back End: master kategori tugas belum tersedia.</p>
                </div>

                <div>
                    <span class="{{ $labelClass }}">Kewajiban QA</span>
                    <p class="flex h-9 items-center rounded-lg border border-line bg-soft px-3 text-sm"
                        :class="requiresReview === null ? 'text-muted' : 'font-semibold text-ink'"
                        x-text="requiresReview === null ? 'Ditentukan dari kategori' : (requiresReview ? 'Wajib QA' : 'Tidak wajib QA')"></p>
                    <p class="{{ $helperClass }}">Otomatis mengikuti kategori yang dipilih.</p>
                </div>

                <div>
                    <label for="create-task-platform" class="{{ $labelClass }}">Platform</label>
                    <select id="create-task-platform" x-model="form.platform" class="{{ $inputClass }}">
                        <option value="">Tidak terkait platform</option>
                        <template x-for="platform in platforms" :key="platform.slug">
                            <option :value="platform.slug" x-text="platform.name"></option>
                        </template>
                    </select>
                    <p class="{{ $helperClass }}">Isi apabila tugas terkait platform tertentu.</p>
                </div>

                <div>
                    <label for="create-task-priority" class="{{ $labelClass }}">Prioritas {!! $required !!}</label>
                    <select id="create-task-priority" x-model="form.priority" class="{{ $inputClass }}">
                        <template x-for="priority in priorities" :key="priority.key">
                            <option :value="priority.key" x-text="priority.label" :selected="form.priority === priority.key"></option>
                        </template>
                    </select>
                </div>
            </fieldset>

            {{-- Penugasan --}}
            <fieldset class="grid gap-4 border-t border-line pt-5 sm:grid-cols-2">
                <legend class="mb-3 text-xs font-bold tracking-wide text-label uppercase">Penugasan</legend>

                <div>
                    <label for="create-task-division" class="{{ $labelClass }}">Target Divisi {!! $required !!}</label>
                    <select id="create-task-division" x-model="form.division" class="{{ $inputClass }}" :class="errors.division && 'border-red-500!'" :disabled="user.role === 'manager'" @change="errors.division = null">
                        <option value="">Pilih divisi</option>
                        <template x-for="division in creatableDivisions" :key="division.code_division">
                            <option :value="division.code_division" x-text="division.name" :selected="form.division === division.code_division"></option>
                        </template>
                    </select>
                    <p x-show="!errors.division" class="{{ $helperClass }}"
                        :class="user.role === 'manager' && !creatableDivisions.length && 'text-amber-800'"
                        x-text="user.role === 'admin' ? 'Admin dapat memilih semua divisi.' : (creatableDivisions.length ? 'Manajer hanya membuat tugas untuk divisinya.' : 'Akun Anda belum memiliki divisi. Hubungi Admin untuk menetapkan divisi.')"></p>
                    <p x-show="errors.division" x-text="errors.division" class="{{ $errorClass }}"></p>
                </div>

                <div>
                    <label for="create-task-pic" class="{{ $labelClass }}">PIC</label>
                    <select id="create-task-pic" x-model="form.pic_id" class="{{ $inputClass }}" :class="errors.pic_id && 'border-red-500!'" :disabled="assigneeState !== 'ready'" @change="errors.pic_id = null">
                        <option value="" x-text="{ idle: 'Pilih divisi terlebih dahulu', loading: 'Memuat PIC…', unavailable: 'Belum tersedia', error: 'Gagal memuat PIC', ready: 'Pilih PIC' }[assigneeState]"></option>
                        <template x-for="person in assignees" :key="person.id">
                            <option :value="person.id" x-text="person.fullname"></option>
                        </template>
                    </select>
                    <p x-show="assigneeState === 'unavailable' && !errors.pic_id" class="{{ $helperClass }} text-amber-800">Menunggu Back End: daftar PIC sesuai aturan penugasan belum tersedia.</p>
                    <p x-show="errors.pic_id" x-text="errors.pic_id" class="{{ $errorClass }}"></p>
                </div>

                <div>
                    <label for="create-task-start" class="{{ $labelClass }}">Tanggal Mulai</label>
                    <input id="create-task-start" type="date" x-model="form.start_date" class="{{ $inputClass }}">
                </div>

                <div>
                    <label for="create-task-deadline" class="{{ $labelClass }}">Deadline</label>
                    <input id="create-task-deadline" type="date" x-model="form.deadline" class="{{ $inputClass }}" :class="errors.deadline && 'border-red-500!'" @input="errors.deadline = null">
                    <p x-show="errors.deadline" x-text="errors.deadline" class="{{ $errorClass }}"></p>
                </div>

                <div class="sm:col-span-2">
                    <span class="{{ $labelClass }}">Status Awal {!! $required !!}</span>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label class="flex cursor-pointer gap-3 rounded-lg border p-3" :class="form.status === 'backlog' ? 'border-primary bg-primary-soft' : 'border-line'">
                            <input type="radio" value="backlog" x-model="form.status" class="mt-0.5 accent-primary">
                            <span><span class="block text-sm font-semibold text-ink">Back Log</span><span class="text-xs text-muted">Tugas belum siap dikerjakan.</span></span>
                        </label>
                        <label class="flex cursor-pointer gap-3 rounded-lg border p-3" :class="form.status === 'todo' ? 'border-primary bg-primary-soft' : 'border-line'">
                            <input type="radio" value="todo" x-model="form.status" class="mt-0.5 accent-primary" @change="errors.pic_id = null">
                            <span><span class="block text-sm font-semibold text-ink">To Do</span><span class="text-xs text-muted">Siap dikerjakan, wajib memiliki PIC.</span></span>
                        </label>
                    </div>
                </div>
            </fieldset>

            <p x-show="formError" x-text="formError" class="rounded-lg border px-3 py-2 text-xs" role="alert"
                :class="formPending ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-red-200 bg-red-50 text-red-700'"></p>

            <div class="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                <button type="button" class="{{ $button }}" :disabled="saving" @click="createOpen = false">Cancel</button>
                <button type="submit" class="{{ $primaryButton }}" :disabled="saving">
                    <span x-text="saving ? 'Saving…' : 'Save Task'">Save Task</span>
                </button>
            </div>
        </form>
    </div>
</div>
