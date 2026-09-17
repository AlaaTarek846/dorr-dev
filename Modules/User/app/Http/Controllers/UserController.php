<?php

namespace Modules\User\Http\Controllers;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use Modules\User\Http\Requests\UserRequest;
use Modules\User\Services\UserService;

class UserController extends Controller
{
    public function __construct(protected UserService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(UserRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int|string $user)
    {
        return $this->service->find($user);
    }

    public function update(UserRequest $request, int|string $user)
    {
        return $this->service->updateRecord($user, $request->validated());
    }

    public function destroy(int|string $user)
    {
        return $this->service->delete($user);
    }

    public function restore(int|string $user)
    {
        return $this->service->restoreRecord($user);
    }

    public function forceDestroy(int|string $user)
    {
        return $this->service->forceDeleteRecord($user);
    }

    public function deleteMultiple(UserRequest $request)
    {
        return $this->service->deleteMultiple($request->validated('ids'));
    }

    public function changeStatus(UserRequest $request, int|string $user)
    {
        return $this->service->changeStatus(
            $user,
            UserStatus::from($request->validated('status')),
        );
    }
}
