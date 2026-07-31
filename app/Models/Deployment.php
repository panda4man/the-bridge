<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Ported from reference/src/models/deployment.ts.
 */
#[Fillable([
    'app_id',
    'status',
    'log',
    'started_at',
    'finished_at',
    'commit_sha',
    'commit_message',
    'rollback_sha',
])]
class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /**
     * Find the most recent successful deployment for an app that predates
     * $beforeId and has a known commit SHA.
     *
     * Exact semantics ported from reference findLastSuccessful(): all four
     * conditions matter. `commit_sha IS NOT NULL` is what makes rollback
     * safe — a successful deployment with no recorded commit has nothing to
     * roll back to.
     */
    public static function findLastSuccessful(int $appId, int $beforeId): ?self
    {
        return static::query()
            ->where('app_id', $appId)
            ->where('status', DeploymentStatus::Success)
            ->where('id', '<', $beforeId)
            ->whereNotNull('commit_sha')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Append a chunk to the log, stripping ANSI escapes and carriage
     * returns first, and bump updated_at.
     *
     * Uses a SQL-level concatenation (COALESCE(log, '') || ?) rather than a
     * read-modify-write so concurrent chunks from the same deploy process
     * don't clobber each other — ported from reference appendLog().
     *
     * Stripping happens on write, not read, because Phase 5/6 polling
     * offsets are character offsets into the stored string.
     *
     * Deliberately does NOT call refresh(): the reference appendLog() is a
     * free function that touches no object state, and Phase 3 holds one
     * $deployment instance across an entire deploy, interleaving this call
     * with unsaved status/commit_sha assignment
     * (`$d->commit_sha = $sha; $d->appendLog($out); $d->save();`). A full
     * refresh() would silently discard that dirty state. Only the `log`
     * attribute is pulled back and marked clean; everything else the caller
     * has set stays dirty and is preserved for their own save().
     */
    public function appendLog(string $chunk): static
    {
        DB::update(
            'update '.$this->getTable().' set log = COALESCE(log, \'\') || ?, updated_at = ? where '.$this->getKeyName().' = ?',
            [static::stripAnsi($chunk), now()->toDateTimeString(), $this->getKey()]
        );

        $freshLog = DB::table($this->getTable())
            ->where($this->getKeyName(), $this->getKey())
            ->value('log');

        $this->setAttribute('log', $freshLog);
        $this->syncOriginalAttribute('log');

        return $this;
    }

    /**
     * Ported byte-for-byte from reference/src/models/deployment.ts stripAnsi().
     */
    public static function stripAnsi(string $text): string
    {
        return preg_replace('/\x1b\[[0-9;]*[A-Za-z]|\r/', '', $text);
    }
}
