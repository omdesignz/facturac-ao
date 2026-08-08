<?php

namespace App\Http\Controllers;

use App\LegalDocumentType;
use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Records a cookie choice.
 *
 * A refusal is stored exactly like an acceptance: proving that someone declined
 * matters as much as proving that they agreed, and a cookie that only exists
 * when the answer was "yes" cannot tell the two apart from never being asked.
 */
class CookieConsentController extends Controller
{
    /** Twelve months, matching what the cookie policy tells the reader. */
    private const REMEMBER_MINUTES = 60 * 24 * 365;

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'boolean'],
            'analytics' => ['required', 'boolean'],
        ]);

        $choices = [
            'essential' => true,
            'preferences' => (bool) $validated['preferences'],
            'analytics' => (bool) $validated['analytics'],
        ];

        $document = LegalDocument::current(LegalDocumentType::Cookies);

        if ($document !== null) {
            LegalAcceptance::query()->create([
                'user_id' => $request->user()?->id,
                'legal_document_id' => $document->id,
                'type' => LegalDocumentType::Cookies,
                'version' => $document->version,
                'choices' => $choices,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'accepted_at' => now(),
            ]);
        }

        Cookie::queue(Cookie::make(
            name: 'cookie_consent',
            value: json_encode([
                'version' => $document->version ?? 0,
                'choices' => $choices,
            ], JSON_THROW_ON_ERROR),
            minutes: self::REMEMBER_MINUTES,
            httpOnly: false,
        ));

        return back();
    }
}
