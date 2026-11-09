<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Requests\UpdateMobilePushDeviceRequest;
use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Where the push provider can reach the phone this request comes from.
 *
 * Kept per sign-in: the phone sends its token after every launch, because
 * Providers rotate it now and then, and the row goes when the sign-in does.
 */
class MobilePushDeviceController
{
    public function update(UpdateMobilePushDeviceRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $accessTokenId = $user->currentAccessToken()->getKey();

        DB::transaction(function () use ($request, $user, $accessTokenId): void {
            $user->tokens()->whereKey($accessTokenId)->lockForUpdate()->firstOrFail();
            $provider = $request->validated('provider');
            $token = $request->validated('token');
            $hash = hash('sha256', $provider.':'.$token);

            PushDevice::query()->where('personal_access_token_id', $accessTokenId)
                ->where(fn (Builder $query) => $query->where('token_hash', '!=', $hash)->orWhere('provider', '!=', $provider)
                    ->orWhere(fn (Builder $legacy) => $legacy->whereNull('token_hash')->where('token', '!=', $token)))
                ->delete();

            // Legacy Apple registrations acquire their hash on the next launch.
            $legacy = PushDevice::query()->where('provider', 'apns')->whereNull('token_hash')
                ->where('token', $token)->first();
            $attributes = [
                'token' => $token,
                'provider' => $provider,
                'user_id' => $user->id,
                'personal_access_token_id' => $accessTokenId,
                'environment' => $request->validated('environment'),
                'bundle_id' => $request->validated('bundle_id'),
                'binding' => $request->validated('binding'),
            ];
            if ($provider === 'apns' && $legacy !== null) {
                $legacy->fill($attributes)->save();
            } else {
                PushDevice::query()->updateOrCreate(['token_hash' => $hash], $attributes);
            }
        });

        return response()->noContent();
    }
}
