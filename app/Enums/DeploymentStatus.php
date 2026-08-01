<?php

namespace App\Enums;

/**
 * Ported from reference/src/enums.ts DeploymentStatus.
 */
enum DeploymentStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';

    /**
     * A deployment the worker will not write to again.
     *
     * The reference keeps this list inline in three places — the SSE stream's
     * `TERMINAL` const (reference/src/routes/deployments.ts:6), the API log
     * endpoint's `done` flag (reference/src/routes/api.ts:100) and the view's
     * `done` initialiser (reference/src/views/deployments/show.ejs:2). Here it
     * is one method, because the log endpoint's `X-Deploy-Done` header and the
     * log viewer's stop-polling condition MUST agree: a viewer that keeps
     * polling a finished deployment never stops, and one that stops early
     * truncates the log at whatever the last poll saw.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Success, self::Failed => true,
            self::Pending, self::Running => false,
        };
    }

    /**
     * A deployment the manual Reset control may fail.
     *
     * Ported from reference/src/routes/deployments.ts:57 (`resetable`). The
     * complement of isTerminal() today, and deliberately not written as
     * `! $this->isTerminal()`: they answer different questions and a fifth
     * status (say `cancelled` — terminal, but not something Reset should
     * offer to fail) would need them to diverge.
     */
    public function isResettable(): bool
    {
        return match ($this) {
            self::Pending, self::Running => true,
            self::Success, self::Failed => false,
        };
    }
}
