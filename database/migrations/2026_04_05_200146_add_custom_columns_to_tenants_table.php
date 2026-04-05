<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->string('plan')->nullable()->after('name');
            $table->string('tenant_size')->nullable()->after('plan');
            $table->string('shard')->nullable()->after('tenant_size');
            $table->string('tenancy_db_connection')->nullable()->after('shard');
            $table->string('tenant_schema')->nullable()->after('tenancy_db_connection');

            $table->index('shard');
            $table->index('tenancy_db_connection');
            $table->index('tenant_schema');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['shard']);
            $table->dropIndex(['tenancy_db_connection']);
            $table->dropIndex(['tenant_schema']);

            $table->dropColumn([
                'name',
                'plan',
                'tenant_size',
                'shard',
                'tenancy_db_connection',
                'tenant_schema',
            ]);
        });
    }
};
