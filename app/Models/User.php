<?php

namespace App\Models;

use App\NotificationTopic;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property string $attribution_id
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property int|null $current_workspace_id
 * @property int $work_session_minutes
 * @property array<string, array{database?: bool, mail?: bool}>|null $notification_preferences
 * @property bool $is_support_staff
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'work_session_minutes', 'notification_preferences'])]
#[Hidden([
    'password',
    'attribution_id',
    'remember_token',
    'two_factor_secret',
    'two_factor_recovery_codes',
])]
class User extends Authenticatable implements MustVerifyEmailContract, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, MustVerifyEmail, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    public $usesUniqueIds = true;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['attribution_id'];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    public function setUniqueIds(): void
    {
        $this->attribution_id = $this->newUniqueId();
    }

    protected static function booted(): void
    {
        static::updating(function (User $user): void {
            if ($user->isDirty('attribution_id')) {
                throw new \DomainException('Immutable attribution identity.');
            }
        });
    }

    /** @return BelongsTo<Workspace, $this> */
    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    /**
     * Whether this user wants to hear about a topic on a given channel.
     *
     * Falls back to the topic's own default, so a topic added after someone
     * saved their choices starts where it was designed to rather than off.
     */
    public function wantsNotification(NotificationTopic $topic, string $channel): bool
    {
        if ($topic->isMandatory()) {
            return true;
        }

        $saved = $this->notification_preferences[$topic->value][$channel] ?? null;

        return is_bool($saved) ? $saved : ($topic->defaults()[$channel] ?? false);
    }

    /**
     * The channels a notification on this topic should actually use.
     *
     * @return list<string>
     */
    public function notificationChannels(NotificationTopic $topic): array
    {
        return array_values(array_filter(
            ['database', 'mail'],
            fn (string $channel): bool => $this->wantsNotification($topic, $channel),
        ));
    }

    /** @return HasMany<WorkspaceMembership, $this> */
    public function workspaceMemberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    /** @return BelongsToMany<Workspace, $this> */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_memberships')
            ->withPivot(['role', 'is_active', 'joined_at'])
            ->withTimestamps();
    }

    /** @return HasMany<SocialIdentity, $this> */
    public function socialIdentities(): HasMany
    {
        return $this->hasMany(SocialIdentity::class);
    }

    /** @return HasMany<DataImport, $this> */
    public function dataImports(): HasMany
    {
        return $this->hasMany(DataImport::class, 'uploaded_by_user_id');
    }

    /** Troubleshooting sessions this user has opened on other accounts. */
    /** @return HasMany<ImpersonationSession, $this> */
    public function impersonationsPerformed(): HasMany
    {
        return $this->hasMany(ImpersonationSession::class, 'impersonator_id');
    }

    /** Troubleshooting sessions support has opened on this user's account. */
    /** @return HasMany<ImpersonationSession, $this> */
    public function impersonationsReceived(): HasMany
    {
        return $this->hasMany(ImpersonationSession::class, 'subject_id');
    }

    public function isSupportStaff(): bool
    {
        return $this->is_support_staff;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_support_staff' => 'boolean',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'notification_preferences' => 'array',
        ];
    }
}
