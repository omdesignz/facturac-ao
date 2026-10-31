<?php

namespace App\Analytics;

/**
 * What the owner has to decide today, one verb per card, for the phone app.
 *
 * The web works the same cards out in resources/js/lib/today-decisions.ts.
 * tests/Fixtures/today-decisions-cases.json is the contract between the two:
 * both test suites read it, so a change to one fails until the other follows.
 *
 * @phpstan-type Decision array{key: string, kicker: string, headline: string, detail: string|null, action: string, kind: string, secondary_label: string, document_public_id: string|null, customer: array{public_id: string, name: string}|null, amount_minor: int|null}
 */
class TodayDecisions
{
    /**
     * @param  array<string, mixed>|null  $focus
     * @param  array{customers: list<array{customer_public_id: string|null, name: string, overdue_minor: int, oldest_days_past_due: int}>}|null  $collections
     * @param  array{name: string, quantity: int|string, unit: string}|null  $lowStock
     * @return list<Decision>
     */
    public function for(?array $focus, ?array $collections, ?array $lowStock): array
    {
        return array_values(array_filter([
            $this->fiscal($focus),
            $this->collection($collections),
            $this->stock($lowStock),
        ]));
    }

    /**
     * @param  array<string, mixed>|null  $focus
     * @return Decision|null
     */
    private function fiscal(?array $focus): ?array
    {
        if ($focus === null || ! in_array($focus['reason'], ['invalid', 'contingency'], true)) {
            return null;
        }

        $number = $focus['document_no'] ?? 'Um documento';

        return [
            'key' => 'fiscal',
            'kicker' => 'Fiscal',
            'headline' => $focus['reason'] === 'invalid'
                ? "{$number} foi devolvida pela AGT."
                : "{$number} está em contingência.",
            'detail' => $focus['agt_message'] ?? null,
            'action' => 'Corrigir',
            'kind' => 'link',
            'secondary_label' => 'Ver documentos',
            'document_public_id' => $focus['public_id'] ?? null,
            'customer' => null,
            'amount_minor' => null,
        ];
    }

    /**
     * @param  array{customers: list<array{customer_public_id: string|null, name: string, overdue_minor: int, oldest_days_past_due: int}>}|null  $collections
     * @return Decision|null
     */
    private function collection(?array $collections): ?array
    {
        $debtor = collect($collections['customers'] ?? [])->first(
            fn (array $customer): bool => is_string($customer['customer_public_id']) && $customer['overdue_minor'] > 0,
        );

        if ($debtor === null) {
            return null;
        }

        $days = (int) $debtor['oldest_days_past_due'];
        $prepared = 'Lembrete preparado. Nada é enviado até confirmar.';

        return [
            'key' => 'collection',
            'kicker' => 'Cobrança',
            'headline' => $debtor['name'].' deve',
            'detail' => $days > 0
                ? 'há '.$days.' '.($days === 1 ? 'dia' : 'dias').". {$prepared}"
                : $prepared,
            'action' => 'Rever e enviar',
            'kind' => 'remind',
            'secondary_label' => 'Mais tarde',
            'document_public_id' => null,
            'customer' => ['public_id' => $debtor['customer_public_id'], 'name' => $debtor['name']],
            'amount_minor' => $debtor['overdue_minor'],
        ];
    }

    /**
     * @param  array{name: string, quantity: int|string, unit: string}|null  $lowStock
     * @return Decision|null
     */
    private function stock(?array $lowStock): ?array
    {
        if ($lowStock === null) {
            return null;
        }

        return [
            'key' => 'stock',
            'kicker' => 'Stock',
            'headline' => "{$lowStock['name']}: restam {$lowStock['quantity']} {$lowStock['unit']}.",
            'detail' => null,
            'action' => 'Ver stock',
            'kind' => 'link',
            'secondary_label' => 'Mais tarde',
            'document_public_id' => null,
            'customer' => null,
            'amount_minor' => null,
        ];
    }
}
