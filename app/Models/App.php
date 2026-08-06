<?php

namespace App\Models;

use App\Enums\AppStatus;
use Database\Factories\AppFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ported from reference/src/models/app.ts.
 *
 * `deploy_steps` is left as a raw JSON string — no `array` cast. See
 * docs/porting-notes.md (B5) for why: Eloquent's `array` cast decodes
 * malformed JSON to `null` with no error, which is indistinguishable from
 * "no steps configured" and would make
 * reference/src/services/deploySteps.test.ts's "throws on malformed JSON in
 * deploy_steps" parity case unreachable. Phase 2's DeploySteps service must
 * do its own json_decode with explicit error detection, exactly as the
 * reference does.
 */
#[Fillable([
    'name',
    'repo_url',
    'branch',
    'path',
    'status',
    'health_url',
    'web_url',
    'health_check_interval',
    'webhook_secret',
    'deploy_steps',
])]
class App extends Model
{
    /** @use HasFactory<AppFactory> */
    use HasFactory;

    /**
     * In-memory defaults mirroring the DB column defaults, so a freshly
     * created (unsaved-fields-omitted) instance matches what the reference
     * create() explicitly defaulted before re-querying — without a second
     * round trip to the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'branch' => 'main',
        'status' => 'idle',
        'health_check_interval' => 60,
    ];

    protected function casts(): array
    {
        return [
            'status' => AppStatus::class,
            'health_check_interval' => 'integer',
        ];
    }

    /**
     * @return HasMany<Deployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /**
     * @return HasMany<HealthCheck, $this>
     */
    public function healthChecks(): HasMany
    {
        return $this->hasMany(HealthCheck::class);
    }

    /**
     * @return HasOne<Deployment, $this>
     */
    public function latestDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->latestOfMany();
    }
}
