<?php

namespace App\Exceptions;

/**
 * Lets a domain exception describe its own API error response (HTTP status,
 * translated message, machine-readable code) so ApiExceptionRenderer doesn't
 * need a branch per feature module. `apiErrorCode()` is what a client keys its
 * behaviour on (e.g. the mobile app starts PIN creation on `wallet_pin_not_set`)
 * — the message is for humans and follows the request locale.
 */
interface ApiRenderable
{
    public function apiStatus(): int;

    public function apiMessage(): string;

    public function apiErrorCode(): string;

    /**
     * Extra machine-readable context (e.g. `locked_until`), merged into `data`.
     *
     * @return array<string, mixed>
     */
    public function apiData(): array;
}
