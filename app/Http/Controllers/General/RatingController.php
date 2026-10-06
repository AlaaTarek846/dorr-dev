<?php

namespace App\Http\Controllers\General;

use App\Http\Controllers\Concerns\DefinesAdminCatalogPermissions;
use App\Http\Controllers\Controller;
use App\Http\Requests\General\RatingRequest;
use App\Services\General\RatingService;
use Illuminate\Routing\Controllers\HasMiddleware;

/**
 * Dashboard: read and remove ratings (`ratings.view` / `ratings.delete` / `ratings.multiple-delete`).
 */
class RatingController extends Controller implements HasMiddleware
{
    use DefinesAdminCatalogPermissions;

    protected static function adminPermissionGroup(): string
    {
        return 'ratings';
    }

    protected static function permissionsOnDropdown(): bool
    {
        return false;
    }

    public function __construct(protected RatingService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int|string $rating)
    {
        return $this->service->find($rating);
    }

    public function destroy(int|string $rating)
    {
        return $this->service->delete($rating);
    }

    public function deleteMultiple(RatingRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }
}
