<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ __('filament-panels::layout.direction') ?? 'ltr' }}"
    class="antialiased fi"
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Workstation' }} - Mibeko</title>

    @filamentStyles
    {{ filament()->getTheme()->getHtml() }}
    @vite(['resources/css/app.css'])

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Merriweather:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
    
    <style>
        [x-cloak] { display: none !important; }
        body, html {
            height: 100%;
            margin: 0;
            overflow: hidden;
        }
        .fi-main {
            padding: 0 !important;
            height: 100vh;
        }
    </style>
</head>
<body class="font-sans text-gray-900 antialiased h-screen overflow-hidden bg-zinc-50 dark:bg-zinc-950">
    <div class="h-12 bg-[#1e3a8a] text-white flex items-center justify-between px-4 shrink-0 shadow-md relative z-50">
        <div class="flex items-center gap-3">
            <a href="{{ route('filament.portail.resources.legal-documents.index') }}" class="text-blue-200 hover:text-white transition-colors">
                <x-heroicon-o-arrow-left class="w-5 h-5" />
            </a>
            <span class="font-semibold tracking-wide flex items-center gap-2">
                <x-heroicon-o-briefcase class="w-5 h-5 opacity-70" />
                Mibeko Workstation
            </span>
        </div>
        <div class="flex items-center gap-4 text-sm">
            <div class="flex items-center gap-2 opacity-80">
                <div class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></div>
                En ligne
            </div>
        </div>
    </div>

    <main class="h-[calc(100vh-3rem)] w-full flex">
        {{ $slot }}
    </main>

    @livewire(\Filament\Livewire\Notifications::class)

    @filamentScripts(withCore: true)
    @livewireScripts
</body>
</html>
