<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Models\DocumentLiveActivity;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Where Apple can reach the lock-screen tracker a phone started for a document.
 *
 * Kept per sign-in, like a push device, and only for an issued document of the
 * company on screen. The same token sent again changes nothing.
 */
class MobileLiveActivityController
{
    public function update(Request $request, string $documentPublicId): Response
    {
        Gate::authorize('viewAny', FiscalDocument::class);

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:200', 'regex:/\A[0-9a-f]+\z/'],
            'environment' => ['required', Rule::in(PushDevice::ENVIRONMENTS)],
        ]);

        /** @var LegalEntity $legalEntity */
        $legalEntity = $request->attributes->get('currentLegalEntity');
        /** @var User $user */
        $user = $request->user();

        $document = FiscalDocument::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('public_id', $documentPublicId)
            ->whereNotNull('document_no')
            ->firstOrFail();

        DocumentLiveActivity::query()->updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => $user->id,
                'personal_access_token_id' => $user->currentAccessToken()->getKey(),
                'fiscal_document_id' => $document->id,
                'environment' => $validated['environment'],
            ],
        );

        return response()->noContent();
    }
}
