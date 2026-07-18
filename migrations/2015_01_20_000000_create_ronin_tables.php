<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateRoninTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $tableNames = config('ronin.tables');

        if (empty($tableNames)) {
            throw new \Exception('Error: config/ronin.php not loaded. Run [php artisan config:clear] and try again.');
        }

        Schema::create($tableNames['roles'], function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->enum('special', ['all-access', 'no-access'])->nullable();
            $table->string('guard_name')->default('web');
            $table->timestamps();

            $table->unique(['slug', 'guard_name']);
        });

        Schema::create($tableNames['permissions'], function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('guard_name')->default('web');
            $table->timestamps();

            $table->unique(['slug', 'guard_name']);
        });

        Schema::create($tableNames['role_user'], function (Blueprint $table) use ($tableNames) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('role_id')->index();
            $table->foreign('role_id')->references('id')->on($tableNames['roles'])->onDelete('cascade');
            $table->unsignedBigInteger('user_id')->index();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create($tableNames['permission_role'], function (Blueprint $table) use ($tableNames) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('permission_id')->index();
            $table->foreign('permission_id')->references('id')->on($tableNames['permissions'])->onDelete('cascade');
            $table->unsignedBigInteger('role_id')->index();
            $table->foreign('role_id')->references('id')->on($tableNames['roles'])->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create($tableNames['permission_user'], function (Blueprint $table) use ($tableNames) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('permission_id')->index();
            $table->foreign('permission_id')->references('id')->on($tableNames['permissions'])->onDelete('cascade');
            $table->unsignedBigInteger('user_id')->index();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $tableNames = config('ronin.tables');

        if (empty($tableNames)) {
            throw new \Exception('Error: config/ronin.php not loaded. Run [php artisan config:clear] and try again.');
        }

        Schema::dropIfExists($tableNames['permission_user']);
        Schema::dropIfExists($tableNames['permission_role']);
        Schema::dropIfExists($tableNames['role_user']);
        Schema::dropIfExists($tableNames['permissions']);
        Schema::dropIfExists($tableNames['roles']);
    }
}
