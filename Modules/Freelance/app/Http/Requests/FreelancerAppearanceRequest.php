<?php

namespace Modules\Freelance\Http\Requests;

use Modules\User\Http\Requests\MobileAppearanceRequest;

/** PUT appearance: the same rules as the DORR app's (colours, dark mode, font), for the signed-in freelancer. */
class FreelancerAppearanceRequest extends MobileAppearanceRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user('freelance_api');
    }
}
