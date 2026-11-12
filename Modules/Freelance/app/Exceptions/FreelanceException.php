<?php

namespace Modules\Freelance\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/** A freelance rule the caller can act on. Renders itself as `freelance_<code>` (see ApiRenderable). */
class FreelanceException extends RuntimeException implements ApiRenderable
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
        $message = __('freelance.errors.'.$this->errorCode, $this->replace);
        // FS-705c: a refusal for a plan limit (it carries `feature` and `upgrade`, see `PlanFeatures::upgradeHint`) says which plan lifts it,
        // so every screen of every app shows the way out without a line of its own.
        $plan = isset($this->data['feature']) ? ($this->data['upgrade']['name'] ?? null) : null;

        return $plan === null ? $message : $message.' '.__('freelance.errors.upgrade_to', ['plan' => $plan]);
    }

    public function apiErrorCode(): string
    {
        return 'freelance_'.$this->errorCode;
    }

    public function apiData(): array
    {
        return $this->data;
    }
}
