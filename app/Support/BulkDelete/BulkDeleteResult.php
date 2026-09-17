<?php

namespace App\Support\BulkDelete;

class BulkDeleteResult
{
    public int $deleted = 0;

    /**
     * @var list<array{id: int|string, reason: string, message: string, relation?: string}>
     */
    public array $skipped = [];

    public function addSkipped(int|string $id, string $reason, string $message, ?string $relation = null): void
    {
        $item = [
            'id' => $id,
            'reason' => $reason,
            'message' => $message,
        ];

        if ($relation !== null) {
            $item['relation'] = $relation;
        }

        $this->skipped[] = $item;
    }

    public function skippedCount(): int
    {
        return count($this->skipped);
    }

    public function anyDeleted(): bool
    {
        return $this->deleted > 0;
    }

    public function allSkipped(): bool
    {
        return $this->deleted === 0 && $this->skipped !== [];
    }

    /**
     * @return array{deleted: int, skipped: int, skipped_items: list<array{id: int|string, reason: string, message: string, relation?: string}>}
     */
    public function toArray(): array
    {
        return [
            'deleted' => $this->deleted,
            'skipped' => $this->skippedCount(),
            'skipped_items' => $this->skipped,
        ];
    }
}
