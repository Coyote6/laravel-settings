<?php

namespace Coyote6\LaravelSettings\Tests\Fixtures\Models;

use Coyote6\LaravelSettings\Concerns\HasSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * A model that uses HasSettings but has no settings type in config.
 */
class Team extends Model
{
    use HasSettings;
}
