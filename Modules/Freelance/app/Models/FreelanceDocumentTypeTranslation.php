<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;

class FreelanceDocumentTypeTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['freelance_document_type_id', 'locale', 'name', 'instructions'];
}
