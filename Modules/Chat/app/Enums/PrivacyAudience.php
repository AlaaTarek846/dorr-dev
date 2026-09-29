<?php

namespace Modules\Chat\Enums;

enum PrivacyAudience: string
{
    case Everyone = 'everyone';
    case Contacts = 'contacts';
    case Nobody = 'nobody';
}
