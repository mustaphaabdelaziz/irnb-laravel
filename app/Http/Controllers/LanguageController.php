<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LanguageController extends Controller
{
    public function switch(Request $request, string $locale): RedirectResponse
    {
        if (! in_array($locale, ['ar', 'fr', 'en'])) {
            $locale = 'ar';
        }

        app()->setLocale($locale);

        if ($request->user()) {
            $request->user()->update(['preferred_lng' => $locale]);
        }

        return redirect()->back()->withCookie(cookie('lang', $locale, 525600)); // 1 year
    }
}
