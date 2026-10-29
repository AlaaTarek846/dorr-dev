<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One step of a project's record of approvals (FL-406): who agreed to what, when, on which version. Written only, inside the same
 * transaction as the step itself — a row is never changed or deleted (the model refuses; the table is not touched any other way).
 *
 * @property int $id
 * @property int $project_id
 * @property ?int $agreement_id
 * @property string $action
 * @property string $actor_type
 * @property ?int $actor_id
 * @property ?int $version
 * @property ?array<string, mixed> $payload
 * @property ?\Illuminate\Support\Carbon $created_at
 */
class FreelanceProjectApproval extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'freelance_project_approvals';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The approvals record is written only.'));
        static::deleting(fn () => throw new LogicException('The approvals record is written only.'));
    }

    /** @param  array<string, mixed>  $payload */
    public static function record(int $projectId, ?int $agreementId, string $action, string $actorType, mixed $actorId, ?int $version, array $payload = []): self
    {
        return static::query()->create([
            'project_id' => $projectId, 'agreement_id' => $agreementId, 'action' => $action, 'actor_type' => $actorType,
            'actor_id' => $actorId, 'version' => $version, 'payload' => $payload === [] ? null : $payload,
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    public static function forAgreement(FreelanceAgreement $a, string $action, string $actorType, mixed $actorId, array $payload = []): self
    {
        return static::record((int) $a->project_id, (int) $a->id, $action, $actorType, $actorId, (int) $a->version, $payload);
    }
}
