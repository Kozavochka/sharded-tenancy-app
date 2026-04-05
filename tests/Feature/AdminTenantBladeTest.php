<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\CreateTenantAction;
use App\Actions\DeleteTenantAction;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AdminTenantBladeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for AdminTenantBladeTest.');
        }

        if (! Schema::hasTable('tenants')) {
            Schema::create('tenants', function ($table): void {
                $table->string('id')->primary();
                $table->string('name')->nullable();
                $table->string('plan')->nullable();
                $table->string('tenant_size')->nullable();
                $table->string('shard')->nullable();
                $table->string('tenancy_db_connection')->nullable();
                $table->string('tenant_schema')->nullable();
                $table->timestamps();
                $table->json('data')->nullable();
            });
        }

        if (! Schema::hasTable('domains')) {
            Schema::create('domains', function ($table): void {
                $table->increments('id');
                $table->string('domain', 255)->unique();
                $table->string('tenant_id');
                $table->timestamps();
            });
        }
    }

    public function test_admin_tenants_index_is_accessible(): void
    {
        $response = $this->get('/admin/tenants');

        $response->assertOk();
        $response->assertSee('Tenant Admin');
    }

    public function test_admin_can_create_tenant_via_action(): void
    {
        $mock = Mockery::mock(CreateTenantAction::class);
        $mock->shouldReceive('execute')
            ->once()
            ->with('Demo Tenant', 'free', 'small', 'demo.localhost')
            ->andReturn(Tenant::create([
                'id' => 'test-tenant-id',
                'name' => 'Demo Tenant',
                'plan' => 'free',
                'tenant_size' => 'small',
                'shard' => 'shard_1',
                'tenancy_db_connection' => 'tenant_shard_1',
                'tenant_schema' => 'tenant_test1234',
            ]));

        $this->instance(CreateTenantAction::class, $mock);

        $response = $this->post('/admin/tenants', [
            'name' => 'Demo Tenant',
            'plan' => 'free',
            'tenant_size' => 'small',
            'domain' => 'demo.localhost',
        ]);

        $response->assertRedirect('/admin/tenants');
        $response->assertSessionHas('status', 'Tenant created successfully.');
    }

    public function test_admin_can_delete_tenant_via_action(): void
    {
        $tenant = Tenant::create([
            'id' => 'delete-tenant-id',
            'name' => 'Delete Me',
            'plan' => 'enterprise',
            'tenant_size' => 'large',
            'shard' => 'shard_2',
            'tenancy_db_connection' => 'tenant_shard_2',
            'tenant_schema' => 'tenant_delete01',
        ]);

        $mock = Mockery::mock(DeleteTenantAction::class);
        $mock->shouldReceive('execute')
            ->once()
            ->withArgs(function (Tenant $arg) use ($tenant): bool {
                return $arg->id === $tenant->id;
            });

        $this->instance(DeleteTenantAction::class, $mock);

        $response = $this->delete("/admin/tenants/{$tenant->id}");

        $response->assertRedirect('/admin/tenants');
        $response->assertSessionHas('status', 'Tenant deleted successfully.');
    }

    public function test_open_route_redirects_to_tenant_domain_products(): void
    {
        $tenant = Tenant::create([
            'id' => 'open-tenant-id',
            'name' => 'Open Me',
            'plan' => 'free',
            'tenant_size' => 'small',
            'shard' => 'shard_1',
            'tenancy_db_connection' => 'tenant_shard_1',
            'tenant_schema' => 'tenant_open123',
        ]);
        $tenant->domains()->create(['domain' => 'open.localhost']);

        $response = $this->get("/admin/tenants/{$tenant->id}/open");

        $response->assertRedirect('http://open.localhost/products');
    }
}
