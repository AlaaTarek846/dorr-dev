<?php

namespace Modules\Marketing\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Marketing\Models\MarketingAuditEvent;
use Modules\Marketing\Models\MarketingProject;

/** The trail every change leaves (POL-08), tied to the request that made it. */
class MarketingAudit
{
    private ?string $requestId = null;

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $meta
     */
    public function record(?MarketingProject $project, ?Model $actor, string $action, ?Model $resource = null, ?array $before = null, ?array $after = null, array $meta = []): MarketingAuditEvent
    {
        return MarketingAuditEvent::query()->create([
            'project_id' => $project?->id,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'resource_type' => $resource === null ? null : class_basename($resource),
            'resource_id' => $resource?->getKey(),
            'before_hash' => $before === null ? null : self::hash($before),
            'after_hash' => $after === null ? null : self::hash($after),
            'request_id' => $this->requestId(),
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    /**
     * One id per HTTP request, kept on the request itself (a service instance can outlive a request: routes keep their
     * controllers). Queue jobs get one id per job run.
     */
    public function requestId(): string
    {
        if (! app()->bound('request') || app()->runningInConsole() && ! app()->runningUnitTests()) {
            return $this->requestId ??= (string) Str::uuid();
        }
        $request = request();
        $id = $request->attributes->get('marketing_request_id');
        if (! is_string($id)) {
            $given = (string) $request->header('X-Request-Id', '');
            $id = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $given) ? $given : (string) Str::uuid();
            $request->attributes->set('marketing_request_id', $id);
        }

        return $id;
    }

    /** @param  array<string, mixed>  $data */
    public static function hash(array $data): string
    {
        return hash('sha256', json_encode(self::sorted($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }
}
