<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 'admin' | 'user'
            $table->string('role', 20)->default('user')->after('email');
            // Un usuario nuevo no puede usar la app hasta que un admin lo active
            $table->boolean('is_active')->default(false)->after('role');
        });

        // Los usuarios que ya existían venían usando la app: quedan activos
        DB::table('users')->update(['is_active' => true]);

        DB::table('users')
            ->where('email', config('docbrainer.admin_email'))
            ->update(['role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
