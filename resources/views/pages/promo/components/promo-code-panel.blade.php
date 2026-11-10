{{-- Kelola Kode tab: add → bulk entry → unregistered code alert → search and filter → list → pagination. --}}
@php
    $button = 'inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:w-auto';
@endphp

<div class="mt-6 grid gap-4">
    <section x-data="promoCodeManagement" @promo-codes-changed.window="reload()" @promo-code-register.window="openRegister($event.detail)" class="{{ $card }} min-w-0 p-5" aria-labelledby="promo-list-title">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h2 id="promo-list-title" class="text-base font-semibold text-ink">Daftar Kode Promo</h2>
                <p class="mt-1 text-sm text-muted">Petakan setiap kode promo ke platform, funnel, dan source yang tepat.</p>
            </div>

            <div class="flex shrink-0 flex-col gap-2 sm:flex-row">
                <button type="button" class="{{ $button }} bg-primary text-white hover:bg-primary-hover" @click="openCreate()">
                    Tambah kode promo
                </button>
                <button type="button" class="{{ $button }} border border-line bg-white text-ink hover:bg-soft" @click="$dispatch('promo-bulk-open')">
                    <x-icon name="table" />
                    Input massal
                </button>
            </div>
        </div>

        @include('pages.promo.components.promo-unregistered-alert')
        @include('pages.promo.components.promo-code-filter')
        @include('pages.promo.components.promo-code-table')

        @include('pages.promo.components.promo-code-form-modal')
        @include('pages.promo.components.promo-code-confirm-modal')
        @include('pages.promo.components.promo-code-delete-modal')
    </section>

    @include('pages.promo.components.promo-code-bulk-modal')
</div>
