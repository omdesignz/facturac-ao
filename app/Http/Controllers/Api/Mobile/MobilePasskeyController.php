<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Actions\ConfirmMobileIssuer;
use App\Actions\MobilePasskeyChallenges;
use App\Actions\SignInMobileDevice;
use App\Exceptions\MobileSignInRefused;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passkeys from the phone: signing in with one, adding one, and the challenge
 * that confirms the person at the moment of issuing.
 *
 * The ceremonies are the web's, through the same package and the same relying
 * party, so a passkey made on the phone works on the web and the other way
 * round. Only where the challenge waits differs; see MobilePasskeyChallenges.
 */
class MobilePasskeyController
{
    public function __construct(private readonly MobilePasskeyChallenges $challenges) {}

    public function signInOptions(): JsonResponse
    {
        return response()->json(['data' => $this->challenges->verification(MobilePasskeyChallenges::SIGN_IN)]);
    }

    public function signIn(Request $request, VerifyPasskey $verify, ConfirmMobileIssuer $issuer, SignInMobileDevice $signIn): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'string', 'max:64'],
            'credential' => ['required', 'array'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        try {
            $passkey = $verify(
                $issuer->credential($validated['credential']),
                $this->challenges->takeVerification(MobilePasskeyChallenges::SIGN_IN, $validated['challenge_id']),
            );

            if (! Passkeys::allowsLogin($request, $passkey)) {
                throw InvalidPasskeyException::make('Unable to sign in with this account.');
            }

            /** @var User $user */
            $user = $passkey->user;
            $token = $signIn->handlePasskey($user, $validated['device_name']);
        } catch (InvalidPasskeyException) {
            return $this->refusal('PASSKEY_REFUSED', 'Não foi possível entrar com esta chave de acesso. Tente outra vez ou use a palavra-passe.', Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (MobileSignInRefused $refusal) {
            return $this->refusal($refusal->errorCode, $refusal->getMessage(), $refusal->status);
        }

        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ]], Response::HTTP_CREATED);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->user($request)->passkeys()->latest()->get()
            ->map(fn (Passkey $passkey): array => [
                'id' => $passkey->getKey(),
                'name' => $passkey->name,
                'created_at' => $passkey->created_at?->toIso8601String(),
                'last_used_at' => $passkey->last_used_at?->toIso8601String(),
            ])
            ->all()]);
    }

    /**
     * Adding a passkey asks for the password first, as the web does.
     */
    public function registrationOptions(Request $request): JsonResponse
    {
        $validated = $request->validate(
            ['password' => ['required', 'string', 'max:255']],
            ['password.required' => 'Confirme a palavra-passe para adicionar uma chave de acesso.'],
        );
        $user = $this->user($request);

        if (! Hash::check($validated['password'], $user->password)) {
            return $this->refusal('PASSWORD_INCORRECT', 'A palavra-passe não está certa.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => $this->challenges->registration($user)]);
    }

    public function store(Request $request, StorePasskey $store, ConfirmMobileIssuer $issuer): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'string', 'max:64'],
            'credential' => ['required', 'array'],
            'name' => ['required', 'string', 'max:100'],
        ]);
        $user = $this->user($request);

        try {
            $passkey = $store(
                $user,
                $validated['name'],
                $issuer->credential($validated['credential']),
                $this->challenges->takeRegistration($validated['challenge_id'], $user),
            );
        } catch (InvalidPasskeyException) {
            return $this->refusal('PASSKEY_REFUSED', 'Não foi possível guardar esta chave de acesso. Tente outra vez.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        activity('security')
            ->event('passkey-added')
            ->causedBy($user)
            ->withProperties(['passkey_id' => $passkey->getKey(), 'from' => 'mobile'])
            ->log('passkey added from the phone');

        return response()->json(['data' => [
            'id' => $passkey->getKey(),
            'name' => $passkey->name,
            'created_at' => $passkey->created_at?->toIso8601String(),
            'last_used_at' => null,
        ]], Response::HTTP_CREATED);
    }

    /**
     * The challenge a passkey answers in place of the password when issuing.
     */
    public function confirmationOptions(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if (! $user->passkeys()->exists()) {
            return $this->refusal('NO_PASSKEY', 'Esta conta ainda não tem chaves de acesso.', Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => $this->challenges->verification(MobilePasskeyChallenges::CONFIRM, $user)]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function refusal(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
