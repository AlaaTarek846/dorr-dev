<?php

namespace Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethodTranslation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['payment_method_id', 'locale', 'name', 'description'];

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
