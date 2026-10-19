@extends('layouts.app')

@section('body_class', 'min-h-screen bg-canvas text-ink antialiased')

@section('content')
    <div x-data="sidebar" @keydown.escape.window="open = false" class="relative min-h-screen">
        <div x-show="open" x-cloak x-transition.opacity class="fixed inset-0 z-40 bg-slate-900/50 lg:hidden" @click="open = false"></div>

        <x-sidebar />

        <div class="relative flex min-h-screen min-w-0 flex-col lg:ml-64">
            {{-- Header mobile --}}
            <header class="sticky top-0 z-30 flex items-center justify-between border-b border-navy-line bg-navy px-4 py-2.5 text-white lg:hidden">
                <div class="flex items-center gap-3">
                    <button type="button" class="inline-grid size-9 place-items-center rounded-lg hover:bg-white/10" aria-label="Open Menu" aria-controls="sidebar" :aria-expanded="open" @click="open = true">
                        <x-icon name="menu" class="size-5" />
                    </button>
                    <span class="text-base font-bold tracking-tight">SiMarsel</span>
                </div>
                <span class="rounded-md bg-white/10 px-2 py-0.5 text-[11px] font-semibold text-white">{{ auth()->user()->role->name }}</span>
            </header>

            <main class="flex-1 px-4 py-6 sm:px-6 sm:py-8">
                <div class="mx-auto max-w-7xl">
                    @yield('page')
                </div>

                <footer class="mx-auto mt-12 max-w-7xl border-t border-line pt-6 text-center">
                    <span class="text-xs font-medium text-label">
                        @yield('footer_name', 'Dashboard Analytic Marketing & Sales')
                    </span>
                </footer>
            </main>
        </div>
    </div>
@endsection
