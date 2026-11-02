<?php

namespace Coyote6\LaravelSettings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One stored site-wide setting value.
 *
 * @property string $id
 * @property string $name
 * @property mixed $value
 */
class SettingValue extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'value',
    ];

    /**
     * Get the table associated with the model.
     *
     * @label Get Table
     *
     * @ai Read from config('settings.table') so a site that already has a
     *     "settings" table can rename this one. A subclass that sets its
     *     own $table keeps it.
     */
    public function getTable(): string
    {
        return $this->table ?? config()->string('settings.table', 'settings');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @label Casts
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
