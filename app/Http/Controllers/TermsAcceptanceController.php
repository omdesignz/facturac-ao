<?php

namespace App\Http\Controllers;

use App\LegalDocumentType;
use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Records that the signed-in user accepted the terms version now in force.
 *
 * Stored per version rather than as a flag, so publishing new terms asks again
 * instead of silently inheriting an agreement to different words.
 */
class TermsAcceptanceController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $terms = LegalDocument::current(LegalDocumentType::Terms);
        $user = $request->user();

        if ($terms === null) {
            return back();
        }

        LegalAcceptance::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'type' => LegalDocumentType::Terms,
                'version' => $terms->version,
            ],
            [
                'legal_document_id' => $terms->id,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'accepted_at' => now(),
            ],
        );

        return back()->with('success', 'Obrigado. Registámos a sua aceitação.');
    }
}
