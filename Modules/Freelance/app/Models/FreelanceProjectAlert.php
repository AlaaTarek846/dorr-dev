<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;

/** A freelancer who was told about a project that fits them (FL-208) — once per project. */
class FreelanceProjectAlert extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['project_id', 'freelancer_id', 'score'];
}
