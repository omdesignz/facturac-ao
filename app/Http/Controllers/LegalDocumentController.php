<?php

namespace App\Http\Controllers;

use App\LegalDocumentType;
use App\Models\LegalDocument;
use App\Models\PlatformSetting;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The public legal pages.
 *
 * Deliberately reachable without an account: someone deciding whether to sign
 * up has to be able to read the terms first.
 */
class LegalDocumentController extends Controller
{
    public function show(string $slug): Response
    {
        $type = $this->typeFromSlug($slug);
        $document = LegalDocument::current($type);

        abort_if($document === null, HttpResponse::HTTP_NOT_FOUND);

        return Inertia::render('Legal/Show', [
            'document' => [
                'type' => $type->value,
                'title' => $document->title,
                'summary' => $document->summary,
                // Rendered here, with raw HTML escaped, so a document can only
                // ever produce the markup Markdown allows.
                'body_html' => Str::markdown($document->body, [
                    'html_input' => 'escape',
                    'allow_unsafe_links' => false,
                ]),
                'version' => $document->version,
                'effective_at' => $document->effective_at?->toIso8601String(),
            ],
            'documents' => $this->index(),
            'contact' => [
                'privacy_email' => PlatformSetting::get('data_protection_email'),
                'support_email' => PlatformSetting::get('support_email'),
                'company' => PlatformSetting::get('company_legal_name'),
            ],
        ]);
    }

    /**
     * Every document currently in force, for cross-linking between the pages.
     *
     * @return list<array{type: string, label: string, slug: string, version: int}>
     */
    private function index(): array
    {
        return array_values(array_filter(array_map(
            function (LegalDocumentType $type): ?array {
                $document = LegalDocument::current($type);

                return $document === null ? null : [
                    'type' => $type->value,
                    'label' => $type->label(),
                    'slug' => $type->slug(),
                    'version' => $document->version,
                ];
            },
            LegalDocumentType::ordered(),
        )));
    }

    private function typeFromSlug(string $slug): LegalDocumentType
    {
        foreach (LegalDocumentType::cases() as $type) {
            if ($type->slug() === $slug) {
                return $type;
            }
        }

        abort(HttpResponse::HTTP_NOT_FOUND);
    }
}
