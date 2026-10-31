<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A device was refused a token. The code is what the app branches on; the
 * message is what it can show the person as it stands.
 */
class MobileSignInRefused extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        return new self('INVALID_CREDENTIALS', 'O e-mail ou a palavra-passe não estão certos.', 422);
    }

    public static function emailNotVerified(): self
    {
        return new self('EMAIL_NOT_VERIFIED', 'Confirme o seu e-mail antes de entrar na app.', 403);
    }

    public static function twoFactorRequired(): self
    {
        return new self('TWO_FACTOR_REQUIRED', 'Introduza o código da sua aplicação de autenticação.', 403);
    }

    public static function invalidTwoFactorCode(): self
    {
        return new self('INVALID_TWO_FACTOR_CODE', 'Esse código não está certo. Tente o código seguinte.', 422);
    }
}
