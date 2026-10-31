<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Actions\SignInMobileDevice;
use App\Exceptions\MobileSignInRefused;
use App\Http\Requests\StoreMobileSessionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class MobileSessionController
{
    public function store(StoreMobileSessionRequest $request, SignInMobileDevice $signIn): JsonResponse
    {
        try {
            $token = $signIn->handle(
                $request->string('email')->toString(),
                $request->string('password')->toString(),
                $request->string('device_name')->toString(),
                $request->string('code')->toString(),
                $request->string('recovery_code')->toString(),
            );
        } catch (MobileSignInRefused $refusal) {
            return response()->json(
                ['error' => ['code' => $refusal->errorCode, 'message' => $refusal->getMessage()]],
                $refusal->status,
            );
        }

        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ]], Response::HTTP_CREATED);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()->getKey();

        return response()->json(['data' => $user->tokens()->latest('last_used_at')->get()
            ->map(fn (PersonalAccessToken $token): array => [
                'id' => $token->getKey(),
                'device_name' => $token->name,
                'current' => $token->getKey() === $currentTokenId,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ])
            ->all()]);
    }

    public function destroyCurrent(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->revoke($user, $user->currentAccessToken());

        return response()->noContent();
    }

    public function destroy(Request $request, int $tokenId): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->revoke($user, $user->tokens()->whereKey($tokenId)->firstOrFail());

        return response()->noContent();
    }

    private function revoke(User $user, PersonalAccessToken $token): void
    {
        $token->delete();

        activity('security')
            ->event('mobile-session-revoked')
            ->causedBy($user)
            ->withProperties([
                'workspace_id' => $user->current_workspace_id,
                'token_id' => $token->getKey(),
            ])
            ->log('mobile device signed out');
    }
}
