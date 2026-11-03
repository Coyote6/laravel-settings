<?php

namespace Coyote6\LaravelSettings\Tests\Fixtures\Models;

use Coyote6\LaravelSettings\Concerns\HasSettings;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A client with a UUID key, as in Scorebook.
 */
class Client extends Model
{
    use HasSettings;
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];
}
