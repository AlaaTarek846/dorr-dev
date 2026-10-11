<?php

namespace Modules\Discover\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/** A Discover rule the person can act on. Renders itself as `discover_<code>` (see ApiRenderable). */
class DiscoverException extends RuntimeException implements ApiRenderable
{
    /**
     * @param  array<string, mixed>  $replace
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $errorCode,
        private readonly int $status = 422,
        private readonly array $replace = [],
        private readonly array $data = [],
    ) {
        parent::__construct($errorCode);
    }

    public function apiStatus(): int
    {
        return $this->status;
    }

    public function apiMessage(): string
    {
        return __('discover.errors.'.$this->errorCode, $this->replace);
    }

    public function apiErrorCode(): string
    {
        return 'discover_'.$this->errorCode;
    }

    public function apiData(): array
    {
        return $this->data;
    }

    public static function off(): self
    {
        return new self('off', 403);
    }

    public static function notFound(): self
    {
        return new self('not_found', 404);
    }
}
