<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Native\Desktop\Facades\Shell;

/**
 * Desktop app only: a link with target="_blank" would open wa.me in a bare
 * Electron window, so the page posts the number here and the OS opens it in
 * the default browser (which hands over to WhatsApp). Only wa.me chat links
 * are ever opened — the request carries digits, never a URL.
 */
class OpenWhatsAppController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\d{6,15}$/'],
        ]);

        Shell::openExternal('https://wa.me/'.$data['phone']);

        return back();
    }
}
