<?php

namespace App\Services;

final readonly class ResolvedSourcePdf
{
    public function __construct(
        public string $diskName,
        public string $objectKey,
    ) {}
}
