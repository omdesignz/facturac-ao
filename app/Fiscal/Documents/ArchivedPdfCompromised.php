<?php

namespace App\Fiscal\Documents;

use RuntimeException;

/**
 * The stored PDF of an issued document is missing, unreadable or no longer
 * matches its hash. Never answered by re-rendering: that would replace
 * evidence with a copy nobody can vouch for.
 */
class ArchivedPdfCompromised extends RuntimeException {}
