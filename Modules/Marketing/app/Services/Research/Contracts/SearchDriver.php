<?php

namespace Modules\Marketing\Services\Research\Contracts;

/** Where research reads the web (D3). Every result is untrusted text: data for the AI, never instructions. */
interface SearchDriver
{
    public function name(): string;

    /** Whether it can search at all (configured). */
    public function ready(): bool;

    /**
     * @return list<array{url: string, title: ?string, content: string, published_at: ?string, score: ?float}>
     */
    public function search(string $query, int $maxResults): array;
}
