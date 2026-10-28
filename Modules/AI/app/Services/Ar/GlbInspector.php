<?php

namespace Modules\AI\Services\Ar;

use Modules\AI\Exceptions\AiArException;

/**
 * Reads a binary glTF (GLB) just enough to vet it: real file type (magic bytes), JSON chunk, no external
 * references, triangle count, and the model's own bounding box in metres. It never executes or renders anything.
 * Bounds come from the POSITION accessors and ignore node transforms, so they are an estimate.
 */
class GlbInspector
{
    private const MAGIC = 0x46546C67;       // "glTF"

    private const CHUNK_JSON = 0x4E4F534A;  // "JSON"

    /** @return array{triangles: int, dimensions: ?array{width: float, height: float, depth: float}} */
    public function inspect(string $bytes): array
    {
        if (strlen($bytes) < 20 || unpack('V', substr($bytes, 0, 4))[1] !== self::MAGIC) {
            throw new AiArException('asset_not_glb', 422);
        }

        $header = unpack('Vversion/Vlength', substr($bytes, 4, 8));

        if ($header['version'] !== 2 || $header['length'] !== strlen($bytes)) {
            throw new AiArException('asset_not_glb', 422);
        }

        $chunk = unpack('Vlength/Vtype', substr($bytes, 12, 8));

        if ($chunk['type'] !== self::CHUNK_JSON || 20 + $chunk['length'] > strlen($bytes)) {
            throw new AiArException('asset_not_glb', 422);
        }

        $json = json_decode(substr($bytes, 20, $chunk['length']), true);

        if (! is_array($json) || ! isset($json['asset'])) {
            throw new AiArException('asset_not_glb', 422);
        }

        foreach (array_merge((array) ($json['buffers'] ?? []), (array) ($json['images'] ?? [])) as $ref) {
            // Only the binary chunk or inline data may be used: a model must not point at files or URLs.
            if (isset($ref['uri']) && ! str_starts_with((string) $ref['uri'], 'data:')) {
                throw new AiArException('asset_external_refs', 422);
            }
        }

        return $this->measure($json);
    }

    /** @return array{triangles: int, dimensions: ?array{width: float, height: float, depth: float}} */
    protected function measure(array $json): array
    {
        $accessors = (array) ($json['accessors'] ?? []);
        $triangles = 0;
        $min = null;
        $max = null;

        foreach ((array) ($json['meshes'] ?? []) as $mesh) {
            foreach ((array) ($mesh['primitives'] ?? []) as $primitive) {
                $mode = (int) ($primitive['mode'] ?? 4);
                $count = isset($primitive['indices'])
                    ? (int) ($accessors[$primitive['indices']]['count'] ?? 0)
                    : (int) ($accessors[$primitive['attributes']['POSITION'] ?? -1]['count'] ?? 0);

                $triangles += match ($mode) {
                    4 => intdiv($count, 3),
                    5, 6 => max(0, $count - 2),
                    default => 0,
                };

                $position = $accessors[$primitive['attributes']['POSITION'] ?? -1] ?? null;

                if (is_array($position) && isset($position['min'], $position['max']) && count($position['min']) === 3 && count($position['max']) === 3) {
                    for ($i = 0; $i < 3; $i++) {
                        $min[$i] = min($min[$i] ?? INF, (float) $position['min'][$i]);
                        $max[$i] = max($max[$i] ?? -INF, (float) $position['max'][$i]);
                    }
                }
            }
        }

        return [
            'triangles' => $triangles,
            'dimensions' => $min === null ? null : [
                'width' => round($max[0] - $min[0], 4),
                'height' => round($max[1] - $min[1], 4),
                'depth' => round($max[2] - $min[2], 4),
            ],
        ];
    }
}
