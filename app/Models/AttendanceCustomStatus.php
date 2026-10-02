<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * An attendance code added by the owner (see AttendanceStatusCatalog). Its
 * `key` (c_<id>) is what marks store; `behaviour` is how such a mark counts:
 * present, absent_excused, absent_unexcused or not_counted.
 */
class AttendanceCustomStatus extends Model
{
    public const BEHAVIOURS = ['present', 'absent_excused', 'absent_unexcused', 'not_counted'];

    public const KEY_PREFIX = 'c_';

    protected $fillable = ['key', 'code', 'color', 'label_ar', 'label_fr', 'label_en', 'behaviour', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** Creates the status and gives it its permanent key c_<id>, atomically. */
    public static function createWithKey(array $attributes): self
    {
        return DB::transaction(function () use ($attributes) {
            $status = self::create(['key' => uniqid('tmp', false)] + $attributes);
            $status->update(['key' => self::KEY_PREFIX.$status->id]);

            return $status;
        });
    }

    /** Whether any mark uses this status (it can then only be hidden, never deleted). */
    public function isUsed(): bool
    {
        return Attendance::where('status', $this->key)->exists();
    }
}
