<?php

namespace Modules\Provider\Enums;

/** Who a document type is asked from. A driver's documents are stored on their membership. */
enum DocumentSubject: string
{
    case Establishment = 'establishment';
    case Vehicle = 'vehicle';
    case Driver = 'driver';

    /** The provider_documents.subject_type this subject is stored under. */
    public function storedAs(): string
    {
        return $this === self::Driver ? 'membership' : $this->value;
    }
}
