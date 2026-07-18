<?php

declare(strict_types=1);

namespace Laravel\Ronin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Ronin\Concerns\RefreshesPermissionCache;
use Laravel\Ronin\Contracts\Permission as PermissionContract;
use Laravel\Ronin\Guard;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $guard_name
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Ronin\Models\Role> $roles
 */
class Permission extends Model implements PermissionContract
{
    use RefreshesPermissionCache;

    /**
     * The attributes that are fillable via mass assignment.
     *
     * @var array<int, string>
     */
    protected $fillable = ['name', 'slug', 'description', 'guard_name'];

    /**
     * Create a new Permission instance.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        $attributes['guard_name'] = $attributes['guard_name'] ?? Guard::getDefaultName(static::class);

        parent::__construct($attributes);

        $this->setTable(config('ronin.tables.permissions'));
    }

    /**
     * Permissions can belong to many roles.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(config('ronin.models.role'))->withTimestamps();
    }
}
