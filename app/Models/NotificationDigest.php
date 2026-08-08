<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A record that the daily sweep already said this.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $dedupe_key
 * @property CarbonInterface $last_sent_at
 * @property int $times_sent
 */
#[Fillable(['workspace_id', 'dedupe_key', 'last_sent_at', 'times_sent'])]
class NotificationDigest extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_sent_at' => 'datetime',
            'times_sent' => 'integer',
        ];
    }
}
