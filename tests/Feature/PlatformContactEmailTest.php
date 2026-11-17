<?php

use App\LegalDocumentType;
use App\Models\LegalDocument;
use App\Models\PlatformSetting;
use Inertia\Testing\AssertableInertia as Assert;

test('retired public contacts are corrected without rewriting accepted legal versions', function () {
    PlatformSetting::put(['complaints_email' => 'reclamacoes@vapsolucoes.ao', 'data_protection_email' => 'privacidade@vapsolucoes.ao']);
    PlatformSetting::values();
    $previous = LegalDocument::factory()->ofType(LegalDocumentType::Privacy)->create([
        'body' => 'Privacidade: privacidade@vapsolucoes.ao. Reclamações: reclamacoes@vapsolucoes.ao.',
    ]);
    $previous->forceFill(['effective_at' => now()->subDay(), 'published_at' => now()->subDay()])->save();
    $migration = require database_path('migrations/2026_11_17_212003_consolidate_platform_contact_emails.php');
    $migration->up();
    $migration->up();

    expect(PlatformSetting::get('complaints_email'))->toBe('geral@vapsolucoes.ao')
        ->and(PlatformSetting::get('data_protection_email'))->toBe('geral@vapsolucoes.ao')
        ->and($previous->fresh()->body)->toContain('privacidade@vapsolucoes.ao')
        ->and(LegalDocument::where('type', LegalDocumentType::Privacy)->count())->toBe(2);

    $this->get('/'.LegalDocumentType::Privacy->slug())->assertInertia(fn (Assert $page) => $page
        ->where('contact.privacy_email', 'geral@vapsolucoes.ao')
        ->where('document.body_html', fn (string $body): bool => str_contains($body, 'geral@vapsolucoes.ao') && ! str_contains($body, 'privacidade@vapsolucoes.ao') && ! str_contains($body, 'reclamacoes@vapsolucoes.ao'))
    );
});

test('contact correction preserves custom deployment contacts and unrelated legal text', function () {
    PlatformSetting::put(['data_protection_email' => 'privacy@example.test']);
    $document = LegalDocument::factory()->ofType(LegalDocumentType::Privacy)->create(['body' => 'Contact privacy@example.test']);
    $document->forceFill(['effective_at' => now()->subDay(), 'published_at' => now()->subDay()])->save();
    $migration = require database_path('migrations/2026_11_17_212003_consolidate_platform_contact_emails.php');
    $migration->up();
    expect(PlatformSetting::get('data_protection_email'))->toBe('privacy@example.test')
        ->and(LegalDocument::where('type', LegalDocumentType::Privacy)->count())->toBe(1);
});
