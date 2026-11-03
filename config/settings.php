<?php

use App\Enums\Settings\Setting;

return [

    /*
    |--------------------------------------------------------------------------
    | Site Settings Table
    |--------------------------------------------------------------------------
    |
    | The table that holds settings for the whole site. Change it before you
    | run the migration if your application already has a "settings" table.
    |
    */

    'table' => 'settings',

    /*
    |--------------------------------------------------------------------------
    | Site Settings Enum
    |--------------------------------------------------------------------------
    |
    | The enum that names every site-wide setting. It must implement
    | Coyote6\LaravelSettings\Contracts\SettingName. Running
    | "php artisan settings:install" creates it for you.
    |
    */

    'enum' => Setting::class,

    /*
    |--------------------------------------------------------------------------
    | Setting Types
    |--------------------------------------------------------------------------
    |
    | One entry per model that owns its own settings, such as users or
    | clients. Each type has its own table with a foreign key to its owner:
    |
    |     'client' => [
    |         'owner' => App\Models\Client::class,
    |         'enum' => App\Enums\Settings\ClientSetting::class,
    |         'model' => App\Models\Settings\ClientSettingValue::class,
    |     ],
    |
    | Add 'owner_key' => 'column' only when the settings table's owner column
    | is not the owner's foreign key name (client_id for a Client).
    |
    */

    'types' => [
        // settings:add-type adds entries below this line.
    ],

];
