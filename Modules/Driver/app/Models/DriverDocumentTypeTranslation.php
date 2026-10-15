<?php

namespace Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;

class DriverDocumentTypeTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['driver_document_type_id', 'locale', 'name', 'instructions'];
}
