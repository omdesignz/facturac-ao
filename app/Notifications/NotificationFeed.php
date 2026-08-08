<?php

namespace App\Notifications;

use App\Models\User;
use App\NotificationTopic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Reads what has already been sent to a user.
 *
 * Scoped to the workspace they are in, plus anything about the account itself.
 * A user who keeps books for two companies should not be shown one company's
 * rejected documents while working in the other — and should still be told when
 * support opened their account, which belongs to neither.
 */
class NotificationFeed
{
    /**
     * @return Builder<DatabaseNotification>
     */
    public function forUser(User $user, ?int $workspaceId): Builder
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->when(
                $workspaceId !== null,
                fn (Builder $query) => $query->where(function (Builder $nested) use ($workspaceId): void {
                    $nested->whereNull('data->workspace_id')
                        ->orWhere('data->workspace_id', $workspaceId);
                }),
            )
            ->latest('created_at')
            ->latest('id');
    }

    public function unreadCount(User $user, ?int $workspaceId): int
    {
        return $this->forUser($user, $workspaceId)->whereNull('read_at')->count();
    }

    /**
     * The most recent notifications, ready to render.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(User $user, ?int $workspaceId, int $limit = 8): array
    {
        return array_values($this->forUser($user, $workspaceId)
            ->limit($limit)
            ->get()
            ->map(fn (DatabaseNotification $row): array => $this->present($row))
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function present(DatabaseNotification $row): array
    {
        /** @var array<string, mixed> $data */
        $data = $row->data;

        $topic = NotificationTopic::tryFrom((string) ($data['topic'] ?? ''));

        return [
            'id' => (string) $row->id,
            'topic' => $topic?->value,
            'topic_label' => $topic?->label(),
            // The words come from the row rather than being rebuilt here: a
            // notification about a document deleted since must still read the
            // way it did when it was sent.
            'title' => (string) ($data['title'] ?? 'Notificação'),
            'body' => (string) ($data['body'] ?? ''),
            'url' => $data['url'] ?? null,
            'tone' => (string) ($data['tone'] ?? 'neutral'),
            'read' => $row->read_at !== null,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
