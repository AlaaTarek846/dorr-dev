<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Modules\Freelance\Models\Freelancer;
use Modules\User\Http\Controllers\Mobile\SecurityController;

/**
 * The freelancer app's Settings > Security: the same devices and security log as the DORR app (`mobile/v1/security`),
 * for a freelancer account. The sessions and the log are the shared ones, so support sees them on the same dashboard page.
 */
class FreelancerSecurityController extends SecurityController
{
    protected function owner(Request $request): Model
    {
        /** @var Freelancer */
        return $request->user('freelance_api');
    }

    /** A device signed out through the security screen also leaves the freelancer's own device list. */
    protected function afterSessionsEnded(Model $owner): void
    {
        /** @var Freelancer $owner */
        $owner->devices()->whereNotNull('token_id')->whereNotIn('token_id', $owner->tokens()->select('id'))->delete();
    }
}
