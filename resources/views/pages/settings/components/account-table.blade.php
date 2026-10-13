<div class="mt-5 overflow-hidden rounded-2xl border border-line bg-white/70 dark:bg-white/40">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-left text-sm">
            <thead class="bg-[#eaf0f8] text-[11px] font-bold tracking-widest text-label uppercase dark:bg-[#dbe3ee]">
                <tr>
                    <th scope="col" class="px-6 py-4">Nama Lengkap</th>
                    <th scope="col" class="px-6 py-4">Divisi</th>
                    <th scope="col" class="px-6 py-4">Role</th>
                    <th scope="col" class="px-6 py-4">Status</th>
                    <th scope="col" class="px-6 py-4 text-right">Aksi</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-line">
                <template x-for="user in filteredUsers" :key="user.id">
                    <tr class="transition hover:bg-soft/70">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2">
                                <span class="font-semibold text-ink" x-text="user.fullname"></span>
                                <span
                                    x-show="isSelf(user)"
                                    class="rounded-full bg-brand-indigo/10 px-2 py-0.5 text-[10px] font-bold tracking-wide text-brand-indigo uppercase"
                                >Anda</span>
                            </div>
                            <span class="text-xs text-muted" x-text="'@' + user.username"></span>
                        </td>
                        <td class="px-6 py-4">
                            <span x-show="user.division" class="inline-block rounded-md bg-brand-logo px-2.5 py-1 text-xs font-bold text-white" x-text="user.division?.name"></span>
                            <span x-show="!user.division" class="text-xs text-label">Tanpa divisi</span>
                        </td>
                        <td class="px-6 py-4 text-muted" x-text="user.role.name"></td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700">
                                <span class="size-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                                Aktif
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right whitespace-nowrap">
                            <button
                                type="button"
                                class="rounded-md px-2 py-1 font-medium text-brand-indigo hover:bg-brand-indigo/10 focus-visible:outline-2 focus-visible:outline-brand-indigo"
                                @click="openEdit(user)"
                            >Edit</button>
                            <button
                                type="button"
                                x-show="!isSelf(user)"
                                class="ml-2 rounded-md px-2 py-1 font-medium text-red-600 hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-red-600"
                                @click="openDelete(user)"
                            >Hapus</button>
                            <span
                                x-show="isSelf(user)"
                                class="ml-2 inline-block w-[52px] px-2 py-1 text-left text-xs text-label"
                                title="Anda tidak dapat menghapus akun sendiri"
                            >—</span>
                        </td>
                    </tr>
                </template>

                <tr x-show="loading" x-cloak>
                    <td colspan="5" class="px-6 py-12 text-center text-sm text-muted">Memuat daftar akun…</td>
                </tr>

                <tr x-show="!loading && loadError" x-cloak>
                    <td colspan="5" class="px-6 py-12 text-center">
                        <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
                        <button type="button" class="mt-3 text-xs font-semibold text-brand-indigo hover:underline" @click="fetchUsers()">Coba lagi</button>
                    </td>
                </tr>

                <tr x-show="!loading && !loadError && filteredUsers.length === 0" x-cloak>
                    <td colspan="5" class="px-6 py-12 text-center">
                        <p class="text-sm font-medium text-ink">Tidak ada akun yang cocok.</p>
                        <p class="mt-1 text-xs text-muted">Coba ubah kata kunci atau pilih divisi lain.</p>
                        <button type="button" class="mt-3 text-xs font-semibold text-brand-indigo hover:underline" @click="resetFilters()">Reset filter</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
