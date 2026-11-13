<?php

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayramPayment extends BaseModel
{
    protected function getTimestampsEnabled(): bool
    {
        return true;
    }

    protected function getCastMap(): array
    {
        return [
            'payment_info' => 'array',
            'last_webhook_payload' => 'array',
            'amount_in_usd' => 'float',
            'order_amount' => 'float',
            'fx_rate' => 'float',
            'platform_fee_usd' => 'float',
            'filled_amount' => 'float',
            'filled_amount_in_usd' => 'float',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
