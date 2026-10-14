@php
    $columns = $canManage ? 4 : 3;
@endphp

<div class="mt-5 overflow-hidden rounded-2xl border border-line bg-white/70 dark:bg-white/40">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[560px] text-left text-sm">
            <thead class="bg-[#eaf0f8] text-[11px] font-bold tracking-widest text-label uppercase dark:bg-[#dbe3ee]">
                <tr>
                    <th scope="col" class="px-6 py-4">Kode</th>
                    <th scope="col" class="px-6 py-4">Nama Divisi</th>
                    <th scope="col" class="px-6 py-4">Anggota</th>
                    @if ($canManage)
                        <th scope="col" class="px-6 py-4 text-right">Aksi</th>
                    @endif
                </tr>
            </thead>

            <tbody class="divide-y divide-line">
                <template x-for="division in filteredDivisions" :key="division.id">
                    <tr class="transition hover:bg-soft/70">
                        <td class="px-6 py-4">
                            <span class="inline-block rounded-md bg-brand-logo px-2.5 py-1 text-xs font-bold text-white" x-text="division.code_division"></span>
                        </td>
                        <td class="px-6 py-4 font-semibold text-ink" x-text="division.name"></td>
                        <td class="px-6 py-4">
                            <span x-show="division.accounts_count" class="text-muted" x-text="division.accounts_count + ' akun'"></span>
                            <span x-show="!division.accounts_count" class="text-xs text-label">Belum ada anggota</span>
                        </td>
                        @if ($canManage)
                            <td class="px-6 py-4 text-right whitespace-nowrap">
                                <button
                                    type="button"
                                    class="rounded-md px-2 py-1 font-medium text-brand-indigo hover:bg-brand-indigo/10 focus-visible:outline-2 focus-visible:outline-brand-indigo"
                                    @click="openEdit(division)"
                                >Edit</button>
                                <button
                                    type="button"
                                    class="ml-2 rounded-md px-2 py-1 font-medium text-red-600 hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-red-600"
                                    @click="openDelete(division)"
                                >Hapus</button>
                            </td>
                        @endif
                    </tr>
                </template>

                <tr x-show="loading" x-cloak>
                    <td colspan="{{ $columns }}" class="px-6 py-12 text-center text-sm text-muted">Memuat daftar divisi…</td>
                </tr>

                <tr x-show="!loading && loadError" x-cloak>
                    <td colspan="{{ $columns }}" class="px-6 py-12 text-center">
                        <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
                        <button type="button" class="mt-3 text-xs font-semibold text-brand-indigo hover:underline" @click="fetchDivisions()">Coba lagi</button>
                    </td>
                </tr>

                <tr x-show="!loading && !loadError && divisions.length === 0" x-cloak>
                    <td colspan="{{ $columns }}" class="px-6 py-12 text-center">
                        <p class="text-sm font-medium text-ink">Belum ada divisi.</p>
                        @if ($canManage)
                            <button type="button" class="mt-3 text-xs font-semibold text-brand-indigo hover:underline" @click="tab = 'create'">Buat divisi pertama</button>
                        @endif
                    </td>
                </tr>

                <tr x-show="!loading && !loadError && divisions.length > 0 && filteredDivisions.length === 0" x-cloak>
                    <td colspan="{{ $columns }}" class="px-6 py-12 text-center">
                        <p class="text-sm font-medium text-ink">Tidak ada divisi yang cocok.</p>
                        <p class="mt-1 text-xs text-muted">Coba ubah kata kunci pencarian.</p>
                        <button type="button" class="mt-3 text-xs font-semibold text-brand-indigo hover:underline" @click="search = ''">Reset pencarian</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
