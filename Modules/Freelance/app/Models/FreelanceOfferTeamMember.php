<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FS-205: one person of a team who works on a team's offer, as it was when the offer was sent (a snapshot - the team changing later does not
 * change it): a PM of the project or an executor, with what they take on. At most one PM per offer.
 *
 * @property int $id
 * @property int $offer_id
 * @property int $freelancer_id
 * @property string $role
 * @property ?string $responsibility
 */
class FreelanceOfferTeamMember extends Model
{
    public const PM = 'pm';

    public const EXECUTOR = 'executor';

    public const ROLES = [self::PM, self::EXECUTOR];

    protected $table = 'freelance_offer_team_members';

    protected $fillable = ['offer_id', 'freelancer_id', 'role', 'responsibility'];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(FreelanceOffer::class, 'offer_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class, 'freelancer_id');
    }
}
