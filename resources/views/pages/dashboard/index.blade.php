@extends('layouts.dashboard')

@section('title', 'Dashboard')

@section('page')
    @php
        $card = 'rounded-3xl border border-white/60 bg-surface p-5 shadow-[0_24px_60px_rgba(30,41,70,0.25)] sm:p-6 dark:border-white/25 dark:bg-surface-dim';
    @endphp

    <div class="flex flex-col gap-4">
        <section class="{{ $card }}">
            <h1 class="text-2xl font-bold tracking-tight">Selamat datang, {{ auth()->user()->fullname }}<span class="text-brand-indigo">.</span></h1>
            <p class="mt-1.5 text-sm text-muted">Anda berhasil masuk. Kelola pekerjaan dan pantau progres tim dari sini.</p>
            @can('viewAny', App\Models\Account::class)
                <a href="{{ route('settings.index') }}" class="mt-4 inline-flex items-center gap-1.5 rounded-xl border border-line bg-soft px-3 py-2 text-sm font-medium text-ink transition hover:border-brand-indigo hover:text-brand-indigo">Kelola Akun →</a>
            @endcan
        </section>

        <section class="grid gap-4 sm:grid-cols-3" aria-label="Ringkasan">
            @foreach (['Pekerjaan Aktif', 'Selesai Bulan Ini', 'Anggota Tim'] as $label)
                <article class="{{ $card }} relative overflow-hidden before:absolute before:inset-x-0 before:top-0 before:h-[3px] before:bg-linear-135 before:from-brand-indigo before:via-brand-blue before:to-brand-teal">
                    <p class="text-[11px] font-bold tracking-widest text-label uppercase">{{ $label }}</p>
                    <p class="mt-1.5 text-2xl font-bold">—</p>
                </article>
            @endforeach
        </section>

        <section class="{{ $card }}">
            <h2 class="mb-4 text-[15px] font-bold">Aktivitas Terbaru</h2>
            <div class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-line bg-soft px-4 py-7 text-center text-muted">
                <x-icon name="file" class="size-7" />
                <p class="text-[13px]">Belum ada aktivitas. Data akan tampil setelah database terhubung.</p>
            </div>
        </section>
    </div>
@endsection
