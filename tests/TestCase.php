<?php

namespace Tests;

use App\Observers\ArticleVersionObserver;
use App\Observers\LegalDocumentObserver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Disable AI embedding generation globally for all tests to prevent
        // external API calls (e.g. Mistral) and speed up test execution.
        ArticleVersionObserver::$shouldSkipEmbeddings = true;

        // Idem pour le pré-chauffage du cache PDF d'export : sans ce garde,
        // publier un document dans un test déclencherait un rendu DomPDF réel
        // (QUEUE_CONNECTION=sync en test) à chaque fois. Les tests dédiés à
        // cette fonctionnalité le réactivent explicitement.
        LegalDocumentObserver::$shouldSkipExportPdfWarmup = true;
    }
}
