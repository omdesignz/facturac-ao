<?php

/**
 * Replays the AGT's acceptance for documents issued from the simulator, so a film
 * can show them validated. Uses the tests' own helper; nothing leaves the machine.
 *
 *   . <dir>/env.sh && php84 tools/demo/agt_replay.php FT26/443 RG26/382
 *
 * Capture straight after: the status reads "(observação antiga)" within minutes.
 */

use App\Models\FiscalDocument;
use Illuminate\Contracts\Console\Kernel;

if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DEMO_DIR') === false) {
    exit("refusing: source <dir>/env.sh from tools/demo/setup.sh first\n");
}

$repo = dirname(__DIR__, 4);
chdir($repo);
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.connections.sqlite.database') !== getenv('DB_DATABASE')) {
    exit("refusing: not the demo database\n");
}

require 'tests/PosFixtures.php';
$pest = file_get_contents('tests/Pest.php');
preg_match('/function recordAuthoritativeAgtAcceptance.*?\n}\n/s', $pest, $match);
$uses = implode("\n", array_filter(explode("\n", $pest), fn ($line) => str_starts_with($line, 'use App') || str_starts_with($line, 'use Illuminate')));
eval($uses."\n".$match[0]);

foreach (array_slice($argv, 1) as $number) {
    $document = FiscalDocument::query()->where('document_no', 'like', "%$number")->firstOrFail();
    recordAuthoritativeAgtAcceptance($document);
    echo "accepted $number\n";
}
