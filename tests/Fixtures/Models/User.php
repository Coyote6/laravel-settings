<?php

namespace Coyote6\LaravelSettings\Tests\Fixtures\Models;

use Coyote6\LaravelSettings\Concerns\HasSettings;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A user with an integer key, as in a new Laravel application.
 */
class User extends Authenticatable
{
    use HasSettings;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];
}
