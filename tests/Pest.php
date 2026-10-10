<?php

use App\AgtSubmissionStatus;
use App\Fiscal\Agt\Contracts\AgtGateway;
use App\Fiscal\Agt\Data\AgtDocumentStatusResult;
use App\Fiscal\Agt\Data\AgtInvoiceStatusResult;
use App\Fiscal\Agt\Data\AgtRegistrationResult;
use App\Jobs\PollAgtSubmissionStatus;
use App\Jobs\SubmitAgtDocument;
use App\Models\AgtConnection;
use App\Models\AgtSubmission;
use App\Models\FiscalDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** Build full fake gateway evidence instead of granting fiscal authority by a raw status assignment. */
function recordAuthoritativeAgtAcceptance(FiscalDocument $source, string $reportedStatus = 'V'): void
{
    $source->refresh();
    $submission = $source->submissions()->first() ?? AgtSubmission::factory()->create(['fiscal_document_id' => $source->id, 'agt_connection_id' => AgtConnection::query()->where('legal_entity_id', $source->legal_entity_id)->where('environment', $source->environment)->first()?->id ?? AgtConnection::factory()->create(['workspace_id' => $source->workspace_id, 'legal_entity_id' => $source->legal_entity_id, 'environment' => $source->environment])->id]);
    $requestId = sprintf('%015d', $submission->id);
    $gateway = Mockery::mock(AgtGateway::class);
    $gateway->shouldReceive('registerInvoice')->andReturnUsing(function ($connection, string $body) use ($requestId) {
        $response = json_encode(['requestID' => $requestId], JSON_THROW_ON_ERROR);

        return new AgtRegistrationResult(true, false, '/registarFactura', 200, hash('sha256', $body), $response, hash('sha256', $response), $requestId, [], 'Test acknowledgement', 1);
    });
    $gateway->shouldReceive('queryInvoiceStatus')->andReturnUsing(function ($connection, $entity, string $id) use ($source, $reportedStatus) {
        $request = json_encode(['requestID' => $id, 'schemaVersion' => '2.0', 'taxRegistrationNumber' => $entity->tax_identification_number], JSON_THROW_ON_ERROR);
        $code = $reportedStatus === 'I' ? '2' : '0';
        $response = json_encode(['resultCode' => $code, 'requestErrorList' => [], 'documentStatusList' => [['documentNo' => $source->document_no, 'documentStatus' => $reportedStatus, 'errorList' => []]]], JSON_THROW_ON_ERROR);

        return new AgtInvoiceStatusResult(true, false, '/obterEstado', 200, $request, hash('sha256', $request), $response, hash('sha256', $response), $code, [], [new AgtDocumentStatusResult($source->document_no, $reportedStatus, [])], 'Test validation', 1);
    });
    if (blank($submission->request_id)) {
        $submission->update(['status' => AgtSubmissionStatus::Pending, 'next_attempt_at' => null]);
        (new SubmitAgtDocument($submission->id))->handle($gateway);
    } else {
        $submission->update(['status' => AgtSubmissionStatus::Received, 'next_attempt_at' => null]);
    }
    $submission->refresh()->update(['next_attempt_at' => null]);
    (new PollAgtSubmissionStatus($submission->id))->handle($gateway);
}
