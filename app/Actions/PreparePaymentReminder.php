<?php

namespace App\Actions;

use App\Analytics\ReceivablesQuery;
use App\Models\Customer;
use App\Models\FiscalDocument;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/**
 * Writes the reminder for a customer who is late, without sending anything.
 *
 * Preparing is separate from sending on purpose: the words go out under the
 * company's name, to someone it wants to keep as a customer, so a person reads
 * and may change them first. Nothing here touches the customer.
 */
class PreparePaymentReminder
{
    public const string EVENT = 'payment-reminder-sent';

    public function __construct(private ReceivablesQuery $receivables) {}

    /**
     * @return array{customer_name: string, to: string|null, subject: string, body: string, documents: list<array{document_no: string, due_date: string, days_past_due: int, outstanding_minor: int}>, total_minor: int, currency_code: string, last_sent_at: string|null}
     */
    public function execute(Customer $customer): array
    {
        $legalEntity = $customer->legalEntity;
        $today = CarbonImmutable::today();

        $documents = $this->receivables
            ->withBalances(ReceivablesQuery::issued(
                $customer->fiscalDocuments()
                    ->where('currency_code', $legalEntity->currency_code)
                    ->getQuery(),
            ))
            ->whereIn('document_type', ReceivablesQuery::owableTypes())
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today->toDateString())
            ->orderBy('due_date')
            ->get()
            ->filter(fn (FiscalDocument $document): bool => $this->receivables->outstandingMinor($document) > 0)
            ->values();

        $rows = $documents
            ->map(fn (FiscalDocument $document): array => [
                'document_no' => (string) $document->document_no,
                'due_date' => $document->due_date->toDateString(),
                'days_past_due' => max(1, (int) floor($document->due_date->startOfDay()->diffInDays($today))),
                'outstanding_minor' => $this->receivables->outstandingMinor($document),
            ])
            ->all();

        $total = array_sum(array_column($rows, 'outstanding_minor'));
        $company = $legalEntity->trade_name ?? $legalEntity->legal_name;
        $currency = $legalEntity->currency_code === 'AOA' ? 'Kz' : $legalEntity->currency_code;

        $email = is_string($customer->email) && trim($customer->email) !== '' ? trim($customer->email) : null;

        return [
            'customer_name' => $customer->name,
            'to' => $email,
            'subject' => "Lembrete de pagamento · {$company}",
            'body' => $rows === [] ? '' : $this->body($customer->name, $company, $rows, $total, $currency),
            'documents' => $rows,
            'total_minor' => $total,
            'currency_code' => $legalEntity->currency_code,
            'last_sent_at' => Activity::query()
                ->where('subject_type', $customer->getMorphClass())
                ->where('subject_id', $customer->getKey())
                ->where('event', self::EVENT)
                ->latest('id')
                ->first()?->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<array{document_no: string, due_date: string, days_past_due: int, outstanding_minor: int}>  $rows
     */
    private function body(string $customer, string $company, array $rows, int $total, string $currency): string
    {
        $lines = array_map(
            fn (array $row): string => sprintf(
                '- %s: %s %s, vencido há %s',
                $row['document_no'],
                $this->amount($row['outstanding_minor']),
                $currency,
                $row['days_past_due'] === 1 ? '1 dia' : "{$row['days_past_due']} dias",
            ),
            $rows,
        );

        return implode("\n", [
            'Bom dia,',
            '',
            "Na conta corrente de {$customer} constam em aberto os documentos abaixo, já vencidos:",
            '',
            ...$lines,
            '',
            "Total em atraso: {$this->amount($total)} {$currency}.",
            '',
            'Agradecemos que regularize o pagamento. Se já o fez, ignore esta mensagem e aceite os nossos agradecimentos.',
            '',
            'Com os melhores cumprimentos,',
            $company,
        ]);
    }

    private function amount(int $minor): string
    {
        return number_format($minor / 100, 2, ',', ' ');
    }
}
