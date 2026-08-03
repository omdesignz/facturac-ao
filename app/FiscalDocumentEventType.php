<?php

namespace App;

enum FiscalDocumentEventType: string
{
    case Issued = 'issued';
    case SubmissionQueued = 'submission_queued';
    case SubmissionAccepted = 'submission_accepted';
    case Processing = 'processing';
    case Validated = 'validated';
    case Invalidated = 'invalidated';
    case SubmissionRejected = 'submission_rejected';
    case DeliveryFailed = 'delivery_failed';
    case ProcessingCancelled = 'processing_cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Documento emitido',
            self::SubmissionQueued => 'Entrega colocada na fila',
            self::SubmissionAccepted => 'Pedido recebido pela AGT',
            self::Processing => 'Validação em curso',
            self::Validated => 'Documento validado',
            self::Invalidated => 'Documento considerado inválido',
            self::SubmissionRejected => 'Pedido rejeitado',
            self::DeliveryFailed => 'Entrega requer intervenção',
            self::ProcessingCancelled => 'Processamento cancelado',
        };
    }
}
