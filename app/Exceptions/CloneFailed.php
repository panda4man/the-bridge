<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\AppProvisioner::provision() when the git clone of a
 * new app fails.
 *
 * Exists as its own type purely so the Filament create page can tell a clone
 * failure (which must surface as a validation error on the `repo_url` field,
 * per reference/src/routes/apps.ts:52-62) apart from any other RuntimeException
 * escaping the provisioning step, which should surface as a real error.
 *
 * getMessage() is ALREADY the user-facing string, prefixed `Clone failed: ` —
 * the prefix is parity-critical and belongs next to the throw, not at the
 * call site.
 */
final class CloneFailed extends RuntimeException {}
