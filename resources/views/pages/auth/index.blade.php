@extends('layouts.app')

@section('title', 'Masuk')
@section('body_class', 'auth-body')

@push('scripts')
    @vite('resources/js/pages/auth/index.js')
@endpush

@section('content')
    <main class="auth">
        <section class="auth-card" aria-labelledby="auth-title">
            <header class="auth-header">
                <div class="auth-logo" aria-hidden="true">SM</div>
                <h1 id="auth-title" class="auth-title">SiMarsel<span class="auth-title-dot">.</span></h1>
                <p class="auth-subtitle">Masuk untuk mengakses dashboard.</p>
            </header>

            <div id="login-alert" class="auth-alert" role="alert" hidden></div>

            <form id="login-form" class="auth-form" method="POST" action="{{ route('api.login') }}" data-redirect="{{ route('dashboard') }}" novalidate>
                @csrf

                <div class="auth-field">
                    <label for="username" class="auth-label">Username</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="auth-input"
                        placeholder="Masukkan username"
                        autocomplete="username"
                        autocapitalize="none"
                        spellcheck="false"
                        aria-describedby="username-error"
                        required
                    >
                    <p id="username-error" class="auth-error" hidden></p>
                </div>

                <div class="auth-field">
                    <label for="password" class="auth-label">Password</label>
                    <div class="auth-control">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="auth-input auth-input--toggle"
                            placeholder="Masukkan password"
                            autocomplete="current-password"
                            required
                        >
                        <button type="button" id="toggle-password" class="auth-toggle" aria-label="Tampilkan password" aria-pressed="false">
                            <svg class="auth-icon-eye" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                            <svg class="auth-icon-eye-off" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M3 3l18 18" />
                                <path d="M10.6 5.1A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6C3.9 8.3 2 12 2 12s3.6 7 10 7a9.8 9.8 0 0 0 5.4-1.6" />
                                <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" />
                            </svg>
                        </button>
                    </div>
                    <div class="auth-row">
                        <a href="#" class="auth-link">Lupa password?</a>
                    </div>
                </div>

                <button type="submit" id="login-button" class="auth-button" disabled>
                    <span class="auth-spinner" aria-hidden="true"></span>
                    <span class="auth-button-text">Masuk</span>
                </button>
            </form>
        </section>
    </main>
@endsection
