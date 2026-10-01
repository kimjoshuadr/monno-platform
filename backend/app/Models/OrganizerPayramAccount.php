<?php

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizerPayramAccount extends BaseModel
{
    protected function getTimestampsEnabled(): bool
    {
        return true;
    }

    protected function getCastMap(): array
    {
        return [
            // Credentials never leave the database in plain text.
            'api_key' => 'encrypted',
            'provisioned_password' => 'encrypted',
            'supported_currencies' => 'array',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }
}
