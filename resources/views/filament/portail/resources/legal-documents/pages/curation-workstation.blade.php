<div
    x-data="{ tab: 'editor', showPdf: true, showStructure: true }"
    class="h-full w-full min-h-0 bg-zinc-50 dark:bg-zinc-950 font-sans text-zinc-900 dark:text-zinc-100 overflow-hidden"
>
        <div class="lg:hidden border-b border-zinc-200 dark:border-zinc-800 bg-white/70 dark:bg-zinc-950/70 backdrop-blur supports-[backdrop-filter]:bg-white/60">
            <div class="px-3 py-2 flex items-center justify-between gap-2">
                <div class="flex items-center gap-1 rounded-lg bg-zinc-100 dark:bg-zinc-900 p-1">
                    <button
                        type="button"
                        x-on:click="tab = 'structure'"
                        x-bind:class="tab === 'structure' ? 'bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 shadow-sm' : 'text-zinc-600 dark:text-zinc-300'"
                        class="px-3 py-1.5 text-xs font-semibold rounded-md transition"
                    >
                        Structure
                    </button>
                    <button
                        type="button"
                        x-on:click="tab = 'editor'"
                        x-bind:class="tab === 'editor' ? 'bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 shadow-sm' : 'text-zinc-600 dark:text-zinc-300'"
                        class="px-3 py-1.5 text-xs font-semibold rounded-md transition"
                    >
                        Éditeur
                    </button>
                    <button
                        type="button"
                        x-on:click="tab = 'pdf'"
                        x-bind:class="tab === 'pdf' ? 'bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 shadow-sm' : 'text-zinc-600 dark:text-zinc-300'"
                        class="px-3 py-1.5 text-xs font-semibold rounded-md transition"
                    >
                        PDF
                    </button>
                </div>
                <div class="text-[11px] font-medium text-zinc-500 truncate max-w-[40%]">
                    {{ $record->titre_officiel }}
                </div>
            </div>
        </div>

        <div class="flex h-full w-full min-h-0">
        <div
            x-cloak
            x-show="showStructure"
            class="hidden lg:flex w-72 xl:w-80 min-w-[16rem] max-w-[26rem] border-r border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 flex-col shrink-0 z-10 shadow-[2px_0_15px_-3px_rgba(0,0,0,0.05)] min-h-0"
        >
            <div class="p-4 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50">
                <h3 class="font-semibold text-sm tracking-wide text-zinc-800 dark:text-zinc-200">Structure du Document</h3>
                <p class="text-xs text-zinc-500 mt-1 truncate">{{ $record->titre_officiel }}</p>
            </div>
            <div class="flex-1 min-h-0 overflow-y-auto p-4">
                @livewire('curation.structure-tree', ['document' => $record])
            </div>
        </div>

        <div
            x-cloak
            x-show="tab === 'editor'"
            class="flex-1 flex flex-col min-w-0 min-h-0 bg-white dark:bg-zinc-950 relative z-0"
        >
            <div class="h-14 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between px-4 lg:px-6 bg-white dark:bg-zinc-950">
                <div class="flex items-center gap-4">
                    <h2 class="font-serif font-bold text-lg text-zinc-800 dark:text-zinc-100 whitespace-nowrap">
                        {{ $selectedArticleLabel ?? 'Article …' }}
                    </h2>
                    @if(($selectedArticleStatus ?? 'draft') === 'validated')
                        <span class="px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">Validé</span>
                    @else
                        <span class="px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">Brouillon</span>
                    @endif
                </div>
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        x-on:click="showStructure = !showStructure"
                        class="hidden lg:inline-flex px-3 py-1.5 text-sm font-medium rounded-md text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800 transition-colors"
                    >
                        {{ __('Structure') }}
                    </button>
                    <button
                        type="button"
                        x-on:click="showPdf = !showPdf"
                        class="hidden lg:inline-flex px-3 py-1.5 text-sm font-medium rounded-md text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800 transition-colors"
                    >
                        {{ __('PDF') }}
                    </button>
                    <button
                        type="button"
                        x-on:click="$dispatch('saveCurrentArticle')"
                        class="px-3 py-1.5 text-sm font-medium rounded-md text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800 transition-colors"
                    >
                        Enregistrer
                    </button>
                    <button
                        type="button"
                        x-on:click="$dispatch('createNewArticleVersion')"
                        class="px-3 py-1.5 text-sm font-medium rounded-md bg-[#1e3a8a] text-white hover:bg-blue-800 transition-colors shadow-sm"
                    >
                        Nouvelle Version
                    </button>
                </div>
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto">
                <div class="max-w-3xl mx-auto py-8 lg:py-12 px-4 lg:px-8 min-h-full">
                    @livewire('curation.content-editor', ['document' => $record, 'articleId' => $selectedArticleId])
                </div>
            </div>
        </div>

        <div
            x-cloak
            x-show="tab === 'pdf'"
            class="lg:hidden flex-1 flex flex-col min-h-0 bg-zinc-100 dark:bg-zinc-900"
        >
            <div class="h-10 border-b border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900/80 flex items-center justify-between px-4">
                <span class="text-xs font-medium text-zinc-500 uppercase tracking-wider">Document Source</span>
            </div>
            <div class="flex-1 min-h-0 bg-zinc-200/50 dark:bg-zinc-950 p-2">
                @if($isPdfAvailable && filled($pdfUrl))
                    <iframe src="{{ $pdfUrl }}#toolbar=0" class="w-full h-full rounded shadow-sm border border-zinc-200 dark:border-zinc-800"></iframe>
                @else
                    <div class="w-full h-full flex flex-col items-center justify-center text-zinc-400">
                        <x-heroicon-o-document class="w-12 h-12 mb-2 opacity-20" />
                        <p class="text-sm">PDF indisponible</p>
                    </div>
                @endif
            </div>
        </div>

        <div
            x-cloak
            x-show="tab === 'structure'"
            class="lg:hidden flex-1 flex flex-col min-h-0 bg-white dark:bg-zinc-900"
        >
            <div class="p-4 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50">
                <h3 class="font-semibold text-sm tracking-wide text-zinc-800 dark:text-zinc-200">Structure du Document</h3>
                <p class="text-xs text-zinc-500 mt-1 truncate">{{ $record->titre_officiel }}</p>
            </div>
            <div class="flex-1 min-h-0 overflow-y-auto p-4">
                @livewire('curation.structure-tree', ['document' => $record])
            </div>
        </div>

        <div
            x-cloak
            x-show="showPdf"
            class="hidden lg:flex w-[22rem] xl:w-[34rem] min-w-[20rem] max-w-[45rem] border-l border-zinc-200 dark:border-zinc-800 bg-zinc-100 dark:bg-zinc-900 flex-col shrink-0 z-10 relative min-h-0"
        >
            <div class="h-10 border-b border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900/80 flex items-center justify-between px-4">
                <span class="text-xs font-medium text-zinc-500 uppercase tracking-wider">Document Source</span>
                <button class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                    <x-heroicon-o-arrows-pointing-out class="w-4 h-4" />
                </button>
            </div>
            <div class="flex-1 min-h-0 bg-zinc-200/50 dark:bg-zinc-950 p-2">
                @if($isPdfAvailable && filled($pdfUrl))
                    <iframe src="{{ $pdfUrl }}#toolbar=0" class="w-full h-full rounded shadow-sm border border-zinc-200 dark:border-zinc-800"></iframe>
                @else
                    <div class="w-full h-full flex flex-col items-center justify-center text-zinc-400">
                        <x-heroicon-o-document class="w-12 h-12 mb-2 opacity-20" />
                        <p class="text-sm">PDF indisponible</p>
                    </div>
                @endif
            </div>
        </div>

        </div>
</div>
