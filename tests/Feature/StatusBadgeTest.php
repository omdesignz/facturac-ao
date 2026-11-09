<?php

use Illuminate\Support\Facades\File;

/** The tones StatusBadge accepts; anything else would render unstyled. */
const STATUS_BADGE_TONES = ['success', 'warning', 'danger', 'info', 'neutral', 'draft', 'contingency'];

test('the status chip is one shape and every tone has a colour', function () {
    $badge = (string) file_get_contents(resource_path('js/components/StatusBadge.vue'));

    expect($badge)
        ->toContain('h-[1.375rem]')
        ->toContain('rounded-[5px]')
        // The colour arrives with a word and a mark, never alone.
        ->toContain('{{ label }}</span>')
        ->toContain('const marks:');

    foreach (STATUS_BADGE_TONES as $tone) {
        expect($badge)->toMatch("/\\b{$tone}:\\s*\\n?\\s*'[^']+'/");
    }
});

test('every page passes StatusBadge a tone it knows', function () {
    $unknown = [];

    foreach (File::allFiles(resource_path('js')) as $file) {
        if ($file->getExtension() !== 'vue') {
            continue;
        }

        preg_match_all('/<StatusBadge\b[^>]*?\stone="([a-z]+)"/s', $file->getContents(), $matches);

        foreach ($matches[1] as $tone) {
            if (! in_array($tone, STATUS_BADGE_TONES, true)) {
                $unknown[] = $file->getRelativePathname().': '.$tone;
            }
        }
    }

    expect($unknown)->toBe([]);
});
