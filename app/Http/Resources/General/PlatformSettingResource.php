<?php

namespace App\Http\Resources\General;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'app_name' => $this->app_name,
            'logo' => $this->getSingleMediaUrl('logo') ?: null,
            'logo_dark' => $this->getSingleMediaUrl('logo_dark') ?: null,
            'favicon_ico' => $this->getSingleMediaUrl('favicon_ico') ?: null,
            'favicon_16' => $this->getSingleMediaUrl('favicon_16') ?: null,
            'favicon_32' => $this->getSingleMediaUrl('favicon_32') ?: null,
            'apple_touch_icon' => $this->getSingleMediaUrl('apple_touch_icon') ?: null,
            'web_manifest' => $this->getSingleMediaUrl('web_manifest') ?: null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
