@php
    $account = auth()->user();

    $base = 'relative flex h-9 w-full items-center gap-2.5 rounded-lg px-3 text-left text-[13px] font-medium transition-colors';
    $idle = 'text-slate-300 hover:bg-white/5 hover:text-white';
    $active = 'bg-white/10 text-white before:absolute before:inset-y-2 before:-left-4 before:w-[3px] before:rounded-full before:bg-primary';
    $soon = 'text-slate-500 hover:bg-white/5 hover:text-slate-300';

    $subBase = 'flex h-9 w-full items-center gap-2.5 rounded-lg px-3 text-left text-[13px] font-medium transition-colors';
    $subActive = 'bg-primary text-white';
@endphp

<aside
    id="sidebar"
    class="fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col overflow-hidden bg-navy transition-transform duration-300 ease-in-out lg:translate-x-0"
    :class="{ '-translate-x-full': !open }"
    aria-label="Main Menu"
>
    {{-- 1. Logo & tombol tutup (mobile) --}}
    <div class="relative flex shrink-0 items-center justify-between px-4 pt-6 pb-5">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-3 px-2">
            <span class="grid size-9 place-items-center rounded-lg bg-primary text-sm font-bold text-white">SM</span>
            <span>
                <span class="block text-base leading-tight font-bold text-white">SiMarsel</span>
                <span class="block text-[9px] leading-tight font-bold tracking-wider text-slate-400 uppercase">PT. Cerebrum<br>Edukanesia Nusantara</span>
            </span>
        </a>
        <button type="button" class="p-2 text-slate-400 hover:text-white lg:hidden" aria-label="Close Menu" @click="open = false">
            <x-icon name="close" class="size-5" />
        </button>
    </div>

    {{-- 2. Navigasi --}}
    <div class="relative min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 [scrollbar-color:rgba(255,255,255,0.2)_transparent] [scrollbar-width:thin]">
        @foreach ($sections as $title => $items)
            <p class="mb-2 px-2 text-[11px] font-semibold text-slate-400">{{ $title }}</p>

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
                                <x-icon :name="$item['icon']" />
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
                                            <span class="ml-auto rounded-md bg-white/5 px-1.5 py-0.5 text-[8.5px] font-bold tracking-wider text-slate-500 uppercase">Soon</span>
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
                            <x-icon :name="$item['icon']" />
                            {{ $item['label'] }}
                        </a>
                    @else
                        <button type="button" class="{{ $base }} {{ $soon }}" title="Fitur belum dibuat" @click="soon(@js($item['label']))">
                            <x-icon :name="$item['icon']" />
                            {{ $item['label'] }}
                            <span class="ml-auto rounded-md bg-white/5 px-1.5 py-0.5 text-[8.5px] font-bold tracking-wider text-slate-500 uppercase">Soon</span>
                        </button>
                    @endif
                @endforeach

                @if ($loop->last)
                    <a href="{{ route('login') }}" data-logout class="{{ $base }} {{ $idle }}">
                        <x-icon name="logout" />
                        Logout
                    </a>
                @endif
            </nav>
        @endforeach
    </div>

    {{-- 3. Akun aktif --}}
    <div class="relative shrink-0 border-t border-navy-line px-4 pt-4 pb-5">
        <div class="rounded-lg border border-navy-line p-3">
            <div class="mb-2 flex items-center justify-between gap-2">
                <p class="text-[11px] font-semibold text-slate-400">Active Account</p>
                <span class="shrink-0 rounded-md bg-white/10 px-2 py-0.5 text-[11px] font-semibold text-white">{{ $account->role->name }}</span>
            </div>

            <p class="truncate text-xs leading-tight font-bold text-slate-100" title="{{ $account->fullname }}">{{ $account->fullname }}</p>
            <p class="mt-0.5 text-[10px] leading-snug font-semibold break-all text-slate-400">{{ '@'.$account->username }}</p>

            <p class="mt-2 text-[11px] font-semibold text-emerald-300">
                {{ $account->division?->name ?? 'No Division' }}
            </p>
        </div>
    </div>
</aside>
