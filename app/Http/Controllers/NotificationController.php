<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Notifications\NotificationFeed;
use App\NotificationTopic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The notifications centre.
 */
class NotificationController extends Controller
{
    public function __construct(private NotificationFeed $feed) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $workspaceId = $this->workspaceId($request);

        $filter = $request->string('estado')->toString();
        $topic = NotificationTopic::tryFrom($request->string('tema')->toString());

        $rows = $this->feed
            ->forUser($user, $workspaceId)
            ->when(
                $filter === 'nao-lidas',
                fn (Builder $query) => $query->whereNull('read_at'),
            )
            ->when(
                $topic instanceof NotificationTopic,
                fn (Builder $query) => $query->where('data->topic', $topic?->value),
            )
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Notifications/Index', [
            'notifications' => [
                'data' => array_values($rows->getCollection()
                    ->map(fn (DatabaseNotification $row): array => $this->feed->present($row))
                    ->all()),
                'links' => $rows->linkCollection()->all(),
                'total' => $rows->total(),
                'from' => $rows->firstItem(),
                'to' => $rows->lastItem(),
            ],
            'filters' => [
                'estado' => $filter === 'nao-lidas' ? 'nao-lidas' : 'todas',
                'tema' => $topic->value ?? '',
            ],
            'topics' => array_map(
                fn (NotificationTopic $value): array => [
                    'value' => $value->value,
                    'label' => $value->label(),
                ],
                NotificationTopic::cases(),
            ),
            'unread' => $this->feed->unreadCount($user, $workspaceId),
        ]);
    }

    /** Marks one notification read, then follows it wherever it points. */
    public function read(Request $request, string $notification): RedirectResponse
    {
        $user = $this->user($request);

        $row = $this->feed
            ->forUser($user, $this->workspaceId($request))
            ->whereKey($notification)
            ->firstOrFail();

        $row->markAsRead();

        /** @var array<string, mixed> $data */
        $data = $row->data;
        $url = $data['url'] ?? null;

        return is_string($url) && $url !== ''
            ? redirect()->to($url)
            : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->feed
            ->forUser($this->user($request), $this->workspaceId($request))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back()->with('success', 'Notificações marcadas como lidas.');
    }

    /**
     * Clears what has been read.
     *
     * Deliberately leaves the unread ones: "limpar" is for tidying what you
     * have already dealt with, and taking the rest with it would quietly
     * discard things nobody has seen.
     */
    public function destroyRead(Request $request): RedirectResponse
    {
        $this->feed
            ->forUser($this->user($request), $this->workspaceId($request))
            ->whereNotNull('read_at')
            ->delete();

        return back()->with('success', 'Notificações lidas removidas.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function workspaceId(Request $request): ?int
    {
        $workspace = $request->attributes->get('currentWorkspace');

        return $workspace instanceof Workspace ? $workspace->id : null;
    }
}
