<?php

namespace App\Fiscal;

/** Preserve known application statuses; do not invent detail discarded by the accepted adapter. */
enum AiGatewayFailure: int
{
    case Unauthenticated = 401;
    case Forbidden = 403;
    case NotFound = 404;
    case QuotaExceeded = 429;
    case Unavailable = 503;
}
