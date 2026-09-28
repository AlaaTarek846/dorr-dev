<?php

namespace Modules\User\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\User\Http\Requests\StoreAddressRequest;
use Modules\User\Http\Requests\UpdateAddressRequest;
use Modules\User\Http\Resources\AddressResource;
use Modules\User\Models\Address;
use Modules\User\Models\User;

class AddressController extends Controller
{
    /**
     * The caller's own addresses, newest first. ?search= matches title,
     * details, building number and landmark.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');

        $query = $user->addresses()->latest('id');

        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('address_details', 'like', $like)
                    ->orWhere('building_number', 'like', $like)
                    ->orWhere('landmark', 'like', $like);
            });
        }

        return ApiResponse::paginated($query, AddressResource::class, __('api.retrieved'));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');

        $address = $user->addresses()->findOrFail($id);

        return ApiResponse::success(
            new AddressResource($address),
            __('api.retrieved'),
        );
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');

        $address = DB::transaction(function () use ($user, $request) {
            $address = $user->addresses()->create($request->validated());

            if ($address->is_default) {
                $this->unsetOtherDefaults($user, $address);
            }

            return $address;
        });

        return ApiResponse::created(
            new AddressResource($address->fresh()),
            __('api.created'),
        );
    }

    public function update(UpdateAddressRequest $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');

        /** @var Address $address */
        $address = $user->addresses()->findOrFail($id);

        DB::transaction(function () use ($user, $request, $address) {
            $address->update($request->validated());

            if ($request->boolean('is_default')) {
                $this->unsetOtherDefaults($user, $address);
            }
        });

        return ApiResponse::success(
            new AddressResource($address->fresh()),
            __('api.updated'),
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');

        /** @var Address $address */
        $address = $user->addresses()->findOrFail($id);
        $address->delete();

        return ApiResponse::success([], __('api.deleted'));
    }

    /**
     * Pin or unpin the default flag. Pinning clears the flag on every other
     * address of the same user so only one default ever exists.
     */
    public function setDefault(Request $request, int $id): JsonResponse
    {
        $request->validate(['is_default' => ['required', 'boolean']]);

        /** @var User $user */
        $user = $request->user('user_api');

        /** @var Address $address */
        $address = $user->addresses()->findOrFail($id);

        DB::transaction(function () use ($user, $request, $address) {
            if ($request->boolean('is_default')) {
                $this->unsetOtherDefaults($user, $address);
                $address->update(['is_default' => true]);
            } else {
                $address->update(['is_default' => false]);
            }
        });

        return ApiResponse::success(
            new AddressResource($address->fresh()),
            __('api.updated'),
        );
    }

    private function unsetOtherDefaults(User $user, Address $except): void
    {
        $user->addresses()
            ->where('id', '!=', $except->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
