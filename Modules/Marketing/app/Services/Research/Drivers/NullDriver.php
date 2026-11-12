<?php

namespace Modules\Marketing\Services\Research\Drivers;

use Modules\Marketing\Services\Research\Contracts\SearchDriver;

/** No web search configured: research ends PARTIAL with "no sources" — it never makes numbers up. */
class NullDriver implements SearchDriver
{
    public function name(): string
    {
        return 'none';
    }

    public function ready(): bool
    {
        return false;
    }

    public function search(string $query, int $maxResults): array
    {
        return [];
    }
}
