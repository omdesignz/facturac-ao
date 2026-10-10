<?php

namespace App\Fiscal\Documents;

use App\AgtSubmissionAttemptOperation;
use App\Fiscal\Agt\Data\AgtInvoiceStatusResult;
use App\Fiscal\Agt\Data\AgtRegistrationResult;
use App\Models\AgtSubmission;
use App\Models\AgtSubmissionAttempt;

/** Strict interpretation of protected bytes; no upstream prose enters the projection. */
final class AgtEvidence
{
    /** @return array<string, mixed> */
    public static function fromAttempt(AgtSubmission $submission, AgtSubmissionAttempt $attempt): array
    {
        $request = $attempt->request_body;
        $response = $attempt->response_body;
        $raw = json_decode($response ?? '', true);
        if ($attempt->operation === AgtSubmissionAttemptOperation::QueryStatus) {
            $code = is_array($raw) && (is_string($raw['resultCode'] ?? null) || is_int($raw['resultCode'] ?? null)) ? (string) $raw['resultCode'] : null;
            $result = new AgtInvoiceStatusResult($attempt->http_status !== null && $attempt->http_status >= 200 && $attempt->http_status < 300,
                false, $attempt->endpoint_path, $attempt->http_status, $request, $attempt->request_body_sha256,
                $response, $attempt->response_body_sha256, $code, [], [], '', 0);
        } else {
            $requestId = is_array($raw) && is_string($raw['requestID'] ?? null) ? $raw['requestID'] : null;
            $result = new AgtRegistrationResult($requestId !== null && $requestId === $submission->request_id, false,
                $attempt->endpoint_path, $attempt->http_status, $attempt->request_body_sha256, $response,
                $attempt->response_body_sha256, $requestId, [], '', 0);
        }

        $outcome = self::interpret($submission, $result);
        $missingHash = blank($attempt->request_body_sha256) || ($response !== null && blank($attempt->response_body_sha256));
        $mismatchedHash = (filled($attempt->request_body_sha256) && ! hash_equals(hash('sha256', $request), $attempt->request_body_sha256))
            || ($response !== null && filled($attempt->response_body_sha256) && ! hash_equals(hash('sha256', $response), $attempt->response_body_sha256));
        $missingSuccessfulResponse = $response === null && $attempt->http_status !== null && $attempt->http_status >= 200 && $attempt->http_status < 300;
        if (($missingHash || $missingSuccessfulResponse) && ! $mismatchedHash) {
            return [...$outcome, 'classification' => 'partial', 'knowledge' => 'legacy_unverified', 'reported_state' => null,
                'successful_sync' => false, 'sync' => 'failed', 'reason' => 'legacy_unverified'];
        }

        return $outcome;
    }

    /** @return array<string, mixed> */
    public static function interpret(AgtSubmission $submission, AgtInvoiceStatusResult|AgtRegistrationResult $result): array
    {
        $state = ['version' => 1, 'classification' => 'unknown', 'knowledge' => 'unknown_response', 'reported_state' => null, 'sync' => 'failed', 'delivery_state' => 'failed', 'reason' => 'unknown_response', 'successful_sync' => false];
        $request = $result instanceof AgtInvoiceStatusResult ? $result->requestBody : $submission->request_body;
        if (! hash_equals(hash('sha256', $request), $result->requestBodySha256)
            || ($result->responseBody !== null && ! hash_equals(hash('sha256', $result->responseBody), $result->responseBodySha256 ?? ''))) {
            return [...$state, 'classification' => 'conflicting', 'knowledge' => 'conflicting_evidence', 'reason' => 'evidence_conflict'];
        }
        if ($result->httpStatus === null || $result->httpStatus < 200 || $result->httpStatus >= 300) {
            return [...$state, 'reason' => 'sync_failed'];
        }
        $response = json_decode($result->responseBody ?? '', true);
        $responseObject = json_decode($result->responseBody ?? '');
        if (! is_array($response) || array_is_list($response) || $submission->schema_version !== '2.0') {
            return $state;
        }
        if (self::hasDuplicateMembers($result->responseBody ?? '')) {
            return [...$state, 'classification' => 'conflicting', 'knowledge' => 'conflicting_evidence', 'reason' => 'evidence_conflict'];
        }
        foreach (['requestErrorList', 'errorEntry'] as $field) {
            if (property_exists($responseObject, $field) && (! is_array($responseObject->{$field}) || ! array_is_list($responseObject->{$field}))) {
                return $state;
            }
        }
        if ($result instanceof AgtRegistrationResult) {
            if ($result->accepted && $result->requestId !== null && ($response['requestID'] ?? null) === $result->requestId
                && $result->errorCodes === [] && hash_equals($submission->request_body_sha256, $result->requestBodySha256)) {
                return [...$state, 'classification' => 'partial', 'knowledge' => 'never_known', 'sync' => 'idle', 'delivery_state' => 'acknowledged', 'reason' => 'delivery_acknowledged'];
            }

            return [...$state, 'reason' => 'request_failed'];
        }
        $payload = json_decode($request, true);
        $frozen = json_decode($submission->request_body, true);
        if (! hash_equals(hash('sha256', $submission->request_body), $submission->request_body_sha256)
            || ! is_array($frozen) || ($frozen['schemaVersion'] ?? null) !== $submission->schema_version
            || self::hasDuplicateMembers($submission->request_body) || self::hasDuplicateMembers($request)
            || ! is_string($frozen['taxRegistrationNumber'] ?? null) || $frozen['taxRegistrationNumber'] === ''
            || ($frozen['numberOfEntries'] ?? null) !== 1 || ! is_array($frozen['documents'] ?? null)
            || ! array_is_list($frozen['documents']) || count($frozen['documents']) !== 1
            || ($frozen['documents'][0]['documentNo'] ?? null) !== $submission->fiscalDocument->document_no
            || ! is_array($payload) || ($payload['requestID'] ?? null) !== $submission->request_id
            || ($payload['schemaVersion'] ?? null) !== $submission->schema_version
            || ($payload['taxRegistrationNumber'] ?? null) !== $frozen['taxRegistrationNumber'] || blank($submission->request_id)) {
            return [...$state, 'classification' => 'conflicting', 'knowledge' => 'conflicting_evidence', 'reason' => 'evidence_conflict'];
        }
        $code = $response['resultCode'] ?? null;
        if ((! is_int($code) && ! is_string($code)) || ! $result->successful || $result->requestErrorCodes !== []
            || ! empty($response['requestErrorList']) || ! empty($response['errorEntry'])) {
            return $state;
        }
        $code = (string) $code;
        if ($code === '7') {
            return [...$state, 'sync' => 'pending', 'delivery_state' => 'acknowledged', 'reason' => 'refresh_pending'];
        }
        if (in_array($code, ['8', '9'], true)) {
            return [...$state, 'classification' => 'authoritative', 'knowledge' => 'known', 'reported_state' => $code === '8' ? 'processing' : 'processing_cancelled', 'sync' => $code === '8' ? 'pending' : 'idle', 'delivery_state' => 'acknowledged', 'reason' => $code === '8' ? 'processing_reported' : 'processing_cancelled', 'successful_sync' => true];
        }
        if (! in_array($code, ['0', '1', '2'], true) || ! is_array($response['documentStatusList'] ?? null) || ! array_is_list($response['documentStatusList'])) {
            return $state;
        }
        if (! is_array($responseObject->documentStatusList ?? null)) {
            return $state;
        }
        foreach ($responseObject->documentStatusList as $row) {
            if (! is_object($row) || ! is_string($row->documentNo ?? null) || ! is_string($row->documentStatus ?? null)
                || (property_exists($row, 'errorList') && (! is_array($row->errorList) || ! array_is_list($row->errorList)))) {
                return $state;
            }
        }
        $matches = array_values(array_filter($response['documentStatusList'], fn (mixed $row): bool => is_array($row) && ($row['documentNo'] ?? null) === $submission->fiscalDocument->document_no));
        if ($matches === []) {
            return $state;
        }
        $canonical = json_encode($matches[0], JSON_THROW_ON_ERROR);
        foreach ($matches as $row) {
            if (json_encode($row, JSON_THROW_ON_ERROR) !== $canonical) {
                return [...$state, 'classification' => 'conflicting', 'knowledge' => 'conflicting_evidence', 'reason' => 'evidence_conflict'];
            }
        }
        $status = $matches[0]['documentStatus'] ?? null;
        if (! in_array($status, ['V', 'I'], true)) {
            return $state;
        }
        if (($status === 'V' && (! empty($matches[0]['errorList']) || $code === '2')) || ($status === 'I' && $code === '0')) {
            return [...$state, 'classification' => 'conflicting', 'knowledge' => 'conflicting_evidence', 'reason' => 'evidence_conflict'];
        }

        return [...$state, 'classification' => 'authoritative', 'knowledge' => 'known', 'reported_state' => $status === 'V' ? 'valid' : 'invalid', 'sync' => 'idle', 'delivery_state' => 'acknowledged', 'reason' => $status === 'V' ? 'validation_reported' : 'invalidity_reported', 'successful_sync' => true];
    }

    /** Detect ambiguous object members before PHP's last-member-wins interpretation can grant authority. */
    private static function hasDuplicateMembers(string $json): bool
    {
        if (preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\\[\\]:,]/s', $json, $matches) === false) {
            return true;
        }
        $objects = [];
        foreach ($matches[0] as $index => $token) {
            if ($token === '{' || $token === '[') {
                $objects[] = [];
            } elseif ($token === '}' || $token === ']') {
                array_pop($objects);
            } elseif (str_starts_with($token, '"') && ($matches[0][$index + 1] ?? null) === ':') {
                $key = json_decode($token, true);
                $depth = array_key_last($objects);
                if (! is_string($key) || $depth === null || isset($objects[$depth][$key])) {
                    return true;
                }
                $objects[$depth][$key] = true;
            }
        }

        return false;
    }
}
