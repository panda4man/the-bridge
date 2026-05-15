<?php

namespace App\Models;

use App\Enums\AppStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class App extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'repo_url', 'branch', 'path', 'status'];

    protected $casts = [
        'status' => AppStatus::class,
    ];

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }
}
