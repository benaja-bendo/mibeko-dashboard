<?php

use App\Models\SearchLog;

it('purge les recherches plus vieilles que N jours et conserve les récentes', function () {
    $old = SearchLog::create(['query' => 'ancien', 'results_count' => 0, 'surface' => 'library/search']);
    $old->timestamps = false;
    $old->created_at = now()->subDays(120);
    $old->save();

    $recent = SearchLog::create(['query' => 'recent', 'results_count' => 1, 'surface' => 'library/search']);

    $this->artisan('mibeko:purge-search-logs', ['--days' => 90])->assertSuccessful();

    expect(SearchLog::find($old->id))->toBeNull();
    expect(SearchLog::find($recent->id))->not->toBeNull();
});

it('utilise la rétention de configuration par défaut', function () {
    config(['search_logging.retention_days' => 30]);

    $old = SearchLog::create(['query' => 'ancien', 'results_count' => 0, 'surface' => 'library/search']);
    $old->timestamps = false;
    $old->created_at = now()->subDays(45);
    $old->save();

    $this->artisan('mibeko:purge-search-logs')->assertSuccessful();

    expect(SearchLog::find($old->id))->toBeNull();
});
