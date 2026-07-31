<?php

namespace App\Models;

use App\Enums\HealthStatus;
use Database\Factories\HealthCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ported from reference/src/models/healthCheck.ts.
 *
 * No created_at/updated_at — only `checked_at`, matching the reference
 * schema exactly.
 *
 * consecutiveFailures() has zero callers in the reference and is
 * deliberately not ported (see docs/porting-notes.md).
 */
#[Fillable(['app_id', 'status', 'http_status_code', 'response_time_ms', 'checked_at'])]
class HealthCheck extends Model
{
    /** @use HasFactory<HealthCheckFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'status' => HealthStatus::class,
            'http_status_code' => 'integer',
            'response_time_ms' => 'integer',
            'checked_at' => 'datetime',
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
     * Insert a health check row, then prune to the 20 most recent rows for
     * that app. Ported from reference record().
     */
    public static function record(int $appId, string $status, ?int $httpStatusCode, ?int $responseTimeMs): self
    {
        $check = static::create([
            'app_id' => $appId,
            'status' => $status,
            'http_status_code' => $httpStatusCode,
            'response_time_ms' => $responseTimeMs,
        ]);

        static::query()
            ->where('app_id', $appId)
            ->whereNotIn('id', static::query()
                ->where('app_id', $appId)
                ->orderByDesc('id')
                ->limit(20)
                ->select('id'))
            ->delete();

        return $check;
    }

    public static function findLatest(int $appId): ?self
    {
        return static::query()
            ->where('app_id', $appId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return Collection<int, self>
     */
    public static function listRecent(int $appId): Collection
    {
        return static::query()
            ->where('app_id', $appId)
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }
}
