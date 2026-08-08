<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Workspace;
use App\NotificationTopic;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * The shape every in-app notification shares.
 *
 * Two things live here because getting them wrong is invisible until someone
 * complains. First, the channels: a topic the user switched off must not
 * arrive, and asking each notification to remember that is how one of them
 * forgets. Second, the envelope: the row that reaches the bell carries its own
 * words and its own link, so the browser never has to know what a
 * `document_rejected` is in order to render one.
 */
abstract class WorkspaceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        // The event that caused this is usually mid-transaction; queueing
        // before it commits can read a row that never lands.
        $this->afterCommit();
    }

    abstract public function topic(): NotificationTopic;

    abstract public function title(): string;

    abstract public function body(): string;

    /** Where the notification takes you when opened, or null when nowhere useful. */
    abstract public function url(): ?string;

    /**
     * The workspace this concerns, or null for something about the account.
     *
     * The bell filters on it: a user in two companies should not see one
     * company's overdue invoices while working in the other.
     */
    public function workspaceId(): ?int
    {
        return null;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User
            ? $notifiable->notificationChannels($this->topic())
            : ['database'];
    }

    /**
     * What makes this notification the same as one already sent.
     *
     * A nightly sweep would otherwise report the same overdue invoice every
     * morning until it is paid, which trains people to ignore the bell.
     */
    public function dedupeKey(): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'topic' => $this->topic()->value,
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
            'tone' => $this->topic()->tone(),
            'workspace_id' => $this->workspaceId(),
            'dedupe_key' => $this->dedupeKey(),
        ];
    }

    /**
     * Sends this to everyone who works in the company it concerns.
     *
     * Each recipient's own preferences still decide the channels, so one
     * person silencing a topic does not silence it for their colleagues.
     */
    public function sendToWorkspace(Workspace $workspace): void
    {
        $recipients = $workspace->users()->get();

        if ($recipients->isNotEmpty()) {
            NotificationFacade::send($recipients, $this);
        }
    }
}
