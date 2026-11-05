<?php

namespace Modules\Provider\Enums;

/** An individual works alone with one hidden main branch; a company has branches and a team. Same app, same tables. */
enum EstablishmentType: string
{
    case Individual = 'individual';
    case Company = 'company';
}
