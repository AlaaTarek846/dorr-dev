<?php

namespace Modules\AI\Services\Ar;

use Illuminate\Support\Str;
use Modules\AI\Models\AiProvider;
use Modules\AI\Services\AiGateway;

/**
 * Turns "move the sofa a bit to the left and make it blue" into a short list of actions from a FIXED whitelist.
 * The model never edits anything itself: whatever it answers is parsed, every action is checked against the
 * whitelist and the ids the client actually has on screen, and anything else is dropped. The device applies the
 * result; nothing here touches the saved scene.
 */
class AiArCommandInterpreter
{
    public const ACTIONS = ['add', 'remove', 'move', 'rotate', 'scale', 'tint'];

    public function __construct(protected AiGateway $gateway) {}

    /**
     * @param  list<array{id: int, name: string}>  $items  models currently placed (client-local ids)
     * @param  list<array{id: int, name: string}>  $assets  models the user may add
     * @return array{actions: list<array<string, mixed>>, source: string}
     */
    public function interpret(string $text, array $items, array $assets): array
    {
        $provider = AiProvider::query()->where('is_enabled', true)->orderByDesc('is_default')->first();

        if ($provider === null || blank($provider->api_key)) {
            return ['actions' => [], 'source' => 'none'];
        }

        $list = fn (array $rows) => implode('; ', array_map(fn ($r) => $r['id'].' = '.Str::limit((string) $r['name'], 60, ''), array_slice($rows, 0, 60))) ?: 'none';
        $system = 'You translate a spoken or typed AR editing request into JSON. Reply with ONLY a JSON array, no prose. '
            .'Allowed actions: {"do":"add","asset_id":int} {"do":"remove","item_id":int} '
            .'{"do":"move","item_id":int,"dx":metres,"dz":metres} {"do":"rotate","item_id":int,"degrees":number} '
            .'{"do":"scale","item_id":int,"factor":number} {"do":"tint","item_id":int,"color":"#RRGGBB"}. '
            .'dx positive = right, dz positive = towards the user. If the request is none of these, reply []. '
            .'Placed items (id = name): '.$list($items).'. Addable models (id = name): '.$list($assets).'.';

        try {
            $result = $this->gateway->chat($provider, [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => Str::limit($text, 500, '')]]);
        } catch (\Throwable) {
            return ['actions' => [], 'source' => 'none'];
        }

        $json = (string) ($result['content'] ?? '');

        if (! ($result['success'] ?? false) || preg_match('/\[.*\]/s', $json, $m) !== 1) {
            return ['actions' => [], 'source' => 'none'];
        }

        $decoded = json_decode($m[0], true);

        return ['actions' => is_array($decoded) ? $this->clean($decoded, array_column($items, 'id'), array_column($assets, 'id')) : [], 'source' => 'ai'];
    }

    /** @param list<int> $itemIds @param list<int> $assetIds @return list<array<string, mixed>> */
    public function clean(array $raw, array $itemIds, array $assetIds): array
    {
        $out = [];

        foreach (array_slice($raw, 0, 10) as $a) {
            if (! is_array($a) || ! in_array($a['do'] ?? null, self::ACTIONS, true)) {
                continue;
            }

            $item = (int) ($a['item_id'] ?? 0);

            switch ($a['do']) {
                case 'add':
                    if (in_array((int) ($a['asset_id'] ?? 0), $assetIds, true)) {
                        $out[] = ['do' => 'add', 'asset_id' => (int) $a['asset_id']];
                    }
                    break;
                case 'remove':
                    if (in_array($item, $itemIds, true)) {
                        $out[] = ['do' => 'remove', 'item_id' => $item];
                    }
                    break;
                case 'move':
                    if (in_array($item, $itemIds, true) && is_numeric($a['dx'] ?? null) && is_numeric($a['dz'] ?? null)) {
                        $out[] = ['do' => 'move', 'item_id' => $item, 'dx' => max(-5, min(5, (float) $a['dx'])), 'dz' => max(-5, min(5, (float) $a['dz']))];
                    }
                    break;
                case 'rotate':
                    if (in_array($item, $itemIds, true) && is_numeric($a['degrees'] ?? null)) {
                        $out[] = ['do' => 'rotate', 'item_id' => $item, 'degrees' => max(-360, min(360, (float) $a['degrees']))];
                    }
                    break;
                case 'scale':
                    if (in_array($item, $itemIds, true) && is_numeric($a['factor'] ?? null)) {
                        $out[] = ['do' => 'scale', 'item_id' => $item, 'factor' => max(0.25, min(4, (float) $a['factor']))];
                    }
                    break;
                case 'tint':
                    if (in_array($item, $itemIds, true) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($a['color'] ?? '')) === 1) {
                        $out[] = ['do' => 'tint', 'item_id' => $item, 'color' => strtoupper($a['color'])];
                    }
                    break;
            }
        }

        return $out;
    }
}
