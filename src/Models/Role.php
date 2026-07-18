<?php

declare(strict_types=1);

namespace Laravel\Ronin\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Ronin\Concerns\HasPermissions;
use Laravel\Ronin\Contracts\Role as RoleContract;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Ronin\Guard;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $special
 * @property string|null $description
 * @property string $guard_name
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Ronin\Models\Permission> $permissions
 */
class Role extends Model implements RoleContract
{
    use HasPermissions;

    /**
     * The attributes that are fillable via mass assignment.
     *
     * @var array<int, string>
     */
    protected $fillable = ['name', 'slug', 'description', 'special', 'guard_name'];

    /**
     * Create a new Role instance.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        $attributes['guard_name'] = $attributes['guard_name'] ?? Guard::getDefaultName(static::class);

        parent::__construct($attributes);

        $this->setTable(config('ronin.tables.roles'));
    }

    /**
     * Roles can belong to many users.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(config('auth.model') ?: config('auth.providers.users.model'))->withTimestamps();
    }

    /**
     * Determine if role has permission flags.
     */
    public function hasPermissionFlags(): bool
    {
        return ! is_null($this->special);
    }

    /**
     * Determine if the requested permission is permitted or denied
     * through a special role flag.
     */
    public function hasPermissionThroughFlag(): bool
    {
        if ($this->hasPermissionFlags()) {
            return ! ($this->special === 'no-access');
        }

        return true;
    }
}
