<?php

namespace App\Fiscal\Documents;

use RuntimeException;

/**
 * An issued document no longer matches the record of how it was printed: its
 * stored values changed, or the logo it was issued with is gone. Never
 * answered by printing anyway, which would put an unverifiable document in
 * someone's hands.
 */
class PrintRecordMismatch extends RuntimeException {}
