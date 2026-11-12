<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;

class FreelanceChecklistTemplateTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['freelance_checklist_template_id', 'locale', 'name', 'items'];
}