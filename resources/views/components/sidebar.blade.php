@php
    $account = auth()->user();

    $base = 'group relative flex w-full items-center gap-2.5 rounded-xl px-3 py-2.5 text-left text-[13px] font-semibold transition duration-200';
    $idle = 'text-slate-400 hover:translate-x-1 hover:bg-white/5 hover:text-white';
    $active = 'bg-white/10 text-white before:absolute before:inset-y-2.5 before:-left-4 before:w-[3px] before:rounded-full before:bg-linear-to-b before:from-brand-indigo before:to-brand-teal';
    $soon = 'text-slate-500 hover:bg-white/[0.04] hover:text-slate-300';

    $subBase = 'flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left text-xs font-bold transition duration-200';
    $subActive = 'bg-linear-135 from-brand-indigo via-brand-blue to-brand-teal text-white shadow-lg shadow-brand-indigo/30';
@endphp

<aside
    id="sidebar"
    class="fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col overflow-hidden bg-linear-to-b from-sidebar to-sidebar-deep transition-transform duration-300 ease-in-out lg:translate-x-0"
    :class="{ '-translate-x-full': !open }"
    aria-label="Menu utama"
>
    <div class="pointer-events-none absolute inset-0 bg-radial-[140%_60%_at_100%_0%] from-brand-indigo/30 to-transparent to-60%" aria-hidden="true"></div>

    {{-- 1. Logo & tombol tutup (mobile) --}}
    <div class="relative flex shrink-0 items-center justify-between px-4 pt-6 pb-5">
        <a href="{{ route('dashboard') }}" class="group flex items-center gap-3 px-2">
            <span class="grid size-10 place-items-center rounded-full border border-white/15 bg-white/10 text-sm font-extrabold text-white transition duration-300 group-hover:scale-105 group-hover:border-brand-teal">SM</span>
            <span>
                <span class="block text-base leading-tight font-extrabold text-white">SiMarsel<span class="text-brand-teal">.</span></span>
                <span class="block text-[9px] leading-tight font-bold tracking-wider text-slate-400 uppercase">PT. Cerebrum<br>Edukanesia Nusantara</span>
            </span>
        </a>
        <button type="button" class="p-2 text-slate-400 hover:text-white lg:hidden" aria-label="Tutup menu" @click="open = false">
            <x-icon name="close" class="size-5" />
        </button>
    </div>

    {{-- 2. Navigasi --}}
    <div class="relative min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 [scrollbar-color:rgba(93,96,162,0.5)_transparent] [scrollbar-width:thin]">
        @foreach ($sections as $title => $items)
            <p class="mb-2 px-2 text-[10px] font-bold tracking-widest text-slate-500 uppercase">{{ $title }}</p>

            <nav class="mb-6 space-y-1">
                @foreach ($items as $item)
                    @if ($item['locked'])
                        <button type="button" class="{{ $base }} {{ $soon }}" title="Anda tidak memiliki akses ke menu ini" @click="locked(@js($item['label']))">
                            <x-icon :name="$item['icon']" />
                            <span>{{ $item['label'] }}</span>
                            <x-icon name="lock" class="ml-auto size-3.5" />
                        </button>
                    @elseif (isset($item['children']))
                        <div x-data="{ expanded: @js($item['active']) }" class="space-y-1">
                            <button type="button" class="{{ $base }} {{ $idle }}" :aria-expanded="expanded" @click="expanded = !expanded">
                                <x-icon :name="$item['icon']" class="transition duration-200 group-hover:scale-110 group-hover:text-brand-teal" />
                                <span>{{ $item['label'] }}</span>
                                <x-icon name="chevron-down" class="ml-auto size-3 transition-transform duration-200" ::class="expanded && 'rotate-180'" />
                            </button>

                            <div x-show="expanded" @unless ($item['active']) x-cloak @endunless class="space-y-1 pl-4">
                                @foreach ($item['children'] as $child)
                                    @if ($child['url'])
                                        <a
                                            href="{{ $child['url'] }}"
                                            @class([$subBase, $subActive => $child['active'], $idle => ! $child['active']])
                                            @if ($child['active']) aria-current="page" @endif
                                        >
                                            <x-icon :name="$child['icon']" />
                                            {{ $child['label'] }}
                                        </a>
                                    @else
                                        <button type="button" class="{{ $subBase }} {{ $soon }}" title="Fitur belum dibuat" @click="soon(@js($child['label']))">
                                            <x-icon :name="$child['icon']" />
                                            {{ $child['label'] }}
                                            <span class="ml-auto rounded-md bg-white/5 px-1.5 py-0.5 text-[8.5px] font-bold tracking-wider text-slate-500 uppercase">Segera</span>
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @elseif ($item['url'])
                        <a
                            href="{{ $item['url'] }}"
                            @class([$base, $active => $item['active'], $idle => ! $item['active']])
                            @if ($item['active']) aria-current="page" @endif
                        >
                            <x-icon :name="$item['icon']" class="transition duration-200 group-hover:scale-110 group-hover:text-brand-teal" />
                            {{ $item['label'] }}
                        </a>
                    @else
                        <button type="button" class="{{ $base }} {{ $soon }}" title="Fitur belum dibuat" @click="soon(@js($item['label']))">
                            <x-icon :name="$item['icon']" />
                            {{ $item['label'] }}
                            <span class="ml-auto rounded-md bg-white/5 px-1.5 py-0.5 text-[8.5px] font-bold tracking-wider text-slate-500 uppercase">Segera</span>
                        </button>
                    @endif
                @endforeach

                @if ($loop->last)
                    <a href="{{ route('login') }}" data-logout class="{{ $base }} {{ $idle }}">
                        <x-icon name="logout" class="transition duration-200 group-hover:scale-110 group-hover:text-brand-teal" />
                        Keluar
                    </a>
                @endif
            </nav>
        @endforeach
    </div>

    {{-- 3. Akun aktif --}}
    <div class="relative shrink-0 border-t border-white/10 bg-linear-to-b from-brand-indigo/10 to-transparent px-4 pt-4 pb-5">
        <div class="rounded-2xl border border-white/10 bg-white/5 p-3.5 transition duration-300 hover:border-brand-teal/40 hover:bg-white/[0.07]">
            <div class="mb-2 flex items-center justify-between gap-2">
                <p class="text-[9px] font-bold tracking-widest text-slate-500 uppercase">Akun Aktif</p>
                <span class="shrink-0 rounded-md bg-brand-indigo/30 px-2 py-0.5 text-[9px] font-bold text-indigo-100 uppercase">{{ $account->role->name }}</span>
            </div>

            <p class="truncate text-xs leading-tight font-bold text-slate-100" title="{{ $account->fullname }}">{{ $account->fullname }}</p>
            <p class="mt-0.5 text-[10px] leading-snug font-semibold break-all text-slate-400">{{ '@'.$account->username }}</p>

            <p class="mt-2 flex items-center gap-1.5 text-[9px] font-bold tracking-wide text-brand-teal uppercase">
                <span class="inline-block size-1.5 animate-pulse rounded-full bg-brand-teal"></span>
                {{ $account->division?->name ?? 'Tanpa Divisi' }}
            </p>
        </div>
    </div>
</aside>
