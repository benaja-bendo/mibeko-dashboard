<div class="h-full flex flex-col">
    @if($article)
        <div class="flex items-center gap-2 mb-4 px-4 py-2 bg-zinc-50 dark:bg-zinc-900/50 rounded-lg border border-zinc-200 dark:border-zinc-800 shrink-0">
            <button class="p-1.5 text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100 hover:bg-zinc-200 dark:hover:bg-zinc-800 rounded font-serif font-bold" title="Gras">B</button>
            <button class="p-1.5 text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100 hover:bg-zinc-200 dark:hover:bg-zinc-800 rounded font-serif italic" title="Italique">I</button>
            <button class="p-1.5 text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100 hover:bg-zinc-200 dark:hover:bg-zinc-800 rounded font-serif underline" title="Souligné">U</button>
            <div class="w-px h-4 bg-zinc-300 dark:bg-zinc-700 mx-2"></div>
            <button class="p-1.5 text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100 hover:bg-zinc-200 dark:hover:bg-zinc-800 rounded" title="Liste à puces">
                <x-heroicon-o-list-bullet class="w-4 h-4" />
            </button>
            <div class="flex-1"></div>
            <span class="text-xs text-emerald-600 dark:text-emerald-400 flex items-center gap-1">
                <x-heroicon-o-check-circle class="w-4 h-4" />
                Synchronisé
            </span>
        </div>

        <div class="flex-1 min-h-0 relative">
            <textarea 
                wire:model.defer="content"
                wire:keydown.debounce.1000ms="save"
                class="w-full h-full resize-none border-0 bg-transparent p-4 text-lg leading-loose font-serif text-zinc-800 dark:text-zinc-200 focus:ring-0 placeholder:text-zinc-300 dark:placeholder:text-zinc-700 selection:bg-blue-100 dark:selection:bg-blue-900/30"
                placeholder="Saisissez le contenu de l'article ici..."
                spellcheck="false"
            ></textarea>
        </div>
    @else
        <div class="flex-1 flex flex-col items-center justify-center text-zinc-400">
            <x-heroicon-o-document-text class="w-16 h-16 mb-4 opacity-20" />
            <h3 class="text-lg font-medium text-zinc-600 dark:text-zinc-300 mb-1">Aucun article sélectionné</h3>
            <p class="text-sm">Sélectionnez un élément dans l'arborescence à gauche pour commencer l'édition.</p>
        </div>
    @endif
</div>
