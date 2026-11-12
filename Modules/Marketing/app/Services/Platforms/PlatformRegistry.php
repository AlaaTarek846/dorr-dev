<?php

namespace Modules\Marketing\Services\Platforms;

use Modules\Marketing\Exceptions\MarketingException;

/** The adapters (D6): Meta now; Google, TikTok and Snapchat join here with their own class. */
class PlatformRegistry
{
    public const PROVIDERS = ['meta'];

    public const COMING = ['google', 'tiktok', 'snapchat'];

    public function get(string $provider): AdPlatform
    {
        return match ($provider) {
            'meta' => app(MetaAdapter::class),
            default => throw new MarketingException('platform_unavailable', 422),
        };
    }
}
