@php
    $card = 'rounded-panel border border-line bg-surface shadow-sm';
    $tileLabel = 'text-xs font-semibold text-label';
    $tileValue = 'mt-1 text-2xl font-bold text-ink';
    $canManageDivisions = auth()->user()->can('create', App\Models\Division::class);
@endphp

<div
    x-data="divisionManagement(@js($canManageDivisions), tab === 'divisions')"
    @organization-tab-changed.window="activate($event.detail)"
    class="min-w-0"
>
        {{-- Ringkasan --}}
        <section class="mt-6 grid gap-4 sm:grid-cols-3" aria-label="Ringkasan Divisi">
            <article class="{{ $card }} p-4">
                <p class="{{ $tileLabel }}">Total Divisi</p>
                <p class="{{ $tileValue }}" x-text="loading ? '—' : divisions.length">—</p>
                <p class="mt-1 text-sm text-muted">Terdaftar di sistem</p>
            </article>

            <article class="{{ $card }} p-4">
                <p class="{{ $tileLabel }}">Anggota Divisi</p>
                <p class="{{ $tileValue }}" x-text="loading ? '—' : totalMembers">—</p>
                <p class="mt-1 text-sm text-muted">Akun yang tergabung di divisi</p>
            </article>

            <article class="{{ $card }} p-4">
                <p class="{{ $tileLabel }}">Divisi Tanpa Anggota</p>
                <p class="{{ $tileValue }}" x-text="loading ? '—' : emptyDivisions">—</p>
                <p class="mt-1 text-sm text-muted">Belum memiliki anggota</p>
            </article>
        </section>

        <section class="{{ $card }} mt-6 p-5" aria-labelledby="division-list-title">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 id="division-list-title" class="text-base font-semibold text-ink">Daftar Divisi</h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ $canManageDivisions ? 'Tambah, ubah, atau hapus divisi yang tidak lagi digunakan.' : 'Hanya manager yang dapat menambah, mengubah, atau menghapus divisi.' }}
                    </p>
                </div>

                @if ($canManageDivisions)
                    <button
                        type="button"
                        class="inline-flex h-9 shrink-0 items-center justify-center gap-2 self-start rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50"
                        @click="openCreate()"
                    >
                        Tambah Divisi
                    </button>
                @endif
            </div>

            @include('pages.settings.components.division-filter')
            @include('pages.settings.components.division-table', ['canManage' => $canManageDivisions])
        </section>

        @if ($canManageDivisions)
            @include('pages.settings.components.division-create-modal')
            @include('pages.settings.components.division-edit-modal')
            @include('pages.settings.components.division-delete-modal')
        @endif
</div>
