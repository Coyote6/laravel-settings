<?php

namespace Coyote6\LaravelSettings\Tests\Fixtures\Models\Settings;

use Coyote6\LaravelSettings\Models\OwnedSettingValue;

/**
 * One stored user setting value.
 */
class UserSettingValue extends OwnedSettingValue
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_settings';
}
