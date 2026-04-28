<?php

namespace Tests;

use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Disable AI embedding generation globally for all tests to prevent
        // external API calls (e.g. Mistral) and speed up test execution.
        ArticleVersionObserver::$shouldSkipEmbeddings = true;
    }
}
