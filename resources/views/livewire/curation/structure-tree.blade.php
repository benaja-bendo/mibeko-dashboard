<div>
    @if($rootNodes->isNotEmpty())
        <div class="mb-3">
            <label class="text-[11px] font-semibold tracking-wide text-zinc-500 uppercase">Texte juridique</label>
            <select
                wire:model.live="selectedRootPath"
                class="mt-1 w-full rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm text-zinc-800 dark:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-blue-500/30"
            >
                @foreach($rootNodes as $root)
                    <option value="{{ $root->tree_path }}">
                        {{ trim($root->type_unite . ' ' . ($root->numero ?? '') . ' ' . ($root->titre ?? '')) }}
                    </option>
                @endforeach
            </select>
        </div>
    @endif

    @if(empty($tree))
        <div class="flex flex-col items-center justify-center h-40 text-zinc-400">
            <x-heroicon-o-folder-open class="w-8 h-8 mb-2 opacity-50" />
            <p class="text-sm">Aucune structure trouvée.</p>
            <button class="mt-2 text-xs text-blue-600 hover:underline">Générer via IA</button>
        </div>
    @else
        <div class="space-y-2">
            @foreach($tree as $node)
                @include('livewire.curation.partials.structure-node', ['node' => $node, 'depth' => 0])
            @endforeach
        </div>
    @endif
</div>
