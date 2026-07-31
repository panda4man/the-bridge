<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Ported from reference/src/models/setting.ts — a plain key/value store.
 * `key` is the primary key (string, non-incrementing); there are no
 * timestamps on this table.
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * Get a setting's value, or null if unset. Ported from reference get().
     */
    public static function getValue(string $key): ?string
    {
        return static::query()->find($key)?->value;
    }

    /**
     * Upsert a setting's value. Ported from reference set(), which uses
     * `ON CONFLICT(key) DO UPDATE`. `upsert()` compiles to the same atomic
     * single-statement conflict resolution on SQLite; `updateOrInsert()`
     * would be check-then-act (exists() then insert()/update()).
     */
    public static function setValue(string $key, string $value): void
    {
        static::query()->upsert(['key' => $key, 'value' => $value], 'key', ['value']);
    }
}
