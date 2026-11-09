@php
    $card = 'rounded-panel border border-line bg-surface shadow-sm';
    $tileLabel = 'text-xs font-semibold text-label';
    $tileValue = 'mt-1 text-2xl font-bold text-ink';

    // Every role can read the account list; only Manager can create, edit, and delete.
    $canManageAccounts = auth()->user()->can('create', App\Models\Account::class);
@endphp

<div
    x-data="accountSettings(@js($roles), @js($divisions), @js($canManageAccounts), tab === 'accounts')"
    @organization-tab-changed.window="activate($event.detail)"
    class="min-w-0"
>
        {{-- Ringkasan --}}
        <section class="{{ $card }} mt-6 p-5" aria-label="Ringkasan Akun">
            <div class="flex flex-col sm:flex-row sm:items-stretch">
                <div class="pb-4 sm:w-1/4 sm:pb-0 sm:pr-6">
                <p class="{{ $tileLabel }}">Total Akun</p>
                <p class="{{ $tileValue }}" x-text="loading ? '—' : accountSummary.total">—</p>
                <p class="mt-1 text-sm text-muted">Terdaftar di sistem</p>
                </div>

                <div class="grid flex-1 divide-y divide-line border-t border-line sm:grid-cols-3 sm:divide-x sm:divide-y-0 sm:border-t-0 sm:border-l">
                    @foreach ($roles as $role)
                        <div class="py-4 sm:px-6 sm:py-0">
                            <p class="{{ $tileLabel }}">{{ $role->name }}</p>
                            <p class="{{ $tileValue }}" x-text="loading ? '—' : (accountSummary.roleCounts[@js($role->code_role)] ?? 0)">—</p>
                            <p class="mt-1 text-sm text-muted">Jumlah akun dengan role {{ $role->name }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="{{ $card }} mt-6 p-5" aria-labelledby="account-list-title">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 id="account-list-title" class="text-base font-semibold text-ink">Daftar Akun</h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ $canManageAccounts ? 'Tambah, ubah, atau hapus akun anggota tim beserta role dan divisinya.' : 'Cari akun anggota tim dan saring berdasarkan divisi.' }}
                    </p>
                </div>

                @if ($canManageAccounts)
                    <button
                        type="button"
                        class="inline-flex h-9 shrink-0 items-center justify-center gap-2 self-start rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50"
                        @click="openCreate()"
                    >
                        Tambah Akun
                    </button>
                @endif
            </div>

            @include('pages.settings.components.account-filter')
            @include('pages.settings.components.account-table', ['canManage' => $canManageAccounts])
        </section>

        @if ($canManageAccounts)
            @include('pages.settings.components.account-create-modal')
            @include('pages.settings.components.account-edit-modal')
            @include('pages.settings.components.account-delete-modal')
        @endif
</div>
