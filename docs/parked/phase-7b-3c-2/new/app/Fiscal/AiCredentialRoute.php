<?php

namespace App\Fiscal;

/** Resolved once per interaction from the workspace settings row; never caller-selectable. */
enum AiCredentialRoute
{
    case LegacyUngoverned;
    case VapManaged;
    case CustomerManaged;
}
