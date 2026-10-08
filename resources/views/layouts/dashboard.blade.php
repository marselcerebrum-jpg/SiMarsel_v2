@extends('layouts.app')

@section('body_class', 'min-h-screen bg-fixed bg-linear-135 from-brand-indigo via-brand-blue to-brand-teal text-ink antialiased')

@section('content')
    <div x-data="sidebar" @keydown.escape.window="open = false" class="relative min-h-screen">
        <div class="pointer-events-none fixed inset-0 hidden bg-slate-950/35 dark:block" aria-hidden="true"></div>

        <div x-show="open" x-cloak x-transition.opacity class="fixed inset-0 z-40 bg-slate-950/60 backdrop-blur-sm lg:hidden" @click="open = false"></div>

        <x-sidebar />

        <div class="relative flex min-h-screen min-w-0 flex-col lg:ml-64">
            {{-- Header mobile --}}
            <header class="sticky top-0 z-30 flex items-center justify-between border-b border-white/10 bg-sidebar px-4 py-3 text-white lg:hidden">
                <div class="flex items-center gap-3">
                    <button type="button" class="rounded-xl bg-white/10 p-2 hover:bg-white/20" aria-label="Buka menu" aria-controls="sidebar" :aria-expanded="open" @click="open = true">
                        <x-icon name="menu" class="size-5" />
                    </button>
                    <span class="text-base font-extrabold tracking-tight">SiMarsel<span class="text-brand-teal">.</span></span>
                </div>
                <span class="rounded-lg bg-brand-indigo/30 px-2.5 py-1 text-[10px] font-bold text-indigo-100 uppercase">{{ auth()->user()->role->name }}</span>
            </header>

            <main class="flex-1 px-4 py-6 sm:px-6 sm:py-8">
                <div class="mx-auto max-w-7xl">
                    @yield('page')
                </div>

                <footer class="relative mx-auto mt-14 mb-2 pt-8 text-center before:absolute before:top-0 before:left-1/2 before:h-px before:w-[min(560px,72%)] before:-translate-x-1/2 before:bg-linear-to-r before:from-transparent before:via-white/40 before:to-transparent">
                    <span class="-mr-[.25em] inline-block text-[9.5px] font-extrabold tracking-[.16em] text-white/70 uppercase sm:text-[11px] sm:tracking-[.25em]">
                        @yield('footer_name', 'Dashboard Analytic Marketing & Sales')
                    </span>
                </footer>
            </main>
        </div>
    </div>
@endsection
