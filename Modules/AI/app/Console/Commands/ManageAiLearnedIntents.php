<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Command;
use Modules\AI\Models\AiLearnedIntent;
use Modules\AI\Services\AiLearnedIntentStore;

/**
 * Review and control what the intent router has learned from the model.
 *
 *   php artisan ai:learned-intents list [--pending|--active|--conflicts]
 *   php artisan ai:learned-intents stats
 *   php artisan ai:learned-intents enable 12
 *   php artisan ai:learned-intents disable 12
 *   php artisan ai:learned-intents delete 12
 *
 * "disable" marks the row as contradicted (conflicts >= 1) so the router
 * never switches it back on by itself - only "enable" does.
 */
class ManageAiLearnedIntents extends Command
{
    protected $signature = 'ai:learned-intents
        {action=list : list | stats | enable | disable | delete}
        {id? : Row id for enable / disable / delete}
        {--pending : Only rows still waiting for confirmation}
        {--active : Only active rows}
        {--conflicts : Only rows the model contradicted}
        {--limit=50 : Max rows for list}';

    protected $description = 'Review, enable, disable or delete phrases the AI intent router learned from the default model.';

    public function handle(AiLearnedIntentStore $store): int
    {
        return match ($this->argument('action')) {
            'list' => $this->listRows(),
            'stats' => $this->stats(),
            'enable', 'disable', 'delete' => $this->change($store),
            default => $this->unknown(),
        };
    }

    protected function unknown(): int
    {
        $this->error('Unknown action. Use: list | stats | enable | disable | delete');

        return self::INVALID;
    }

    protected function listRows(): int
    {
        $query = AiLearnedIntent::query()->orderByDesc('hits')->orderByDesc('id');

        if ($this->option('active')) {
            $query->where('is_active', true);
        }

        if ($this->option('pending')) {
            $query->where('is_active', false)->where('conflicts', 0);
        }

        if ($this->option('conflicts')) {
            $query->where('conflicts', '>', 0);
        }

        $rows = $query->limit(max(1, (int) $this->option('limit')))->get();

        $this->table(
            ['id', 'phrase', 'mode', 'intent', 'format', 'conf', 'confirm', 'conflicts', 'hits', 'active'],
            $rows->map(fn (AiLearnedIntent $r) => [
                $r->id, $r->phrase, $r->match_mode, $r->intent, $r->file_format ?? '-', number_format($r->confidence, 2),
                $r->confirmations, $r->conflicts, $r->hits, $r->is_active ? 'yes' : 'no',
            ])->all(),
        );

        return self::SUCCESS;
    }

    protected function stats(): int
    {
        $total = AiLearnedIntent::query()->count();
        $active = AiLearnedIntent::query()->where('is_active', true)->count();
        $conflicts = AiLearnedIntent::query()->where('conflicts', '>', 0)->count();
        $hits = (int) AiLearnedIntent::query()->sum('hits');

        $this->table(['total', 'active', 'pending', 'conflicts', 'model calls saved (hits)'], [[
            $total, $active, $total - $active - $conflicts, $conflicts, $hits,
        ]]);

        return self::SUCCESS;
    }

    protected function change(AiLearnedIntentStore $store): int
    {
        $row = AiLearnedIntent::query()->find($this->argument('id'));

        if ($row === null) {
            $this->error('No learned intent with that id.');

            return self::FAILURE;
        }

        match ($this->argument('action')) {
            'enable' => $row->forceFill(['is_active' => true, 'conflicts' => 0])->save(),
            'disable' => $row->forceFill(['is_active' => false, 'conflicts' => max(1, $row->conflicts)])->save(),
            'delete' => $row->delete(),
        };

        $store->flushCache();
        $this->info("Done ({$this->argument('action')} #{$row->id}).");

        return self::SUCCESS;
    }
}
