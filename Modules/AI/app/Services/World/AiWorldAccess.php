<?php

namespace Modules\AI\Services\World;

use Modules\AI\Exceptions\AiWorldException;
use Modules\AI\Models\World\AiWorldCountryMode;
use Modules\AI\Models\World\AiWorldSetting;

/** Whether DORR AI WORLD, and each of its modes, is switched on for the caller's country. */
class AiWorldAccess
{
    public function enabled(): bool
    {
        return AiWorldSetting::current()->is_enabled;
    }

    public function countryId(): ?int
    {
        return app()->bound('resolved_country') ? app('resolved_country')?->id : null;
    }

    /** @return array<string, bool> */
    public function modes(): array
    {
        return $this->enabled() ? AiWorldCountryMode::forCountry($this->countryId()) : array_fill_keys(array_keys(AiWorldCountryMode::DEFAULTS), false);
    }

    /** @throws AiWorldException disabled | mode_disabled */
    public function assertEnabled(?string $mode = null): void
    {
        if (! $this->enabled()) {
            throw new AiWorldException('disabled', 403);
        }

        if ($mode !== null && ! AiWorldCountryMode::isEnabled($this->countryId(), $mode)) {
            throw new AiWorldException('mode_disabled', 403);
        }
    }
}
