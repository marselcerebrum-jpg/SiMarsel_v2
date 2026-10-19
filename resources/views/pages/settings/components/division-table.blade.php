@php
    $columns = $canManage ? 4 : 3;
@endphp

<div class="mt-4 overflow-hidden rounded-lg border border-line">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[560px] text-left text-sm">
            <thead class="bg-soft text-xs font-semibold text-label">
                <tr>
                    <th scope="col" class="px-4 py-3">Code</th>
                    <th scope="col" class="px-4 py-3">Division Name</th>
                    <th scope="col" class="px-4 py-3">Members</th>
                    @if ($canManage)
                        <th scope="col" class="px-4 py-3 text-right">Actions</th>
                    @endif
                </tr>
            </thead>

            <tbody class="divide-y divide-line">
                <template x-for="division in filteredDivisions" :key="division.id">
                    <tr class="hover:bg-soft/60">
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-md bg-primary-soft px-2 py-0.5 text-xs font-semibold text-primary-hover" x-text="division.code_division"></span>
                        </td>
                        <td class="px-4 py-3 font-semibold text-ink" x-text="division.name"></td>
                        <td class="px-4 py-3">
                            <span x-show="division.accounts_count" class="text-muted" x-text="division.accounts_count + ' akun'"></span>
                            <span x-show="!division.accounts_count" class="inline-flex items-center rounded-md bg-soft px-2 py-0.5 text-xs font-semibold text-muted">Belum ada anggota</span>
                        </td>
                        @if ($canManage)
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button
                                    type="button"
                                    class="rounded-md px-2 py-1 font-semibold text-primary hover:text-primary-hover focus-visible:outline-2 focus-visible:outline-primary"
                                    @click="openEdit(division)"
                                >Edit</button>
                                <button
                                    type="button"
                                    class="ml-2 rounded-md px-2 py-1 font-semibold text-red-600 hover:text-red-700 focus-visible:outline-2 focus-visible:outline-red-600"
                                    @click="openDelete(division)"
                                >Delete</button>
                            </td>
                        @endif
                    </tr>
                </template>

                <tr x-show="loading" x-cloak>
                    <td colspan="{{ $columns }}" class="px-4 py-10 text-center text-sm text-muted">Memuat daftar divisi…</td>
                </tr>

                <tr x-show="!loading && loadError" x-cloak>
                    <td colspan="{{ $columns }}" class="px-4 py-10 text-center">
                        <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
                        <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="fetchDivisions()">Retry</button>
                    </td>
                </tr>

                <tr x-show="!loading && !loadError && divisions.length === 0" x-cloak>
                    <td colspan="{{ $columns }}" class="px-4 py-10 text-center">
                        <p class="text-sm font-medium text-ink">Belum ada divisi.</p>
                        @if ($canManage)
                            <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="openCreate()">Create First Division</button>
                        @endif
                    </td>
                </tr>

                <tr x-show="!loading && !loadError && divisions.length > 0 && filteredDivisions.length === 0" x-cloak>
                    <td colspan="{{ $columns }}" class="px-4 py-10 text-center">
                        <p class="text-sm font-medium text-ink">Tidak ada divisi yang cocok.</p>
                        <p class="mt-1 text-xs text-muted">Coba ubah kata kunci pencarian.</p>
                        <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="search = ''">Reset Search</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
