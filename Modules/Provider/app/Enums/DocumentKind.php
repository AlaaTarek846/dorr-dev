<?php

namespace Modules\Provider\Enums;

/** A photo is a picture of the vehicle itself (one that shows its plate, an angle…); a document is a paper. */
enum DocumentKind: string
{
    case Document = 'document';
    case Photo = 'photo';
}
