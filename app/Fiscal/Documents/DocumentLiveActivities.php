<?php

namespace App\Fiscal\Documents;

use App\Models\DocumentLiveActivity;
use App\Models\FiscalDocument;
use App\Notifications\ApnsClient;
use App\Notifications\PushDelivery;
use Throwable;

/**
 * The lock-screen tracker for a document on its way to the AGT.
 *
 * Four stages, the ones a person cares about: signed, received by the AGT, and
 * then validated or returned. The last two end the activity; it stays on the
 * lock screen for an hour so the answer can be read, then goes.
 *
 * The state is the app's own shape, read by the widget as it stands, so its
 * keys are single words that need no conversion.
 */
final readonly class DocumentLiveActivities
{
    public const string SIGNED = 'signed';

    public const string RECEIVED = 'received';

    public const string VALIDATED = 'validated';

    public const string RETURNED = 'returned';

    public function __construct(private ApnsClient $apns) {}

    /**
     * @return array{stage: string, label: string}
     */
    public static function state(string $stage): array
    {
        return [
            'stage' => $stage,
            'label' => match ($stage) {
                self::RECEIVED => 'Recebida pela AGT',
                self::VALIDATED => 'Validada pela AGT',
                self::RETURNED => 'Devolvida pela AGT',
                default => 'Assinada',
            },
        ];
    }

    /**
     * Where a document stands, as the tracker shows it.
     */
    public static function stageOf(?string $workflowStatus): string
    {
        return match ($workflowStatus) {
            'valid' => self::VALIDATED,
            'invalid' => self::RETURNED,
            'received', 'processing' => self::RECEIVED,
            default => self::SIGNED,
        };
    }

    public function advance(FiscalDocument $document, string $stage): void
    {
        if (! ApnsClient::isConfigured()) {
            return;
        }

        $ends = in_array($stage, [self::VALIDATED, self::RETURNED], true);
        $payload = ['aps' => array_filter([
            'timestamp' => now()->getTimestamp(),
            'event' => $ends ? 'end' : 'update',
            'content-state' => self::state($stage),
            'dismissal-date' => $ends ? now()->addHour()->getTimestamp() : null,
        ], fn (mixed $value): bool => $value !== null)];

        DocumentLiveActivity::query()
            ->where('fiscal_document_id', $document->id)
            ->whereNull('ended_at')
            ->each(function (DocumentLiveActivity $activity) use ($payload, $ends): void {
                try {
                    $delivery = $this->apns->sendLiveActivity($activity->token, $activity->environment, $payload);
                } catch (Throwable $exception) {
                    report($exception);

                    return;
                }

                if ($delivery === PushDelivery::DeviceGone) {
                    $activity->delete();
                } elseif ($ends) {
                    $activity->update(['ended_at' => now()]);
                }
            });
    }
}
