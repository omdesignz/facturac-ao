<?php

use App\Notifications\DocumentAcceptedByAgt;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Clears the backlog left from when every acceptance by the AGT was announced.
     *
     * The notices stay in each person's list; they only stop counting as
     * unread, so the bell shows what still needs reading.
     */
    public function up(): void
    {
        DB::table('notifications')
            ->where('type', DocumentAcceptedByAgt::class)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Which notices were unread before is not recorded, so there is nothing to restore.
     */
    public function down(): void {}
};
