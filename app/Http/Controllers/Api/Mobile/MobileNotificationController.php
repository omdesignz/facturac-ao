<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Requests\MobileListRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\NotificationFeed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The web's bell, read from the phone: the same rows, for the company the person
 * is working in, plus anything about the account itself.
 */
class MobileNotificationController
{
    public function __construct(private readonly NotificationFeed $feed) {}

    public function index(MobileListRequest $request): JsonResponse
    {
        $rows = $this->rows($request)
            ->when($request->boolean('only_unread'), fn (Builder $query) => $query->whereNull('read_at'))
            ->paginate(25);

        return $request->pageOf($rows, array_values($rows->getCollection()
            ->map(fn (DatabaseNotification $row): array => $this->present($row))
            ->all()));
    }

    public function unread(Request $request): JsonResponse
    {
        return $this->count($request);
    }

    public function read(Request $request, string $notificationId): JsonResponse
    {
        $row = $this->rows($request)->whereKey($notificationId)->firstOrFail();
        $row->markAsRead();

        return response()->json(['data' => $this->present($row)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->rows($request)->whereNull('read_at')->update(['read_at' => now()]);

        return $this->count($request);
    }

    private function count(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => $this->rows($request)->whereNull('read_at')->count()]]);
    }

    /**
     * @return Builder<DatabaseNotification>
     */
    private function rows(Request $request): Builder
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $request->attributes->get('currentWorkspace');

        return $this->feed->forUser($user, $workspace instanceof Workspace ? $workspace->id : null);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DatabaseNotification $row): array
    {
        $presented = $this->feed->present($row);
        /** @var array<string, mixed> $data */
        $data = $row->data;

        return [
            'id' => $presented['id'],
            'topic_label' => $presented['topic_label'],
            'title' => $presented['title'],
            'body' => $presented['body'],
            'read' => $presented['read'],
            'created_at' => $presented['created_at'],
            // What the app can open itself; anything else can only go to the web.
            'target' => $data['target'] ?? null,
            'web_url' => $presented['url'],
        ];
    }
}
