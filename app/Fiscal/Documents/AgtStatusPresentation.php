<?php

namespace App\Fiscal\Documents;

use App\Models\FiscalDocument;
use Carbon\CarbonImmutable;

final class AgtStatusPresentation
{
    public static function label(FiscalDocument $document, CarbonImmutable $asOf): string
    {
        $status = CurrentAgtState::status($document, $asOf);
        if (! in_array($status->value, ['valid', 'invalid'], true)) {
            return $status->label();
        }
        $view = self::describe($document, $asOf);

        return ($status->value === 'valid' ? 'Validação reportada' : 'Invalidade reportada')
            .($view['freshness'] === 'stale' ? ' (observação antiga)' : '');
    }

    /** @return array<string, mixed> */
    public static function describe(FiscalDocument $document, CarbonImmutable $asOf): array
    {
        $document->loadMissing('submissions');
        $state = CurrentAgtState::projection($document->submissions->first(), $asOf);
        $observed = null;
        try {
            $observed = is_string($state['observed_at'] ?? null) ? CarbonImmutable::parse($state['observed_at']) : null;
        } catch (\Throwable) {
            $state = AgtObservationReducer::empty();
        }
        $freshness = $observed === null ? 'unverified' : ($observed->gt($asOf) || $observed->addMinutes(15)->lte($asOf) ? 'stale' : 'recent_observation');
        if ($observed !== null && $observed->gt($asOf)) {
            $state['classification'] = 'unknown';
            $state['knowledge'] = 'unknown_response';
            $state['reported_state'] = null;
            $state['reason'] = 'unknown_response';
        }
        $reason = match (true) {
            ($state['knowledge'] ?? null) === 'conflicting_evidence' => 'evidence_conflict',
            ($state['knowledge'] ?? null) === 'unknown_response' => 'unknown_response',
            ($state['knowledge'] ?? null) === 'legacy_unverified' => 'legacy_unverified',
            ($state['sync'] ?? null) === 'failed' => 'sync_failed',
            ($state['sync'] ?? null) === 'pending' => 'refresh_pending',
            $freshness === 'stale' => 'stale_observation',
            default => $state['reason'] ?? 'not_submitted',
        };
        [$message, $action] = match ($reason) {
            'delivery_pending' => ['A comunicação está pendente; a validação não está comprovada.', 'wait'],
            'delivery_acknowledged' => ['A comunicação foi recebida; a validação do documento não está comprovada.', 'wait'],
            'processing_reported' => ['A AGT reportou processamento na data indicada.', 'wait'],
            'validation_reported' => ['A AGT reportou validação na data indicada.', 'none'],
            'invalidity_reported' => ['A AGT reportou o documento inválido na data indicada.', 'review_document'],
            'processing_cancelled' => ['O processamento parou; isto não comprova cancelamento fiscal.', 'contact_support'],
            'request_failed' => ['A comunicação não pôde ser concluída.', 'review_status'],
            'refresh_pending' => ['Uma actualização do estado está pendente.', 'wait'],
            'sync_failed' => ['A sincronização falhou; o resultado anterior é histórico.', 'review_status'],
            'stale_observation' => ['O último resultado está fora da janela de observação recente.', 'review_status'],
            'unknown_response' => ['A resposta não permite comprovar um estado suportado.', 'contact_support'],
            'evidence_conflict' => ['A evidência disponível está em conflito.', 'contact_support'],
            'legacy_unverified' => ['O estado histórico não tem evidência suficiente.', 'review_status'],
            default => ['Não existe observação AGT comprovada.', $document->isMutable() ? 'none' : 'review_status'],
        };
        if ($document->isMutable()) {
            $state = AgtObservationReducer::empty();
            $freshness = 'not_applicable';
            $observed = null;
            $reason = 'not_submitted';
            $message = 'O rascunho não tem validação AGT.';
            $action = 'none';
        }

        return ['version' => 1, 'public_id' => $document->public_id, 'fiscal_state' => $document->status->value,
            'delivery_state' => $state['delivery_state'], 'reported_state' => $state['reported_state'],
            'knowledge' => $document->isMutable() ? 'not_applicable' : $state['knowledge'], 'sync' => $state['sync'], 'freshness' => $freshness,
            'observed_at' => $observed?->toIso8601String(), 'effective_at' => null,
            'last_successful_sync_at' => $state['last_successful_sync_at'], 'as_of' => $asOf->toIso8601String(),
            'provenance' => $state['classification'] === 'authoritative' ? 'agt_observation' : ($state['knowledge'] === 'legacy_unverified' ? 'legacy_unverified' : 'none'),
            'explanation' => ['code' => $reason, 'message' => $message, 'action' => $action, 'retryability' => $state['sync'] === 'pending' ? 'scheduled' : 'not_scheduled']];
    }
}
