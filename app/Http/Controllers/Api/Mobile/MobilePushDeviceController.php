<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Requests\UpdateMobilePushDeviceRequest;
use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Http\Response;

/**
 * Where Apple can reach the phone this request comes from.
 *
 * Kept per sign-in: the phone sends its token after every launch, because
 * Apple changes it now and then, and the row goes when the sign-in does.
 */
class MobilePushDeviceController
{
    public function update(UpdateMobilePushDeviceRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $accessTokenId = $user->currentAccessToken()->getKey();

        // A token Apple gave this phone under an earlier sign-in, maybe
        // someone else's, is moved here: one phone hears one person.
        PushDevice::query()
            ->where('personal_access_token_id', $accessTokenId)
            ->where('token', '!=', $request->validated('token'))
            ->delete();

        PushDevice::query()->updateOrCreate(
            ['token' => $request->validated('token')],
            [
                'user_id' => $user->id,
                'personal_access_token_id' => $accessTokenId,
                'environment' => $request->validated('environment'),
            ],
        );

        return response()->noContent();
    }
}
