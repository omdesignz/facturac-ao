<?php

namespace App\Notifications;

use App\Models\DataImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DataImportCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $importPublicId,
        public readonly string $typeLabel,
        public readonly int $importedRows,
        public readonly int $createdRows,
        public readonly int $updatedRows,
    ) {
        $this->afterCommit();
    }

    public static function fromModel(DataImport $dataImport): self
    {
        return new self(
            importPublicId: $dataImport->public_id,
            typeLabel: $dataImport->type->label(),
            importedRows: $dataImport->imported_rows,
            createdRows: $dataImport->created_rows,
            updatedRows: $dataImport->updated_rows,
        );
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Importação concluída no VAP Fatura')
            ->greeting('Importação concluída')
            ->line("Foram processados {$this->importedRows} registos de {$this->typeLabel}.")
            ->line("Novos: {$this->createdRows} · Actualizados: {$this->updatedRows}.")
            ->action('Ver importação', route('imports.index', ['import' => $this->importPublicId]))
            ->line('O ficheiro original foi eliminado após o processamento; o hash e o histórico de validação permanecem auditáveis.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'data_import_completed',
            'import_public_id' => $this->importPublicId,
            'type_label' => $this->typeLabel,
            'imported_rows' => $this->importedRows,
            'created_rows' => $this->createdRows,
            'updated_rows' => $this->updatedRows,
        ];
    }
}
