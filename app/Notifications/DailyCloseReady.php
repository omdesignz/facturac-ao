<?php

namespace App\Notifications;

use App\Models\User;
use App\NotificationTopic;

/**
 * The evening summary for those who run the company: sold, received, still owed.
 */
class DailyCloseReady extends WorkspaceNotification
{
    /**
     * @param  array{date: string, currency_code: string, billed_minor: int, issued_count: int, received_minor: int, outstanding_minor: int, overdue_minor: int, overdue_count: int}  $summary
     */
    public function __construct(
        public readonly array $summary,
        public readonly string $legalEntityPublicId,
        public readonly int $workspace,
    ) {
        parent::__construct();
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::DailyClose;
    }

    public function title(): string
    {
        return 'Fecho do dia';
    }

    public function body(): string
    {
        $sold = $this->summary['issued_count'] === 0
            ? 'Sem vendas hoje'
            : 'Vendeu '.$this->money($this->summary['billed_minor']);

        return $sold
            .' · recebeu '.$this->money($this->summary['received_minor'])
            .' · faltam receber '.$this->money($this->summary['outstanding_minor'])
            .($this->summary['overdue_minor'] > 0 ? ', '.$this->money($this->summary['overdue_minor']).' em atraso.' : '.');
    }

    public function url(): ?string
    {
        return route('dashboard');
    }

    public function workspaceId(): ?int
    {
        return $this->workspace;
    }

    public function dedupeKey(): ?string
    {
        return "daily_close:{$this->legalEntityPublicId}:{$this->summary['date']}";
    }

    /** The figures travel with the push, so a tap can show them before the app loads anything. */
    public function toPush(User $notifiable): array
    {
        $push = parent::toPush($notifiable);

        // One figure per line: the banner shows the first two (sold, received),
        // the Lock Screen all three.
        $push['aps']['alert']['body'] = implode("\n", [
            'Vendas: '.$this->money($this->summary['billed_minor']).$this->documents(),
            'Recebido: '.$this->money($this->summary['received_minor']),
            'Por receber: '.$this->money($this->summary['outstanding_minor'])
                .($this->summary['overdue_minor'] > 0 ? ' ('.$this->money($this->summary['overdue_minor']).' em atraso)' : ''),
        ]);

        return [...$push, 'daily_close' => $this->summary];
    }

    private function documents(): string
    {
        return match ($this->summary['issued_count']) {
            0 => '',
            1 => ' · 1 documento',
            default => " · {$this->summary['issued_count']} documentos",
        };
    }

    private function money(int $minor): string
    {
        $whole = number_format(intdiv(abs($minor), 100), 0, '', ' ');
        $cents = abs($minor) % 100;
        $amount = $cents === 0 ? $whole : $whole.','.str_pad((string) $cents, 2, '0', STR_PAD_LEFT);

        return ($minor < 0 ? '-' : '').$amount.' '.($this->summary['currency_code'] === 'AOA' ? 'Kz' : $this->summary['currency_code']);
    }
}
