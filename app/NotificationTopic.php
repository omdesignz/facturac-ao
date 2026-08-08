<?php

namespace App;

/**
 * The things the app tells a user about.
 *
 * Every notification names its topic, and the topic is what a user switches on
 * or off. Keeping the list here rather than deriving it from class names means
 * a notification can be renamed or replaced without silently resetting the
 * choices people already made.
 */
enum NotificationTopic: string
{
    case DocumentAccepted = 'document_accepted';
    case DocumentRejected = 'document_rejected';
    case InvoiceOverdue = 'invoice_overdue';
    case QuoteAccepted = 'quote_accepted';
    case QuoteExpiring = 'quote_expiring';
    case RecurringGenerated = 'recurring_generated';
    case StockLow = 'stock_low';
    case CreditLimitExceeded = 'credit_limit_exceeded';
    case ImportCompleted = 'import_completed';
    case BillingActivity = 'billing_activity';
    case SupportAccess = 'support_access';

    public function label(): string
    {
        return match ($this) {
            self::DocumentAccepted => 'Documentos aceites pela AGT',
            self::DocumentRejected => 'Documentos recusados pela AGT',
            self::InvoiceOverdue => 'Facturas vencidas',
            self::QuoteAccepted => 'Orçamentos aceites',
            self::QuoteExpiring => 'Orçamentos a expirar',
            self::RecurringGenerated => 'Avenças geradas',
            self::StockLow => 'Existências no mínimo',
            self::CreditLimitExceeded => 'Clientes acima do limite',
            self::ImportCompleted => 'Importações concluídas',
            self::BillingActivity => 'Plano e pagamentos',
            self::SupportAccess => 'Acessos do suporte',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DocumentAccepted => 'Quando a AGT confirma um documento comunicado.',
            self::DocumentRejected => 'Quando a AGT recusa um documento e é preciso corrigi-lo.',
            self::InvoiceOverdue => 'No dia seguinte ao vencimento de uma factura por pagar.',
            self::QuoteAccepted => 'Quando um orçamento passa a aceite e pode virar factura.',
            self::QuoteExpiring => 'Três dias antes de um orçamento perder a validade.',
            self::RecurringGenerated => 'Quando uma avença cria o documento do período.',
            self::StockLow => 'Quando um artigo desce ao nível de reposição.',
            self::CreditLimitExceeded => 'Quando a dívida de um cliente passa o limite acordado.',
            self::ImportCompleted => 'Quando um ficheiro importado acaba de ser processado.',
            self::BillingActivity => 'Activação do plano, referências e pagamentos.',
            self::SupportAccess => 'Sempre que o suporte entra na sua conta.',
        };
    }

    /**
     * Whether a user may switch this topic off.
     *
     * Support access cannot be silenced: knowing when someone else opened your
     * account is not a preference, it is the reason the record exists.
     */
    public function isMandatory(): bool
    {
        return $this === self::SupportAccess;
    }

    /**
     * Where a topic goes when the user has not said otherwise.
     *
     * Everything lands in the app; only what needs acting on outside the app
     * also lands in an inbox, so email stays worth reading.
     *
     * @return array{database: bool, mail: bool}
     */
    public function defaults(): array
    {
        return [
            'database' => true,
            'mail' => match ($this) {
                self::DocumentRejected,
                self::InvoiceOverdue,
                self::CreditLimitExceeded,
                self::ImportCompleted,
                self::BillingActivity,
                self::SupportAccess => true,
                default => false,
            },
        ];
    }

    /**
     * How urgent the topic reads in a list.
     *
     * @return 'critical'|'warning'|'success'|'neutral'
     */
    public function tone(): string
    {
        return match ($this) {
            self::DocumentRejected, self::CreditLimitExceeded => 'critical',
            self::InvoiceOverdue, self::QuoteExpiring, self::StockLow => 'warning',
            self::DocumentAccepted, self::QuoteAccepted => 'success',
            default => 'neutral',
        };
    }

    /**
     * The topics a user can configure, in the order the settings page shows
     * them: what needs acting on first.
     *
     * @return list<self>
     */
    public static function configurable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $topic): bool => ! $topic->isMandatory(),
        ));
    }
}
