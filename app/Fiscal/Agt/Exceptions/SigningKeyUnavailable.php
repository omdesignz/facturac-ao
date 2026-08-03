<?php

namespace App\Fiscal\Agt\Exceptions;

use RuntimeException;

class SigningKeyUnavailable extends RuntimeException
{
    // The message must remain safe for user-facing diagnostics and logs.
}
