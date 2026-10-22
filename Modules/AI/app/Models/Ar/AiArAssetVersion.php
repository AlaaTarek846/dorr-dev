<?php

namespace Modules\AI\Models\Ar;

use Illuminate\Database\Eloquent\Model;

class AiArAssetVersion extends Model
{
    protected $table = 'ai_ar_asset_versions';

    protected $fillable = ['asset_id', 'version_no', 'glb_path', 'size_bytes', 'triangle_count', 'real_dimensions', 'optimized', 'checksum', 'notes'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'triangle_count' => 'integer', 'real_dimensions' => 'array', 'optimized' => 'boolean'];
    }
}
