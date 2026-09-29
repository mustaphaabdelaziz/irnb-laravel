<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A period (holidays, closed stadium) in which no regular session is generated. */
class ClubClosure extends Model
{
    protected $fillable = ['start_date', 'end_date', 'reason'];
}
