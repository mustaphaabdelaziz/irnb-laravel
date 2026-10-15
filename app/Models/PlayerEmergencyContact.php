<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerEmergencyContact extends Model
{
    use HasFactory;
    use SerializesClubDates;

    protected $fillable = [
        'player_id',
        'name',
        'relationship',
        'phones',
    ];

    protected function casts(): array
    {
        return [
            'phones' => 'array',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
