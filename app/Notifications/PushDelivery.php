<?php

namespace App\Notifications;

/**
 * What Apple said about one notification for one phone.
 */
enum PushDelivery
{
    case Delivered;
    /** The phone will never take this token again, so it is forgotten. */
    case DeviceGone;
    /** Anything else; logged, and the next notification tries again. */
    case Failed;
}
