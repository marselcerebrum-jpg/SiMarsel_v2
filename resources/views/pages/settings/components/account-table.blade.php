@php
    $columns = $canManage ? 4 : 3;
@endphp

<div class="mt-4 overflow-hidden rounded-lg border border-line">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-left text-sm">
            <thead class="bg-soft text-xs font-semibold text-label">
                <tr>
                    <th scope="col" class="px-4 py-3">Full Name</th>
                    <th scope="col" class="px-4 py-3">Division</th>
                    <th scope="col" class="px-4 py-3">Role</th>
                    @if ($canManage)
                        <th scope="col" class="px-4 py-3 text-right">Actions</th>
                    @endif
                </tr>
            </thead>

            <tbody class="divide-y divide-line">
                <template x-for="user in filteredUsers" :key="user.id">
                    <tr class="hover:bg-soft/60">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="font-semibold text-ink" x-text="user.fullname"></span>
                                <span
                                    x-show="isSelf(user)"
                                    class="inline-flex items-center rounded-md bg-primary-soft px-2 py-0.5 text-xs font-semibold text-primary-hover"
                                >You</span>
                            </div>
                            <span class="text-xs text-muted" x-text="'@' + user.username"></span>
                        </td>
                        <td class="px-4 py-3">
                            <span x-show="user.division" class="inline-flex items-center rounded-md bg-primary-soft px-2 py-0.5 text-xs font-semibold text-primary-hover" x-text="user.division?.name"></span>
                            <span x-show="!user.division" class="inline-flex items-center rounded-md bg-soft px-2 py-0.5 text-xs font-semibold text-muted">No Division</span>
                        </td>
                        <td class="px-4 py-3 text-muted" x-text="user.role.name"></td>
                        @if ($canManage)
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <button
                                type="button"
                                class="rounded-md px-2 py-1 font-semibold text-primary hover:text-primary-hover focus-visible:outline-2 focus-visible:outline-primary"
                                @click="openEdit(user)"
                            >Edit</button>
                            <button
                                type="button"
                                x-show="!isSelf(user)"
                                class="ml-2 rounded-md px-2 py-1 font-semibold text-red-600 hover:text-red-700 focus-visible:outline-2 focus-visible:outline-red-600"
                                @click="openDelete(user)"
                            >Delete</button>
                            <span
                                x-show="isSelf(user)"
                                class="ml-2 inline-block w-[52px] px-2 py-1 text-left text-xs text-label"
                                title="Anda tidak dapat menghapus akun sendiri"
                            >—</span>
                        </td>
                        @endif
                    </tr>
                </template>

                <tr x-show="loading" x-cloak>
                    <td colspan="{{ $columns }}" class="px-4 py-10 text-center text-sm text-muted">Memuat daftar akun…</td>
                </tr>

                <tr x-show="!loading && loadError" x-cloak>
                    <td colspan="{{ $columns }}" class="px-4 py-10 text-center">
                        <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
                        <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="fetchUsers()">Retry</button>
                    </td>
                </tr>

                <tr x-show="!loading && !loadError && filteredUsers.length === 0" x-cloak>
                    <td colspan="{{ $columns }}" class="px-4 py-10 text-center">
                        <p class="text-sm font-medium text-ink">Tidak ada akun yang cocok.</p>
                        <p class="mt-1 text-xs text-muted">Coba ubah kata kunci atau pilih divisi lain.</p>
                        <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="resetFilters()">Reset Filter</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
