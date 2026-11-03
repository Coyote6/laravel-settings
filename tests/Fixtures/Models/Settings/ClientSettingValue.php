<?php

namespace Coyote6\LaravelSettings\Tests\Fixtures\Models\Settings;

use Coyote6\LaravelSettings\Models\OwnedSettingValue;

/**
 * One stored client setting value.
 */
class ClientSettingValue extends OwnedSettingValue
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'client_settings';
}
