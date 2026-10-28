<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;

/** A project a freelancer saved (FL-207). */
class FreelanceProjectFavorite extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['freelancer_id', 'project_id'];
}
