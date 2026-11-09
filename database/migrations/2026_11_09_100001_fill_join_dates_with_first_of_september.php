<?php

use App\Services\Player\JoinDateSuggestion;
use App\Services\Player\RegistrationService;
use Illuminate\Database\Migrations\Migration;

/**
 * Owner request 2026-11-08: registrations known only by their join year get
 * 1 September of that year, so the desktop install (no terminal) and the server
 * are dated on their next update. A second branch of a two-branch player
 * (swimming) stays in the queue for the admin. Same rule as players:fill-join-dates.
 */
return new class extends Migration
{
    public function up(): void
    {
        JoinDateSuggestion::fillAll(app(RegistrationService::class));
    }

    /** A filled date can't be told apart from one typed by hand, so nothing is undone. */
    public function down(): void {}
};
