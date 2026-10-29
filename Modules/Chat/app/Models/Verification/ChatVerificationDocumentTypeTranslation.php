<?php

namespace Modules\Chat\Models\Verification;

use Illuminate\Database\Eloquent\Model;

class ChatVerificationDocumentTypeTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['chat_verification_document_type_id', 'locale', 'name', 'instructions'];
}
