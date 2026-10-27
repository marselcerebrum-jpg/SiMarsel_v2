{{-- Panel detail tugas (TASK-03 s.d. TASK-08). Data diambil berdasarkan ID tugas. --}}
@php
    $labelClass = 'mb-1.5 block text-xs font-semibold text-label';
    $inputClass = 'h-9 w-full rounded-lg border border-line bg-white px-3 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:bg-soft';
    $textareaClass = 'w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink placeholder:text-label focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none';
    $errorClass = 'mt-1.5 text-xs text-red-600';
    $sectionTitle = 'text-sm font-semibold text-ink';
    $dt = 'text-xs font-semibold text-label';
    $dd = 'mt-0.5 text-sm text-ink';
    $empty = 'italic text-muted';
@endphp

<div x-data="taskDetail" x-show="detailId !== null" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-labelledby="task-detail-title" @keydown.escape.window="detailId !== null && closeTask()">
    <div x-show="detailId !== null" x-transition.opacity class="absolute inset-0 bg-slate-900/50" @click="closeTask()"></div>

    <aside x-show="detailId !== null" x-transition:enter="transition duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
        class="absolute inset-y-0 right-0 flex w-full max-w-2xl flex-col bg-surface shadow-2xl">
        <div class="flex items-center justify-between border-b border-line px-5 py-3">
            <p class="text-xs font-semibold text-label">Detail Tugas <span x-show="task" x-text="`#${task?.id}`"></span></p>
            <button type="button" class="grid size-8 place-items-center rounded-lg text-muted hover:bg-soft hover:text-ink" @click="closeTask()" aria-label="Tutup detail">
                <x-icon name="close" class="size-4" />
            </button>
        </div>

        <div class="flex-1 overflow-y-auto px-5 py-5">
            {{-- Kondisi selain data siap --}}
            <div x-show="state === 'loading'" class="flex animate-pulse flex-col gap-3" aria-label="Memuat detail">
                <div class="h-6 w-2/3 rounded bg-soft"></div>
                <div class="h-4 w-1/3 rounded bg-soft"></div>
                <div class="mt-4 h-40 rounded-lg bg-soft"></div>
            </div>

            <div x-show="state === 'unavailable'" x-cloak>
                @include('pages.tasks.components.pending', ['text' => 'Detail tugas diambil dari API detail tugas yang belum tersedia.'])
            </div>

            <div x-show="['not_found', 'forbidden', 'error'].includes(state)" x-cloak class="flex flex-col items-center gap-2 rounded-lg border border-line bg-soft px-4 py-12 text-center" role="alert">
                <x-icon name="alert" class="size-6 text-label" />
                <p class="text-sm font-semibold text-ink" x-text="{ not_found: 'Tugas tidak ditemukan', forbidden: 'Akses ditolak', error: 'Detail gagal dimuat' }[state]"></p>
                <p class="text-sm text-muted" x-text="message"></p>
                <button x-show="state === 'error'" type="button" class="{{ $button }} mt-2" @click="reload()">Try Again</button>
            </div>

            <template x-if="state === 'ready' && task">
                <div class="flex flex-col gap-6">
                    {{-- Informasi utama dan aksi: judul, status, prioritas, deadline, PIC, tombol workflow --}}
                    <header class="flex flex-col gap-4">
                        <div>
                            <div class="flex flex-wrap items-center gap-1.5" x-show="task.requires_review || isOverdue(task)">
                                <span x-show="task.requires_review" class="{{ $badge }} bg-violet-50 text-violet-700">Wajib QA</span>
                                <span x-show="isOverdue(task)" class="{{ $badge }} bg-red-50 text-red-700">Terlambat</span>
                            </div>
                            <h2 id="task-detail-title" class="mt-1.5 text-xl font-bold break-words text-ink" x-text="task.title"></h2>
                            <p class="mt-0.5 text-sm text-muted"><span x-text="task.division?.name ?? '-'"></span> · <span x-text="task.category?.name ?? 'Tanpa kategori'"></span></p>
                        </div>

                        <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-line bg-line sm:grid-cols-4" data-testid="detail-key-facts">
                            <div class="bg-surface px-3 py-2.5">
                                <dt class="{{ $dt }}">Status</dt>
                                <dd class="mt-1"><span class="{{ $badge }}" :class="statusOf(task.status).badge" x-text="statusOf(task.status).label"></span></dd>
                            </div>
                            <div class="bg-surface px-3 py-2.5">
                                <dt class="{{ $dt }}">Prioritas</dt>
                                <dd class="mt-1"><span class="{{ $badge }}" :class="priorityOf(task.priority).badge" x-text="priorityOf(task.priority).label"></span></dd>
                            </div>
                            <div class="bg-surface px-3 py-2.5">
                                <dt class="{{ $dt }}">Deadline</dt>
                                <dd class="mt-1 text-sm" :class="!task.deadline ? @js($empty) : (isOverdue(task) ? 'font-semibold text-red-700' : 'font-semibold text-ink')" x-text="task.deadline ? formatDate(task.deadline) : 'Tanpa deadline'"></dd>
                            </div>
                            <div class="min-w-0 bg-surface px-3 py-2.5">
                                <dt class="{{ $dt }}">PIC</dt>
                                <dd class="mt-1 truncate text-sm" :class="task.pic ? 'font-semibold text-ink' : @js($empty)" x-text="task.pic?.fullname ?? 'Belum ada PIC'" :title="task.pic?.fullname"></dd>
                            </div>
                        </dl>

                        <div class="flex flex-wrap gap-2">
                            <template x-for="transition in transitions" :key="transition.to">
                                <button type="button"
                                    class="{{ $button }}"
                                    :class="transition.review === 'revision' ? 'border-amber-300 text-amber-800' : (transition.review === 'approve' || transition.to === 'done' ? 'border-primary bg-primary text-white hover:bg-primary-hover' : '')"
                                    :disabled="acting !== null"
                                    @click="runTransition(transition)">
                                    <span x-text="acting === transition.to ? 'Menyimpan…' : transition.label"></span>
                                </button>
                            </template>
                            <button x-show="canEditThis && !editing" type="button" class="{{ $button }}" @click="startEdit()">Edit Tugas</button>
                            <p x-show="!transitions.length && task.status !== 'done'" class="self-center text-xs text-muted">
                                Tidak ada perubahan status yang dapat Anda lakukan pada tahap ini.
                            </p>
                        </div>
                    </header>

                    {{-- Keputusan review (TASK-07) --}}
                    <section x-show="reviewMode" x-cloak class="rounded-lg border p-4" :class="reviewMode === 'revision' ? 'border-amber-200 bg-amber-50/60' : 'border-primary/30 bg-primary-soft'">
                        <h3 class="{{ $sectionTitle }}" x-text="reviewMode === 'revision' ? 'Request Revision' : 'Approve Tugas'"></h3>
                        <p class="mt-1 text-xs text-muted" x-text="reviewMode === 'revision' ? 'Tugas kembali ke On Process. Jelaskan apa yang perlu diperbaiki PIC.' : 'Tugas akan ditandai Done dan waktu penyelesaian dicatat.'"></p>
                        <label for="review-note" class="{{ $labelClass }} mt-3">Catatan <span x-show="reviewMode === 'revision'" class="text-red-500">*</span><span x-show="reviewMode !== 'revision'">(opsional)</span></label>
                        <textarea id="review-note" x-ref="reviewNote" x-model="reviewNote" rows="3" class="{{ $textareaClass }}" @input="reviewError = ''" maxlength="1000"
                            :placeholder="reviewMode === 'revision' ? 'Contoh: lampirkan versi final desain dan perbaiki ukuran banner.' : 'Catatan untuk PIC'"></textarea>
                        <p x-show="reviewError" x-text="reviewError" class="{{ $errorClass }}"></p>
                        <div class="mt-3 flex justify-end gap-2">
                            <button type="button" class="{{ $button }}" :disabled="acting !== null" @click="reviewMode = null">Batal</button>
                            <button type="button" class="{{ $primaryButton }}" :disabled="acting !== null" @click="submitReview()"
                                x-text="acting ? 'Menyimpan…' : (reviewMode === 'revision' ? 'Kirim Revisi' : 'Approve')"></button>
                        </div>
                    </section>

                    {{-- Catatan review terakhir untuk PIC --}}
                    <section x-show="task.last_review" class="rounded-lg border p-4" :class="task.last_review?.decision === 'revision' ? 'border-amber-200 bg-amber-50/60' : 'border-line bg-soft'">
                        <h3 class="{{ $sectionTitle }}" x-text="task.last_review?.decision === 'revision' ? 'Catatan Revisi Terakhir' : 'Hasil Review Terakhir'"></h3>
                        <p class="mt-1 text-sm text-ink" x-text="task.last_review?.note || 'Tanpa catatan.'"></p>
                        <p class="mt-1 text-xs text-muted"><span x-text="task.last_review?.reviewer?.fullname ?? '-'"></span> · <span x-text="formatDateTime(task.last_review?.created_at)"></span></p>
                    </section>

                    {{-- Informasi tugas / form edit (TASK-03) --}}
                    <section>
                        <h3 class="{{ $sectionTitle }}">Informasi Tugas</h3>

                        <dl x-show="!editing" class="mt-3 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <dt class="{{ $dt }}">Deskripsi</dt>
                                <dd class="{{ $dd }} whitespace-pre-line" :class="!task.description && @js($empty)" x-text="task.description || 'Belum ada deskripsi.'"></dd>
                            </div>
                            <div><dt class="{{ $dt }}">Kategori / Jenis Tugas</dt><dd class="{{ $dd }}" :class="!task.category && @js($empty)" x-text="task.category?.name ?? 'Belum ditentukan'"></dd></div>
                            <div><dt class="{{ $dt }}">Divisi</dt><dd class="{{ $dd }}" x-text="task.division?.name ?? '-'"></dd></div>
                            <div><dt class="{{ $dt }}">Platform</dt><dd class="{{ $dd }}" :class="!task.platform && @js($empty)" x-text="task.platform?.name ?? 'Tidak terkait platform'"></dd></div>
                            <div><dt class="{{ $dt }}">Tanggal Mulai</dt><dd class="{{ $dd }}" :class="!task.start_date && @js($empty)" x-text="task.start_date ? formatDate(task.start_date) : 'Belum ditentukan'"></dd></div>
                            <div><dt class="{{ $dt }}">Kewajiban QA</dt><dd class="{{ $dd }}" x-text="task.requires_review ? 'Ya, wajib melewati QA sebelum Done' : 'Tidak, PIC dapat langsung menyelesaikan'"></dd></div>
                            <div><dt class="{{ $dt }}">Dibuat oleh</dt><dd class="{{ $dd }}"><span x-text="task.creator?.fullname ?? '-'"></span> <span class="text-muted" x-show="task.created_at" x-text="`· ${formatDateTime(task.created_at)}`"></span></dd></div>
                            <div x-show="task.completed_at"><dt class="{{ $dt }}">Selesai</dt><dd class="{{ $dd }}" x-text="formatDateTime(task.completed_at)"></dd></div>
                        </dl>

                        <form x-show="editing" x-cloak class="mt-3 grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="saveEdit()">
                            <div class="sm:col-span-2">
                                <label for="edit-title" class="{{ $labelClass }}">Judul Tugas <span class="text-red-500">*</span></label>
                                <input id="edit-title" type="text" x-model="editForm.title" maxlength="150" class="{{ $inputClass }}" :class="editErrors.title && 'border-red-500!'" :disabled="!isEditable('title')" @input="editErrors.title = null">
                                <p x-show="editErrors.title" x-text="editErrors.title" class="{{ $errorClass }}"></p>
                            </div>
                            <div class="sm:col-span-2">
                                <label for="edit-description" class="{{ $labelClass }}">Deskripsi</label>
                                <textarea id="edit-description" x-model="editForm.description" rows="4" maxlength="5000" class="{{ $textareaClass }} disabled:bg-soft" :class="editErrors.description && 'border-red-500!'" :disabled="!isEditable('description')" @input="editErrors.description = null"></textarea>
                                <p x-show="editErrors.description" x-text="editErrors.description" class="{{ $errorClass }}"></p>
                            </div>
                            <div>
                                <label for="edit-category" class="{{ $labelClass }}">Kategori / Jenis Tugas</label>
                                <select id="edit-category" x-model="editForm.category_id" class="{{ $inputClass }}" :class="editErrors.category_id && 'border-red-500!'" :disabled="!isEditable('category_id') || editOptionsState === 'loading'" @change="editErrors.category_id = null">
                                    <option value="">Tanpa kategori</option>
                                    <template x-for="category in editChoices('categories')" :key="category.id">
                                        <option :value="String(category.id)" x-text="category.name" :selected="editForm.category_id === String(category.id)"></option>
                                    </template>
                                </select>
                                <p x-show="editErrors.category_id" x-text="editErrors.category_id" class="{{ $errorClass }}"></p>
                                <p x-show="!editErrors.category_id" class="mt-1.5 text-xs text-muted" x-text="editCategoryRequiresReview ? 'Kategori ini mewajibkan QA sebelum Done.' : 'Mengubah kategori dapat mengubah kewajiban QA.'"></p>
                            </div>
                            <div>
                                <label for="edit-platform" class="{{ $labelClass }}">Platform</label>
                                <select id="edit-platform" x-model="editForm.platform_id" class="{{ $inputClass }}" :class="editErrors.platform_id && 'border-red-500!'" :disabled="!isEditable('platform_id') || editOptionsState === 'loading'" @change="editErrors.platform_id = null">
                                    <option value="">Tidak terkait platform</option>
                                    <template x-for="platform in editChoices('platforms')" :key="platform.id">
                                        <option :value="String(platform.id)" x-text="platform.name" :selected="editForm.platform_id === String(platform.id)"></option>
                                    </template>
                                </select>
                                <p x-show="editErrors.platform_id" x-text="editErrors.platform_id" class="{{ $errorClass }}"></p>
                            </div>
                            <p x-show="editOptionsState === 'error'" class="text-xs text-amber-700 sm:col-span-2">
                                Daftar kategori dan platform gagal dimuat; pilihan saat ini tetap tersimpan.
                                <button type="button" class="font-semibold underline" @click="loadEditOptions()">Coba lagi</button>
                            </p>
                            <div>
                                <label for="edit-priority" class="{{ $labelClass }}">Prioritas</label>
                                <select id="edit-priority" x-model="editForm.priority" class="{{ $inputClass }}" :class="editErrors.priority && 'border-red-500!'" :disabled="!isEditable('priority')" @change="editErrors.priority = null">
                                    <template x-for="priority in priorities" :key="priority.key">
                                        <option :value="priority.key" x-text="priority.label" :selected="editForm.priority === priority.key"></option>
                                    </template>
                                </select>
                                <p x-show="editErrors.priority" x-text="editErrors.priority" class="{{ $errorClass }}"></p>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label for="edit-start" class="{{ $labelClass }}">Tanggal Mulai</label>
                                    <input id="edit-start" type="date" x-model="editForm.start_date" class="{{ $inputClass }}" :class="editErrors.start_date && 'border-red-500!'" :disabled="!isEditable('start_date')" @input="editErrors.start_date = null">
                                </div>
                                <div>
                                    <label for="edit-deadline" class="{{ $labelClass }}">Deadline</label>
                                    <input id="edit-deadline" type="date" x-model="editForm.deadline" class="{{ $inputClass }}" :class="editErrors.deadline && 'border-red-500!'" :disabled="!isEditable('deadline')" @input="editErrors.deadline = null">
                                </div>
                                <p x-show="editErrors.start_date" x-text="editErrors.start_date" class="{{ $errorClass }} col-span-2 mt-0"></p>
                                <p x-show="editErrors.deadline" x-text="editErrors.deadline" class="{{ $errorClass }} col-span-2 mt-0"></p>
                            </div>
                            <p class="text-xs text-muted sm:col-span-2">Divisi dan PIC diubah melalui bagian Penanggung Jawab; status melalui tombol workflow.</p>
                            <p x-show="editFormError" x-text="editFormError" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 sm:col-span-2" role="alert"></p>
                            <div class="flex justify-end gap-2 border-t border-line pt-4 sm:col-span-2">
                                <button type="button" class="{{ $button }}" :disabled="saving" @click="editing = false">Cancel</button>
                                <button type="submit" class="{{ $primaryButton }}" :disabled="saving" x-text="saving ? 'Saving…' : 'Save Changes'"></button>
                            </div>
                        </form>
                    </section>

                    {{-- Penanggung jawab (TASK-04) --}}
                    <section>
                        <h3 class="{{ $sectionTitle }}">Penanggung Jawab</h3>
                        <div class="mt-3 flex flex-col gap-3 rounded-lg border border-line p-4">
                            <div class="flex items-center justify-between gap-3">
                                <span class="inline-flex items-center gap-2 text-sm">
                                    <x-icon name="user" class="size-4 text-label" />
                                    <span :class="task.pic ? 'font-semibold text-ink' : @js($empty)" x-text="task.pic?.fullname ?? 'Belum ada PIC'"></span>
                                </span>
                                <button x-show="assignment.ok && assigneeState === 'idle'" type="button" class="{{ $button }}" @click="openAssign()" x-text="task.pic ? 'Ganti / Teruskan PIC' : 'Tetapkan PIC'"></button>
                            </div>
                            <p x-show="!assignment.ok && user.role !== 'staff'" class="text-xs text-muted" x-text="assignment.reason"></p>

                            <div x-show="assigneeState !== 'idle'" x-cloak class="flex flex-col gap-3 border-t border-line pt-3">
                                <div x-show="assigneeState === 'loading'" class="h-9 animate-pulse rounded-lg bg-soft"></div>
                                <div x-show="assigneeState === 'unavailable'">
                                    @include('pages.tasks.components.pending', ['text' => 'Daftar PIC yang memenuhi kriteria penugasan diambil dari API assignment.'])
                                </div>
                                <p x-show="assigneeState === 'error'" class="text-xs text-red-600">Daftar PIC gagal dimuat.</p>
                                <template x-if="assigneeState === 'ready'">
                                    <form class="flex flex-col gap-3" @submit.prevent="saveAssign()">
                                        <div>
                                            <label for="assign-pic" class="{{ $labelClass }}">PIC baru</label>
                                            <select id="assign-pic" x-model="assignForm.pic_id" class="{{ $inputClass }}">
                                                <option value="">Pilih PIC</option>
                                                <template x-for="person in assignees" :key="person.id">
                                                    <option :value="person.id" x-text="`${person.fullname}${person.division ? ' · ' + person.division.name : ''}`"></option>
                                                </template>
                                            </select>
                                        </div>
                                        <div>
                                            <label for="assign-note" class="{{ $labelClass }}">Catatan (opsional)</label>
                                            <input id="assign-note" type="text" x-model="assignForm.note" class="{{ $inputClass }}" maxlength="255" placeholder="Alasan penggantian atau penerusan">
                                        </div>
                                        <p class="text-xs text-muted">Mengganti PIC tidak mengubah status tugas. PIC lama dan baru tercatat di histori.</p>
                                        <div class="flex justify-end gap-2">
                                            <button type="button" class="{{ $button }}" :disabled="assigning" @click="assigneeState = 'idle'">Cancel</button>
                                            <button type="submit" class="{{ $primaryButton }}" :disabled="!assignForm.pic_id || assigning" x-text="assigning ? 'Saving…' : 'Simpan PIC'"></button>
                                        </div>
                                    </form>
                                </template>
                                <button x-show="assigneeState !== 'ready'" type="button" class="self-end text-xs font-semibold text-muted hover:text-ink" @click="assigneeState = 'idle'">Tutup</button>
                            </div>
                        </div>
                    </section>

                    {{-- Evidence (TASK-06) --}}
                    <section>
                        <div class="flex items-center justify-between">
                            <h3 class="{{ $sectionTitle }}">Evidence</h3>
                            <span x-show="task.evidence_required" class="text-xs font-semibold text-amber-700">Wajib sebelum submission</span>
                        </div>

                        <form x-show="canSubmitEvidence" class="mt-3 flex flex-col gap-3 rounded-lg border border-line p-4" novalidate @submit.prevent="sendEvidence()">
                            <div class="inline-flex self-start rounded-lg border border-line bg-soft p-1" role="group" aria-label="Jenis evidence">
                                <button type="button" class="rounded-md px-3 py-1 text-xs font-semibold" :class="evidenceForm.type === 'file' ? 'bg-white text-ink shadow-sm' : 'text-muted'" @click="evidenceForm.type = 'file'; evidenceErrors = {}">File</button>
                                <button type="button" class="rounded-md px-3 py-1 text-xs font-semibold" :class="evidenceForm.type === 'link' ? 'bg-white text-ink shadow-sm' : 'text-muted'" @click="evidenceForm.type = 'link'; evidenceErrors = {}">Tautan</button>
                            </div>
                            <div x-show="evidenceForm.type === 'file'">
                                <label for="evidence-file" class="{{ $labelClass }}">File</label>
                                <input id="evidence-file" x-ref="evidenceFile" type="file" :accept="evidenceAccept" @change="pickEvidence($event)"
                                    class="block w-full text-sm text-ink file:mr-3 file:rounded-lg file:border file:border-line file:bg-white file:px-3 file:py-1.5 file:text-sm file:font-semibold hover:file:bg-soft">
                                <p x-show="!evidenceErrors.file" class="mt-1.5 text-xs text-muted" x-text="evidenceHint"></p>
                                <p x-show="evidenceErrors.file" x-text="evidenceErrors.file" class="{{ $errorClass }}"></p>
                            </div>
                            <div x-show="evidenceForm.type === 'link'">
                                <label for="evidence-url" class="{{ $labelClass }}">Tautan</label>
                                <input id="evidence-url" type="url" x-model="evidenceForm.url" class="{{ $inputClass }}" :class="evidenceErrors.url && 'border-red-500!'" placeholder="https://" @input="evidenceErrors.url = null">
                                <p x-show="evidenceErrors.url" x-text="evidenceErrors.url" class="{{ $errorClass }}"></p>
                            </div>
                            <div>
                                <label for="evidence-note" class="{{ $labelClass }}">Keterangan (opsional)</label>
                                <input id="evidence-note" type="text" x-model="evidenceForm.note" class="{{ $inputClass }}" maxlength="255">
                            </div>
                            <button type="submit" class="{{ $primaryButton }} self-end" :disabled="sendingEvidence" x-text="sendingEvidence ? 'Mengirim…' : 'Kirim Evidence'"></button>
                        </form>

                        <div class="mt-3">
                            <div x-show="evidenceState === 'loading'" class="h-14 animate-pulse rounded-lg bg-soft"></div>
                            <div x-show="evidenceState === 'unavailable'">@include('pages.tasks.components.pending', ['text' => 'Daftar dan pengiriman evidence menunggu API evidence.'])</div>
                            <p x-show="evidenceState === 'error'" class="text-xs text-red-600">Evidence gagal dimuat.</p>
                            <ul x-show="evidenceState === 'ready' && evidences.length" class="flex flex-col gap-2">
                                <template x-for="evidence in evidences" :key="evidence.id">
                                    <li class="flex items-start gap-3 rounded-lg border border-line p-3">
                                        <span class="mt-0.5 text-label">
                                            <x-icon name="link" class="size-4" x-show="evidence.url" />
                                            <x-icon name="file" class="size-4" x-show="!evidence.url" />
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <a :href="evidence.url ?? evidence.file_url" target="_blank" rel="noopener noreferrer" class="block truncate text-sm font-semibold text-primary hover:underline" x-text="evidence.file_name ?? evidence.url"></a>
                                            <p x-show="evidence.note" class="mt-0.5 text-sm text-ink" x-text="evidence.note"></p>
                                            <p class="mt-0.5 text-xs text-muted"><span x-text="evidence.submitter?.fullname ?? '-'"></span> · <span x-text="formatDateTime(evidence.created_at)"></span></p>
                                        </div>
                                    </li>
                                </template>
                            </ul>
                            <p x-show="evidenceState === 'ready' && !evidences.length" class="rounded-lg border border-dashed border-line bg-soft px-3 py-4 text-center text-sm text-muted">Belum ada evidence yang dikirim.</p>
                        </div>
                    </section>

                    {{-- Histori aktivitas (TASK-08) --}}
                    <section>
                        <h3 class="{{ $sectionTitle }}">Histori Aktivitas</h3>
                        <div class="mt-3">
                            <div x-show="activityState === 'loading'" class="flex animate-pulse flex-col gap-2">
                                <div class="h-12 rounded-lg bg-soft"></div><div class="h-12 rounded-lg bg-soft"></div>
                            </div>
                            <div x-show="activityState === 'unavailable'">@include('pages.tasks.components.pending', ['text' => 'Histori aktivitas diambil dari API histori tugas.'])</div>
                            <div x-show="activityState === 'error'" class="flex items-center justify-between rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                                Histori gagal dimuat.
                                <button type="button" class="font-semibold underline" @click="loadActivities()">Coba lagi</button>
                            </div>
                            <ol x-show="activityState === 'ready' && activities.length" class="relative flex flex-col gap-4 border-l border-line pl-5">
                                <template x-for="activity in activities" :key="activity.id">
                                    <li class="relative">
                                        <span class="absolute top-1 -left-[25px] size-2.5 rounded-full border-2 border-surface bg-primary"></span>
                                        <p class="text-sm font-semibold text-ink" x-text="activity.label"></p>
                                        <ul x-show="activity.changes.length" class="mt-1 flex flex-col gap-0.5 text-xs text-muted">
                                            <template x-for="change in activity.changes" :key="change.field">
                                                <li>
                                                    <span x-text="`${change.label}:`"></span>
                                                    <span x-show="change.hidden">diubah</span>
                                                    <span x-show="!change.hidden"><span class="line-through" x-text="change.before"></span> → <span class="font-semibold text-ink" x-text="change.after"></span></span>
                                                </li>
                                            </template>
                                        </ul>
                                        <p class="mt-0.5 text-xs text-muted"><span x-text="activity.causer?.fullname ?? 'Sistem'"></span> · <span x-text="formatDateTime(activity.created_at)"></span></p>
                                    </li>
                                </template>
                            </ol>
                            <p x-show="activityState === 'ready' && !activities.length" class="rounded-lg border border-dashed border-line bg-soft px-3 py-4 text-center text-sm text-muted">Belum ada aktivitas yang tercatat.</p>
                        </div>
                    </section>
                </div>
            </template>
        </div>
    </aside>
</div>
