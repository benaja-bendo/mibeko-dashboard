@php
    $nodeLabel = trim(($node['type_unite'] ?? '') . ' ' . ($node['numero'] ?? ''));
    $nodeTitle = trim((string) ($node['titre'] ?? ''));
    $isEditingNode = (string) ($editingNodeId ?? '') === (string) ($node['id'] ?? '');
@endphp

<div style="margin-left: {{ $depth * 0.75 }}rem;">
    <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white/70 dark:bg-zinc-900/60">
        <details class="group" open>
            <summary class="flex items-start gap-2 px-2.5 py-2 cursor-pointer select-none hover:bg-zinc-50 dark:hover:bg-zinc-900/80 rounded-lg">
                <div class="mt-0.5 shrink-0">
                    <x-heroicon-o-folder class="w-4 h-4 text-amber-500" />
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200 truncate">
                            {{ $nodeLabel !== '' ? $nodeLabel : 'Unité' }}
                        </span>
                        @if(($node['validation_status'] ?? 'pending') === 'validated')
                            <span class="w-2 h-2 rounded-full bg-emerald-500" title="Validé"></span>
                        @else
                            <span class="w-2 h-2 rounded-full bg-amber-400" title="Brouillon"></span>
                        @endif
                    </div>
                    @if($nodeTitle !== '')
                        <div class="text-[11px] text-zinc-500 dark:text-zinc-400 truncate">
                            {{ $nodeTitle }}
                        </div>
                    @endif
                </div>

                <div class="flex items-center gap-1 opacity-100 lg:opacity-0 lg:group-hover:opacity-100 transition-opacity">
                    <button type="button" wire:click.stop="moveNodeUp('{{ $node['id'] }}')" class="p-1 rounded hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-500">
                        <x-heroicon-o-chevron-up class="w-4 h-4" />
                    </button>
                    <button type="button" wire:click.stop="moveNodeDown('{{ $node['id'] }}')" class="p-1 rounded hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-500">
                        <x-heroicon-o-chevron-down class="w-4 h-4" />
                    </button>
                    <button type="button" wire:click.stop="addChildNode('{{ $node['id'] }}')" class="p-1 rounded hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-500" title="Ajouter une section">
                        <x-heroicon-o-folder-plus class="w-4 h-4" />
                    </button>
                    <button type="button" wire:click.stop="addArticle('{{ $node['id'] }}')" class="p-1 rounded hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-500" title="Ajouter un article">
                        <x-heroicon-o-document-plus class="w-4 h-4" />
                    </button>
                    <button type="button" wire:click.stop="startEditNode('{{ $node['id'] }}')" class="p-1 rounded hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-500" title="Modifier">
                        <x-heroicon-o-pencil-square class="w-4 h-4" />
                    </button>
                </div>
            </summary>

            <div class="px-2.5 pb-2">
                @if($isEditingNode)
                    <div class="mt-2 grid grid-cols-1 gap-2">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                            <input
                                type="text"
                                wire:model.defer="editingNodeType"
                                class="w-full rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-3 py-2 text-sm"
                                placeholder="Type (Titre, Chapitre, Section...)"
                            />
                            <input
                                type="text"
                                wire:model.defer="editingNodeNumero"
                                class="w-full rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-3 py-2 text-sm"
                                placeholder="Numéro"
                            />
                            <input
                                type="text"
                                wire:model.defer="editingNodeTitle"
                                class="w-full rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-3 py-2 text-sm md:col-span-1"
                                placeholder="Titre"
                            />
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="saveNode" class="px-3 py-1.5 rounded-md bg-[#1e3a8a] text-white text-sm font-medium hover:bg-blue-800">
                                Enregistrer
                            </button>
                            <button type="button" wire:click="cancelEditNode" class="px-3 py-1.5 rounded-md text-sm font-medium text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800">
                                Annuler
                            </button>
                        </div>
                    </div>
                @endif

                @if(! empty($node['articles']))
                    <div class="mt-2 space-y-1">
                        @foreach($node['articles'] as $article)
                            @php
                                $isSelected = (string) ($selectedArticleId ?? '') === (string) ($article['id'] ?? '');
                                $isEditingArticle = (string) ($editingArticleId ?? '') === (string) ($article['id'] ?? '');
                            @endphp

                            <div class="rounded-md {{ $isSelected ? 'bg-blue-50 dark:bg-blue-900/20' : 'hover:bg-zinc-50 dark:hover:bg-zinc-900/80' }}">
                                <div class="flex items-center gap-2 px-2 py-1.5">
                                    <button
                                        type="button"
                                        wire:click="selectArticle('{{ $article['id'] }}')"
                                        class="flex-1 min-w-0 text-left text-sm {{ $isSelected ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-600 dark:text-zinc-400' }}"
                                    >
                                        <span class="inline-flex items-center gap-2 truncate">
                                            <x-heroicon-o-document-text class="w-4 h-4 opacity-50 shrink-0" />
                                            Article {{ $article['numero_article'] ?? '?' }}
                                        </span>
                                    </button>

                                    <div class="flex items-center gap-1 shrink-0">
                                        <button type="button" wire:click.stop="moveArticleUp('{{ $article['id'] }}')" class="p-1 rounded hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-500">
                                            <x-heroicon-o-chevron-up class="w-4 h-4" />
                                        </button>
                                        <button type="button" wire:click.stop="moveArticleDown('{{ $article['id'] }}')" class="p-1 rounded hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-500">
                                            <x-heroicon-o-chevron-down class="w-4 h-4" />
                                        </button>
                                        <button type="button" wire:click.stop="startEditArticle('{{ $article['id'] }}')" class="p-1 rounded hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-500">
                                            <x-heroicon-o-pencil-square class="w-4 h-4" />
                                        </button>
                                        @if(($article['validation_status'] ?? 'draft') === 'validated')
                                            <span class="w-2 h-2 rounded-full bg-emerald-500" title="Validé"></span>
                                        @else
                                            <span class="w-2 h-2 rounded-full bg-amber-400" title="Brouillon"></span>
                                        @endif
                                    </div>
                                </div>

                                @if($isEditingArticle)
                                    <div class="px-2 pb-2">
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                                            <input
                                                type="text"
                                                wire:model.defer="editingArticleNumero"
                                                class="w-full rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-3 py-2 text-sm"
                                                placeholder="Numéro d'article"
                                            />
                                            <div class="md:col-span-2 flex items-center gap-2">
                                                <button type="button" wire:click="saveArticle" class="px-3 py-1.5 rounded-md bg-[#1e3a8a] text-white text-sm font-medium hover:bg-blue-800">
                                                    Enregistrer
                                                </button>
                                                <button type="button" wire:click="cancelEditArticle" class="px-3 py-1.5 rounded-md text-sm font-medium text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800">
                                                    Annuler
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @if(! empty($node['children']))
                    <div class="mt-2 space-y-2">
                        @foreach($node['children'] as $child)
                            @include('livewire.curation.partials.structure-node', ['node' => $child, 'depth' => $depth + 1])
                        @endforeach
                    </div>
                @endif
            </div>
        </details>
    </div>
</div>

