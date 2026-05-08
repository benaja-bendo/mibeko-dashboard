<?php

test('la planification du backup DB vers Google Drive est déclarée', function () {
    $consoleRoutesPath = dirname(__DIR__, 2).'/routes/console.php';
    $content = file_get_contents($consoleRoutesPath);

    expect($content)->toBeString();
    expect($content)->toContain('mibeko:backup --disk=gdrive --only-db');
});
