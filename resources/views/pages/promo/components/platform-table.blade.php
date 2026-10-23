{{-- Read-only: platforms are managed in the global platform master, so there are no create/edit/delete actions here. --}}
<section x-data="promoPlatformTable" class="{{ $card }} min-w-0 p-5" aria-labelledby="platform-list-title">
    <div class="flex flex-wrap items-center gap-2">
        <h2 id="platform-list-title" class="text-base font-semibold text-ink">Platform List</h2>
        <span class="inline-flex items-center gap-1 rounded-md bg-soft px-2 py-0.5 text-xs font-semibold text-muted">
            <x-icon name="lock" class="size-3" />
            Read Only
        </span>
    </div>
    <p class="mt-1 text-sm text-muted">Diambil dari master platform global. Platform tidak dapat ditambah, diubah, atau dihapus dari modul promo.</p>

    <div class="mt-4 overflow-hidden rounded-lg border border-line">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[360px] text-left text-sm">
                <thead class="bg-soft text-xs font-semibold text-label">
                    <tr>
                        <th scope="col" class="px-4 py-3">Platform Name</th>
                        <th scope="col" class="px-4 py-3">Slug</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    <template x-for="platform in platforms" :key="platform.id">
                        <tr class="hover:bg-soft/60">
                            <td class="px-4 py-3 font-semibold text-ink" x-text="platform.name"></td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-md bg-primary-soft px-2 py-0.5 text-xs font-semibold text-primary-hover" x-text="platform.slug"></span>
                            </td>
                        </tr>
                    </template>

                    <tr x-show="loading" x-cloak>
                        <td colspan="2" class="px-4 py-10 text-center text-sm text-muted">Memuat daftar platform…</td>
                    </tr>

                    <tr x-show="!loading && loadError" x-cloak>
                        <td colspan="2" class="px-4 py-10 text-center">
                            <p class="text-sm font-medium text-red-600" x-text="loadError"></p>
                            <button type="button" class="mt-2 text-xs font-semibold text-primary hover:text-primary-hover" @click="fetchPlatforms()">Retry</button>
                        </td>
                    </tr>

                    <tr x-show="!loading && !loadError && platforms.length === 0" x-cloak>
                        <td colspan="2" class="px-4 py-10 text-center text-sm font-medium text-ink">Belum ada platform di master platform global.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</section>
