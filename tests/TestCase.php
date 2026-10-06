<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Issued-document PDFs are archived as a side effect of issuing and
        // emailing; never let a test write them into the real storage.
        Storage::fake((string) config('fiscal.print.archive_disk'));
    }
}
