<?php

namespace App\Models;

use App\Models\Concerns\SerializesClubDates;
use Illuminate\Database\Eloquent\Model;

class StorageLocation extends Model
{
    use SerializesClubDates;

    protected $fillable = ['name'];
}
