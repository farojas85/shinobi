<?php

return [

    'models' => [

        /*
        |--------------------------------------------------------------------------
        | Model References
        |--------------------------------------------------------------------------
        |
        | Ronin needs to know which Eloquent Models should be referenced during
        | actions such as registering and checking for permissions, assigning
        | permissions to roles and users, and assigning roles to users.
        */

        'role' => Laravel\Ronin\Models\Role::class,
        'permission' => Laravel\Ronin\Models\Permission::class,

    ],

    'tables' => [

        /*
        |--------------------------------------------------------------------------
        | Table References
        |--------------------------------------------------------------------------
        |
        | When customizing the models used by Ronin, you may also wish to
        | customize the table names as well. You will want to publish the
        | bundled migrations and update the references here for use.
        */

        'roles' => 'roles',
        'permissions' => 'permissions',
        'role_user' => 'role_user',
        'permission_role' => 'permission_role',
        'permission_user' => 'permission_user',

    ],

    'cache' => [

        /*
        |--------------------------------------------------------------------------
        | Cache Layer
        |--------------------------------------------------------------------------
        |
        | Ronin ships with a caching layer in an attempt to lessen the load on
        | resources when checking and validating permissions. By default this is
        | disabled.
        */

        /**
         * Enable or disable the built-in caching system.
         */
        'enabled' => false,

        /**
         * Enable request-level in-memory cache to prevent duplicate lookups in a single request lifecycle.
         */
        'request_memory' => true,

        /**
         * Enable granular caching (caching per user/role specifically instead of the entire table).
         * Highly recommended for large projects with high performance/scalability requirements.
         */
        'granular' => true,

        /**
         * Define the length of time permissions should be cached for before being
         * refreshed (in seconds). By default we cache for 86400 seconds (24 hours).
         */
        'length' => 60 * 60 * 24,

        /**
         * The cache key prefix used for granular keys.
         */
        'prefix' => 'ronin',

        /**
         * The cache key used to store all permissions (only when granular caching is disabled).
         */
        'key' => 'ronin.permission.cache',

        /**
         * The tag used if the cache driver supports tags.
         */
        'tag' => 'ronin',

    ],

    /*
    |--------------------------------------------------------------------------
    | Use Migrations
    |--------------------------------------------------------------------------
    |
    | Ronin comes packaged with migrations. If instead you wish to customize or
    | extend Ronin beyond its offering, you may disable the migrations.
    */

    'migrate' => true,

];
