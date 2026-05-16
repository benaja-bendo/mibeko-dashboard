<?php

namespace App\Livewire\Curation;

use Livewire\Component;
use App\Models\LegalDocument;
use App\Models\Article;
use App\Models\ArticleVersion;
use Livewire\Attributes\On;

class ContentEditor extends Component
{
    public LegalDocument $document;
    public ?Article $article = null;
    
    public ?string $content = '';

    public function mount(LegalDocument $document, $articleId = null)
    {
        $this->document = $document;
        if ($articleId) {
            $this->loadArticle($articleId);
        }
    }

    #[On('articleSelected')]
    public function loadArticle(string $articleId): void
    {
        $this->article = Article::query()
            ->with('latestVersion')
            ->whereKey($articleId)
            ->first();
        $this->content = $this->article?->latestVersion?->contenu_texte ?? '';
    }

    public function save(): void
    {
        if (! $this->article) {
            return;
        }

        $version = $this->article->latestVersion;

        if ($version) {
            $version->update([
                'contenu_texte' => (string) $this->content,
                'modifie_par_document_id' => $this->document->id,
            ]);
        } else {
            $startDate = now()->toDateString();

            ArticleVersion::create([
                'article_id' => $this->article->id,
                'validity_period' => ArticleVersion::makeValidityPeriod($startDate),
                'contenu_texte' => (string) $this->content,
                'modifie_par_document_id' => $this->document->id,
            ]);
        }
    }

    #[On('saveCurrentArticle')]
    public function saveCurrentArticle(): void
    {
        $this->save();
    }

    #[On('createNewArticleVersion')]
    public function createNewArticleVersion(): void
    {
        if (! $this->article) {
            return;
        }

        $startDate = now()->toDateString();

        ArticleVersion::create([
            'article_id' => $this->article->id,
            'validity_period' => ArticleVersion::makeValidityPeriod($startDate),
            'contenu_texte' => (string) $this->content,
            'modifie_par_document_id' => $this->document->id,
            'validation_status' => 'draft',
        ]);

        $this->article->load('latestVersion');
    }

    public function render()
    {
        return view('livewire.curation.content-editor');
    }
}
