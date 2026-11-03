<?php

namespace App\Http\Controllers;

use App\Models\BranchRegistration;
use App\Models\Player;
use App\Services\Player\RegistrationService;
use Illuminate\Http\RedirectResponse;

class RegistrationController extends Controller
{
    public function destroy(Player $player, BranchRegistration $registration, RegistrationService $registrations): RedirectResponse
    {
        abort_unless((int) $registration->player_id === (int) $player->id, 404);

        $refused = $registrations->removeMistake($registration);

        return $refused !== null
            ? back()->with('error', $refused)
            : back()->with('success', 'flash.registration_deleted');
    }
}
