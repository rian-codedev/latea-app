@props(['code' => '', 'title' => '', 'message' => ''])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" x-data="{ darkMode: localStorage.getItem('darkMode') === 'true' }" :class="{ 'dark': darkMode }"
    x-init="$watch('darkMode', val => localStorage.setItem('darkMode', val))">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('assets/logo.webp') }}" class="h-2 w-2">
    <title>{{ $code }} — {{ $title }} | SMILE</title>

    {{-- Google Fonts --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap"
        rel="stylesheet">

    <style>
        /* Subtle noise texture overlay */
        .noise-bg::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.035'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 0;
        }

        /* Decorative grid pattern */
        .grid-pattern {
            background-image:
                linear-gradient(rgba(92, 127, 92, 0.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(92, 127, 92, 0.05) 1px, transparent 1px);
            background-size: 40px 40px;
        }

        .dark .grid-pattern {
            background-image:
                linear-gradient(rgba(168, 192, 168, 0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(168, 192, 168, 0.04) 1px, transparent 1px);
        }

        /* Animated gradient orb */
        .orb {
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.18;
        }

        .dark .orb {
            opacity: 0.12;
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: #a8c0a8;
            border-radius: 3px;
        }

        .dark ::-webkit-scrollbar-thumb {
            background: #486548;
        }

        /* Delay utilities */
        .delay-100 {
            animation-delay: 0.1s;
        }

        .delay-200 {
            animation-delay: 0.2s;
        }

        .delay-300 {
            animation-delay: 0.3s;
        }

        .delay-400 {
            animation-delay: 0.4s;
        }

        .delay-500 {
            animation-delay: 0.5s;
        }

        .delay-600 {
            animation-delay: 0.6s;
        }

        .opacity-0-init {
            opacity: 0;
        }
    </style>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>

<body
    class="noise-bg font-body bg-stone-50 text-stone-800 antialiased transition-colors duration-500 dark:bg-stone-950 dark:text-stone-100">

    <section class="grid-pattern relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-16">

        {{-- Decorative Orbs --}}
        <div class="bg-sage-400 orb animate-float absolute -left-24 top-1/4 h-96 w-96"></div>
        <div class="bg-sage-300 orb animate-float-slow absolute -right-24 bottom-1/4 h-80 w-80"></div>
        <div class="orb animate-float absolute left-1/3 top-3/4 h-64 w-64 bg-stone-300 dark:bg-stone-700"
            style="animation-delay: -3s;"></div>

        {{-- Dark mode toggle --}}
        <button type="button" @click="darkMode = !darkMode"
            class="hover:border-sage-300 dark:hover:border-sage-700 absolute right-6 top-6 z-20 flex h-9 w-9 items-center justify-center rounded-full border border-stone-200 bg-white/70 text-stone-500 backdrop-blur transition-colors dark:border-stone-700 dark:bg-stone-800/70 dark:text-stone-400"
            aria-label="{{ __('Ganti Tema') }}">
            <svg x-show="!darkMode" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
            </svg>
            <svg x-show="darkMode" x-cloak class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
            </svg>
        </button>

        <div class="relative z-10 mx-auto w-full max-w-lg text-center">

            {{-- Logo --}}
            <a wire:navigate
                class="flex shrink-0 flex-col items-center gap-2.5 px-5 no-underline dark:border-stone-800">
                <div class="error-logo-icon">
                    <img src="{{ asset('assets/logo.webp') }}" alt="Logo" class="w-5xl" />
                </div>
                <div class="min-w-0">
                    <div class="error-logo-name">Latea App</div>
                    <div class="error-logo-sub">Point of Sale Information System</div>
                </div>
            </a>

            {{-- Big Number --}}
            <h1
                class="opacity-0-init animate-fade-up font-display text-[6.5rem] font-bold leading-none text-stone-900 delay-200 sm:text-[9rem] dark:text-stone-50">
                {{ $code }}
            </h1>

            {{-- Title --}}
            <h2
                class="opacity-0-init animate-fade-up font-display mb-4 text-2xl font-bold text-stone-800 delay-300 sm:text-3xl dark:text-stone-100">
                {{ $title }}
            </h2>

            {{-- Message --}}
            <p
                class="opacity-0-init animate-fade-up delay-400 mx-auto mb-10 max-w-sm leading-relaxed text-stone-600 dark:text-stone-400">
                {{ $message }}
            </p>

            {{-- CTA Buttons --}}
            <div class="opacity-0-init animate-fade-up flex flex-col justify-center gap-3 delay-500 sm:flex-row">
                <a href="/" wire:navigate
                    class="bg-sage-600 dark:bg-sage-500 hover:bg-sage-700 dark:hover:bg-sage-400 hover:shadow-sage-600/25 inline-flex items-center justify-center gap-2.5 rounded-xl px-5 py-2 text-sm font-medium text-white shadow-md transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1h3a1 1 0 001-1V10" />
                    </svg>
                    {{ __('Back to Home') }}
                </a>
            </div>

            {{ $slot ?? '' }}
        </div>
    </section>

    @livewireScripts
    @fluxScripts
</body>

</html>
