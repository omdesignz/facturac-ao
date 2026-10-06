<?php

namespace App\Fiscal\Agt;

use App\AgtOperation;
use App\Fiscal\Agt\Contracts\AgtGateway;
use App\Fiscal\Agt\Data\AgtDocumentStatusResult;
use App\Fiscal\Agt\Data\AgtInvoiceStatusResult;
use App\Fiscal\Agt\Data\AgtProbeResult;
use App\Fiscal\Agt\Data\AgtRegistrationResult;
use App\Fiscal\Agt\Data\AgtSeriesData;
use App\Fiscal\Agt\Data\AgtSeriesListResult;
use App\Fiscal\Agt\Data\AgtSeriesRequestResult;
use App\Fiscal\Agt\Exceptions\SigningKeyUnavailable;
use App\Fiscal\Agt\Exceptions\UnsupportedAgtSchema;
use App\Fiscal\Agt\Support\CanonicalJson;
use App\Fiscal\Agt\V2_0\AgtRequestPayloadBuilder;
use App\FiscalDocumentType;
use App\FiscalSeriesContingency;
use App\FiscalSeriesStatus;
use App\Models\AgtConnection;
use App\Models\LegalEntity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final readonly class RestAgtGateway implements AgtGateway
{
    public function __construct(
        private AgtRequestPayloadBuilder $payloadBuilder,
        private CanonicalJson $canonicalJson,
    ) {}

    public function probeListSeries(AgtConnection $connection, LegalEntity $legalEntity): AgtProbeResult
    {
        $result = $this->listSeries($connection, $legalEntity);

        return new AgtProbeResult(
            successful: $result->successful,
            endpoint: $result->endpoint,
            httpStatus: $result->httpStatus,
            requestBodySha256: $result->requestBodySha256,
            responseBodySha256: $result->responseBodySha256,
            resultCode: $result->resultCode,
            errorCodes: $result->errorCodes,
            safeMessage: $result->successful
                ? 'Ligação ao ambiente de homologação verificada pela AGT.'
                : $result->safeMessage,
            durationMs: $result->durationMs,
            attemptCount: $result->attemptCount,
        );
    }

    public function listSeries(AgtConnection $connection, LegalEntity $legalEntity): AgtSeriesListResult
    {
        $endpointPath = AgtOperation::ListSeriesProbe->endpointPath();
        $startedAt = hrtime(true);
        $requestHash = null;

        try {
            $requestBody = $this->payloadBuilder->listSeries($connection, $legalEntity, now('UTC'));
            $encodedBody = $this->canonicalJson->encode($requestBody);
            $requestHash = hash('sha256', $encodedBody);
            $response = $this->post($connection, $endpointPath, $encodedBody);

            return $this->seriesResult($response, $endpointPath, $requestHash, $startedAt);
        } catch (UnsupportedAgtSchema $exception) {
            return $this->failedSeriesResult($endpointPath, $requestHash, $startedAt, $exception->getMessage(), 'AGT_SCHEMA_UNSUPPORTED', 0);
        } catch (SigningKeyUnavailable $exception) {
            return $this->failedSeriesResult(
                $endpointPath,
                $requestHash,
                $startedAt,
                $exception->getMessage(),
                'SIGNING_KEY_UNAVAILABLE',
                0,
            );
        } catch (ConnectionException) {
            return $this->failedSeriesResult(
                $endpointPath,
                $requestHash,
                $startedAt,
                'Não foi possível alcançar o serviço AGT dentro do tempo limite.',
                'AGT_UNREACHABLE',
            );
        } catch (Throwable) {
            return $this->failedSeriesResult(
                $endpointPath,
                $requestHash,
                $startedAt,
                'A consulta de séries não pôde ser concluída de forma segura.',
                'GATEWAY_FAILURE',
                0,
            );
        }
    }

    public function requestSeries(
        AgtConnection $connection,
        LegalEntity $legalEntity,
        FiscalDocumentType $documentType,
        int $seriesYear,
        FiscalSeriesContingency $contingency,
        string $submissionUuid,
    ): AgtSeriesRequestResult {
        $endpointPath = AgtOperation::RequestSeries->endpointPath();
        $startedAt = hrtime(true);
        $requestHash = null;

        try {
            $requestBody = $this->payloadBuilder->requestSeries(
                $connection,
                $legalEntity,
                $documentType,
                $seriesYear,
                $contingency,
                $submissionUuid,
                now('UTC'),
            );
            $encodedBody = $this->canonicalJson->encode($requestBody);
            $requestHash = hash('sha256', $encodedBody);
            $response = $this->post($connection, $endpointPath, $encodedBody);

            return $this->seriesRequestResult($response, $endpointPath, $requestHash, $startedAt);
        } catch (UnsupportedAgtSchema $exception) {
            return $this->failedSeriesRequestResult($endpointPath, $requestHash, $startedAt, $exception->getMessage(), 'AGT_SCHEMA_UNSUPPORTED', 0);
        } catch (SigningKeyUnavailable $exception) {
            return $this->failedSeriesRequestResult(
                $endpointPath,
                $requestHash,
                $startedAt,
                $exception->getMessage(),
                'SIGNING_KEY_UNAVAILABLE',
                0,
            );
        } catch (ConnectionException) {
            return $this->failedSeriesRequestResult(
                $endpointPath,
                $requestHash,
                $startedAt,
                'Não foi possível confirmar se a AGT criou a série. Sincronize as séries antes de repetir o pedido.',
                'AGT_SERIES_REQUEST_UNCERTAIN',
            );
        } catch (Throwable) {
            return $this->failedSeriesRequestResult(
                $endpointPath,
                $requestHash,
                $startedAt,
                'O pedido de série não pôde ser concluído de forma segura.',
                'GATEWAY_FAILURE',
                0,
            );
        }
    }

    public function registerInvoice(AgtConnection $connection, string $requestBody): AgtRegistrationResult
    {
        $endpointPath = (string) config('agt.operations.register_invoice.path', '/registarFactura');
        $startedAt = hrtime(true);
        $requestHash = hash('sha256', $requestBody);

        try {
            $response = $this->post($connection, $endpointPath, $requestBody);
            $responseBody = $response->body();
            $payload = $this->payload($response);
            $requestId = $this->requestId($payload['requestID'] ?? null);
            $errorCodes = $this->responseErrors($payload, 'errorList');
            $accepted = $response->successful() && $requestId !== null && $errorCodes === [];

            return new AgtRegistrationResult(
                accepted: $accepted,
                retryable: $this->isRetryable($response),
                endpoint: $endpointPath,
                httpStatus: $response->status(),
                requestBodySha256: $requestHash,
                responseBody: $responseBody,
                responseBodySha256: hash('sha256', $responseBody),
                requestId: $requestId,
                errorCodes: $errorCodes,
                safeMessage: $accepted
                    ? 'Pedido recebido pela AGT e encaminhado para validação.'
                    : $this->safeResponseMessage($response, $errorCodes, 'registo da factura'),
                durationMs: $this->durationMs($startedAt),
            );
        } catch (UnsupportedAgtSchema $exception) {
            return $this->failedRegistrationResult($endpointPath, $requestHash, $startedAt, $exception->getMessage(), 'AGT_SCHEMA_UNSUPPORTED', false);
        } catch (ConnectionException) {
            return $this->failedRegistrationResult(
                $endpointPath,
                $requestHash,
                $startedAt,
                'Não foi possível confirmar se a AGT recebeu o pedido; os mesmos bytes serão reutilizados.',
                'AGT_UNREACHABLE',
                true,
            );
        } catch (Throwable) {
            return $this->failedRegistrationResult(
                $endpointPath,
                $requestHash,
                $startedAt,
                'A transmissão não pôde ser concluída de forma segura.',
                'GATEWAY_FAILURE',
                true,
            );
        }
    }

    public function queryInvoiceStatus(
        AgtConnection $connection,
        LegalEntity $legalEntity,
        string $requestId,
    ): AgtInvoiceStatusResult {
        $endpointPath = (string) config('agt.operations.invoice_status.path', '/obterEstado');
        $startedAt = hrtime(true);
        $encodedBody = '';

        try {
            $requestBody = $this->payloadBuilder->invoiceStatus(
                $connection,
                $legalEntity,
                $requestId,
                (string) Str::uuid(),
                now('UTC'),
            );
            $encodedBody = $this->canonicalJson->encode($requestBody);
            $response = $this->post($connection, $endpointPath, $encodedBody);
            $responseBody = $response->body();
            $payload = $this->payload($response);
            $resultCode = $this->normalizedCode($payload['resultCode'] ?? null);
            $requestErrorCodes = $this->responseErrors($payload, 'requestErrorList');
            $documents = $this->documentStatuses($payload['documentStatusList'] ?? null);
            $successful = $response->successful()
                && in_array($resultCode, ['0', '1', '2', '7', '8', '9'], true)
                && $requestErrorCodes === [];

            return new AgtInvoiceStatusResult(
                successful: $successful,
                retryable: $this->isRetryable($response)
                    || ($response->status() === 422 && $requestErrorCodes !== []
                        && array_diff($requestErrorCodes, ['E96', 'E97']) === []),
                endpoint: $endpointPath,
                httpStatus: $response->status(),
                requestBody: $encodedBody,
                requestBodySha256: hash('sha256', $encodedBody),
                responseBody: $responseBody,
                responseBodySha256: hash('sha256', $responseBody),
                resultCode: $resultCode,
                requestErrorCodes: $requestErrorCodes,
                documents: $documents,
                safeMessage: $successful
                    ? $this->statusMessage($resultCode)
                    : $this->safeResponseMessage($response, $requestErrorCodes, 'consulta do estado'),
                durationMs: $this->durationMs($startedAt),
            );
        } catch (UnsupportedAgtSchema $exception) {
            return $this->failedStatusResult($endpointPath, $encodedBody, $startedAt, $exception->getMessage(), 'AGT_SCHEMA_UNSUPPORTED', false);
        } catch (SigningKeyUnavailable $exception) {
            return $this->failedStatusResult(
                $endpointPath,
                $encodedBody,
                $startedAt,
                $exception->getMessage(),
                'SIGNING_KEY_UNAVAILABLE',
                false,
            );
        } catch (ConnectionException) {
            return $this->failedStatusResult(
                $endpointPath,
                $encodedBody,
                $startedAt,
                'Não foi possível consultar a AGT dentro do tempo limite.',
                'AGT_UNREACHABLE',
                true,
            );
        } catch (Throwable) {
            return $this->failedStatusResult(
                $endpointPath,
                $encodedBody,
                $startedAt,
                'A consulta do estado não pôde ser concluída de forma segura.',
                'GATEWAY_FAILURE',
                true,
            );
        }
    }

    private function post(AgtConnection $connection, string $endpointPath, string $body): Response
    {
        UnsupportedAgtSchema::assertSupported($connection->schema_version);
        $payload = json_decode($body, true);
        $schemaVersion = is_array($payload) ? ($payload['schemaVersion'] ?? null) : null;
        UnsupportedAgtSchema::assertSupported(is_string($schemaVersion) ? $schemaVersion : '');

        return Http::withBasicAuth(
            (string) $connection->basic_auth_username,
            (string) $connection->basic_auth_password,
        )
            ->acceptJson()
            ->connectTimeout((int) config('agt.transport.connect_timeout_seconds', 3))
            ->timeout((int) config('agt.transport.timeout_seconds', 10))
            ->withBody($body, 'application/json')
            ->post($connection->environment->baseUrl().$endpointPath);
    }

    private function seriesResult(
        Response $response,
        string $endpointPath,
        string $requestHash,
        int $startedAt,
    ): AgtSeriesListResult {
        $responseBody = $response->body();
        $payload = $this->payload($response);
        $resultCode = $this->normalizedCode($payload['resultCode'] ?? null);
        $errorCodes = $this->responseErrors($payload, 'errorList');
        $rawSeries = data_get($payload, 'seriesInfo')
            ?? data_get($payload, 'seriresInfo')
            ?? data_get($payload, 'seriesListResult.seriesInfo');
        $rawSeries = is_array($rawSeries) && array_is_list($rawSeries) ? $rawSeries : null;
        $series = $rawSeries === null ? [] : $this->series($rawSeries);
        $declaredCount = $this->positiveOrZeroInteger(
            data_get($payload, 'seriesResultCount')
                ?? data_get($payload, 'seriesListResult.seriesResultCount'),
        );
        $contractValid = $rawSeries !== null
            && count($series) === count($rawSeries)
            && ($declaredCount === null || $declaredCount === count($rawSeries));
        $successful = $response->successful() && $errorCodes === [] && $contractValid;

        return new AgtSeriesListResult(
            successful: $successful,
            endpoint: $endpointPath,
            httpStatus: $response->status(),
            requestBodySha256: $requestHash,
            responseBodySha256: hash('sha256', $responseBody),
            resultCode: $resultCode,
            errorCodes: $successful ? [] : ($errorCodes !== [] ? $errorCodes : ['AGT_SERIES_CONTRACT_INVALID']),
            series: $successful ? $series : [],
            safeMessage: $successful
                ? count($series).' série(s) fiscal(is) recebida(s) da AGT.'
                : $this->safeResponseMessage($response, $errorCodes, 'consulta de séries'),
            durationMs: $this->durationMs($startedAt),
        );
    }

    private function seriesRequestResult(
        Response $response,
        string $endpointPath,
        string $requestHash,
        int $startedAt,
    ): AgtSeriesRequestResult {
        $responseBody = $response->body();
        $payload = $this->payload($response);
        $resultCode = $this->normalizedCode($payload['resultCode'] ?? null);
        $errorCodes = $this->responseErrors($payload, 'errorList');
        $rawSeries = data_get($payload, 'seriesFEResult');
        $seriesCode = is_array($rawSeries) ? $this->seriesCode($rawSeries['seriesCode'] ?? null) : null;
        $authorizedQuantity = is_array($rawSeries)
            ? $this->positiveInteger($rawSeries['authorizedQuantity'] ?? null)
            : null;
        $firstDocumentNumber = is_array($rawSeries)
            ? $this->documentNumber($rawSeries['firstDocumentNo'] ?? null)
            : null;
        $lastDocumentNumber = is_array($rawSeries)
            ? $this->documentNumber($rawSeries['lastDocumentNo'] ?? null)
            : null;
        $contractValid = in_array($resultCode, ['0', '1'], true)
            && $seriesCode !== null
            && $authorizedQuantity !== null
            && $firstDocumentNumber !== null
            && $lastDocumentNumber !== null;
        $successful = $response->successful() && $errorCodes === [] && $contractValid;

        return new AgtSeriesRequestResult(
            successful: $successful,
            endpoint: $endpointPath,
            httpStatus: $response->status(),
            requestBodySha256: $requestHash,
            responseBodySha256: hash('sha256', $responseBody),
            resultCode: $resultCode,
            errorCodes: $successful ? [] : ($errorCodes !== [] ? $errorCodes : ['AGT_SERIES_CONTRACT_INVALID']),
            seriesCode: $successful ? $seriesCode : null,
            authorizedQuantity: $successful ? $authorizedQuantity : null,
            firstDocumentNumber: $successful ? $firstDocumentNumber : null,
            lastDocumentNumber: $successful ? $lastDocumentNumber : null,
            safeMessage: $successful
                ? "Série {$seriesCode} autorizada pela AGT com {$authorizedQuantity} número(s)."
                : $this->safeResponseMessage($response, $errorCodes, 'solicitação de série'),
            durationMs: $this->durationMs($startedAt),
        );
    }

    /**
     * @param  list<mixed>  $rawSeries
     * @return list<AgtSeriesData>
     */
    private function series(array $rawSeries): array
    {
        $series = [];

        foreach ($rawSeries as $item) {
            if (! is_array($item)) {
                continue;
            }

            $seriesCode = $this->seriesCode($item['seriesCode'] ?? null);
            $seriesYear = $this->positiveInteger($item['seriesYear'] ?? null);
            $documentType = is_string($item['documentType'] ?? null)
                ? FiscalDocumentType::tryFrom($item['documentType'])
                : null;
            $status = is_string($item['seriesStatus'] ?? null)
                ? FiscalSeriesStatus::tryFrom($item['seriesStatus'])
                : null;
            $contingency = is_string($item['seriesContingencyIndicator'] ?? null)
                ? FiscalSeriesContingency::tryFrom($item['seriesContingencyIndicator'])
                : null;
            $firstApproved = $this->documentNumber($item['firstDocumentApproved'] ?? null);
            $lastApproved = $this->documentNumber($item['lastDocumentApproved'] ?? null);
            $invoicingMethod = $this->invoicingMethod($item['invoicingMethod'] ?? null);

            if ($seriesCode === null
                || $seriesYear === null
                || $documentType === null
                || $status === null
                || $contingency === null
                || $firstApproved === null
                || $lastApproved === null
                || $invoicingMethod === null) {
                continue;
            }

            $series[] = new AgtSeriesData(
                seriesCode: $seriesCode,
                seriesYear: $seriesYear,
                documentType: $documentType,
                status: $status,
                creationDate: $this->date($item['seriesCreationDate'] ?? null),
                firstDocumentApproved: $firstApproved,
                lastDocumentApproved: $lastApproved,
                firstDocumentCreated: $this->optionalDocumentNumber($item['firstDocumentCreated'] ?? null),
                lastDocumentCreated: $this->optionalDocumentNumber($item['lastDocumentCreated'] ?? null),
                invoicingMethod: $invoicingMethod,
                contingency: $contingency,
            );
        }

        return $series;
    }

    /** @return list<AgtDocumentStatusResult> */
    private function documentStatuses(mixed $statusList): array
    {
        if (! is_array($statusList) || ! array_is_list($statusList)) {
            return [];
        }

        $documents = [];

        foreach ($statusList as $item) {
            if (! is_array($item)) {
                continue;
            }

            $documentNumber = $this->documentNumber($item['documentNo'] ?? null);
            $status = $item['documentStatus'] ?? null;

            if ($documentNumber === null || ! is_string($status) || ! in_array($status, ['V', 'I'], true)) {
                continue;
            }

            $documents[] = new AgtDocumentStatusResult(
                documentNumber: $documentNumber,
                status: $status,
                errorCodes: $this->errorCodes($item['errorList'] ?? null),
            );
        }

        return $documents;
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        $payload = $response->json();

        return is_array($payload) && ! array_is_list($payload) ? $payload : [];
    }

    /** @return list<string> */
    private function errorCodes(mixed $errorList): array
    {
        if (! is_array($errorList)) {
            return [];
        }

        $entries = array_is_list($errorList) ? $errorList : [$errorList];
        $codes = [];

        foreach ($entries as $error) {
            if (count($codes) >= 50) {
                break;
            }

            if (! is_array($error)) {
                continue;
            }

            $code = $this->normalizedCode(
                $error['idError']
                    ?? $error['errorCode']
                    ?? $error['code']
                    ?? null,
            );

            if ($code !== null) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function responseErrors(array $payload, string $listField): array
    {
        return array_values(array_unique([
            ...$this->errorCodes($payload[$listField] ?? null),
            ...$this->errorCodes($payload['errorEntry'] ?? null),
            ...$this->errorCodes($payload),
        ]));
    }

    private function normalizedCode(mixed $code): ?string
    {
        if (! is_string($code) && ! is_int($code)) {
            return null;
        }

        $code = trim((string) $code);

        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,63}\z/', $code) === 1
            ? $code
            : null;
    }

    private function requestId(mixed $requestId): ?string
    {
        if (! is_string($requestId)) {
            return null;
        }

        $requestId = trim($requestId);

        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,14}\z/', $requestId) === 1
            ? $requestId
            : null;
    }

    private function seriesCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = Str::upper(trim($value));

        return preg_match('/\A[A-Z0-9][A-Z0-9._-]{1,31}\z/', $value) === 1 ? $value : null;
    }

    private function documentNumber(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' && mb_strlen($value) <= 60 ? $value : null;
    }

    private function optionalDocumentNumber(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : $this->documentNumber($value);
    }

    private function invoicingMethod(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = Str::upper(trim($value));

        return in_array($value, ['FEPC', 'FESF', 'SF'], true) ? $value : null;
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1
            ? $value
            : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($integer) ? $integer : null;
    }

    private function positiveOrZeroInteger(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return is_int($integer) ? $integer : null;
    }

    /** @param list<string> $errorCodes */
    private function safeResponseMessage(Response $response, array $errorCodes, string $operation): string
    {
        return match (true) {
            $response->status() === 401 => 'A AGT recusou as credenciais de acesso.',
            $response->status() === 403 => 'As credenciais não têm autorização para esta operação.',
            $response->status() === 429 => 'A AGT limitou temporariamente os pedidos.',
            $response->serverError() => 'O serviço AGT está temporariamente indisponível.',
            $response->clientError() => "A AGT recusou a {$operation}.",
            $errorCodes !== [] => "A AGT devolveu erros na {$operation}.",
            default => 'A resposta da AGT não correspondeu ao contrato esperado.',
        };
    }

    private function statusMessage(?string $resultCode): string
    {
        return match ($resultCode) {
            '0' => 'Processamento concluído sem facturas inválidas.',
            '1' => 'Processamento concluído com resultados mistos.',
            '2' => 'Processamento concluído sem facturas válidas.',
            '7' => 'Consulta prematura ou repetitiva; será retomada mais tarde.',
            '8' => 'A validação ainda está em curso.',
            '9' => 'O processamento foi cancelado pela AGT.',
            default => 'Estado AGT recebido.',
        };
    }

    private function isRetryable(Response $response): bool
    {
        return in_array($response->status(), [408, 425, 429], true) || $response->serverError();
    }

    private function failedSeriesResult(
        string $endpointPath,
        ?string $requestHash,
        int $startedAt,
        string $message,
        string $errorCode,
        int $attemptCount = 1,
    ): AgtSeriesListResult {
        return new AgtSeriesListResult(
            successful: false,
            endpoint: $endpointPath,
            httpStatus: null,
            requestBodySha256: $requestHash,
            responseBodySha256: null,
            resultCode: null,
            errorCodes: [$errorCode],
            series: [],
            safeMessage: $message,
            durationMs: $this->durationMs($startedAt),
            attemptCount: $attemptCount,
        );
    }

    private function failedSeriesRequestResult(
        string $endpointPath,
        ?string $requestHash,
        int $startedAt,
        string $message,
        string $errorCode,
        int $attemptCount = 1,
    ): AgtSeriesRequestResult {
        return new AgtSeriesRequestResult(
            successful: false,
            endpoint: $endpointPath,
            httpStatus: null,
            requestBodySha256: $requestHash,
            responseBodySha256: null,
            resultCode: null,
            errorCodes: [$errorCode],
            seriesCode: null,
            authorizedQuantity: null,
            firstDocumentNumber: null,
            lastDocumentNumber: null,
            safeMessage: $message,
            durationMs: $this->durationMs($startedAt),
            attemptCount: $attemptCount,
        );
    }

    private function failedRegistrationResult(
        string $endpointPath,
        string $requestHash,
        int $startedAt,
        string $message,
        string $errorCode,
        bool $retryable,
    ): AgtRegistrationResult {
        return new AgtRegistrationResult(
            accepted: false,
            retryable: $retryable,
            endpoint: $endpointPath,
            httpStatus: null,
            requestBodySha256: $requestHash,
            responseBody: null,
            responseBodySha256: null,
            requestId: null,
            errorCodes: [$errorCode],
            safeMessage: $message,
            durationMs: $this->durationMs($startedAt),
        );
    }

    private function failedStatusResult(
        string $endpointPath,
        string $requestBody,
        int $startedAt,
        string $message,
        string $errorCode,
        bool $retryable,
    ): AgtInvoiceStatusResult {
        return new AgtInvoiceStatusResult(
            successful: false,
            retryable: $retryable,
            endpoint: $endpointPath,
            httpStatus: null,
            requestBody: $requestBody,
            requestBodySha256: hash('sha256', $requestBody),
            responseBody: null,
            responseBodySha256: null,
            resultCode: null,
            requestErrorCodes: [$errorCode],
            documents: [],
            safeMessage: $message,
            durationMs: $this->durationMs($startedAt),
        );
    }

    private function durationMs(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
