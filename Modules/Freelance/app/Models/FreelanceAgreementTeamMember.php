<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FS-205: one person of a team who works on a team's agreement - the offer's staff, copied when the agreement starts.
 *
 * @property int $id
 * @property int $agreement_id
 * @property int $freelancer_id
 * @property string $role
 * @property ?string $responsibility
 */
class FreelanceAgreementTeamMember extends Model
{
    protected $table = 'freelance_agreement_team_members';

    protected $fillable = ['agreement_id', 'freelancer_id', 'role', 'responsibility'];

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(FreelanceAgreement::class, 'agreement_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class, 'freelancer_id');
    }
}
