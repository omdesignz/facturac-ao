<?php

namespace App\Fiscal;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity;

final class RequiredAudit
{
    /** @param Closure(): ?Activity $write */
    public static function record(Closure $write): void
    {
        if (config('activitylog.buffer.enabled', false)) {
            throw new \RuntimeException('Required audit cannot be deferred.');
        }

        $activity = $write();
        if (! $activity instanceof Model || ! $activity->exists) {
            throw new \RuntimeException('Required audit was not persisted.');
        }
    }
}
