<?php

namespace App\Models;

use App\Services\Attendance\GenerationMarks;
use Illuminate\Database\Eloquent\Model;

/** A period (holidays, closed stadium) in which no regular session is generated. */
class ClubClosure extends Model
{
    protected $fillable = ['start_date', 'end_date', 'reason'];

    protected static function booted(): void
    {
        // Every category's months under the old and the new period are generated again.
        static::saved(function (ClubClosure $closure) {
            GenerationMarks::forgetDates($closure->start_date, $closure->end_date);
            if ($closure->getOriginal('start_date') !== null) {
                GenerationMarks::forgetDates($closure->getOriginal('start_date'), $closure->getOriginal('end_date'));
            }
        });
        static::deleted(fn (ClubClosure $closure) => GenerationMarks::forgetDates($closure->start_date, $closure->end_date));
    }
}
