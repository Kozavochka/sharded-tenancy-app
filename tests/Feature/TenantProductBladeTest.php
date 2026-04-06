<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Http\Middleware\InitializeTenancyByCachedDomain;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use Tests\TestCase;

class TenantProductBladeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for TenantProductBladeTest.');
        }

        $this->withoutMiddleware([
            InitializeTenancyByCachedDomain::class,
            PreventAccessFromCentralDomains::class,
        ]);

        if (! Schema::hasTable('products')) {
            Schema::create('products', function ($table): void {
                $table->id();
                $table->string('name');
                $table->decimal('price', 10, 2);
                $table->timestamps();
            });
        }
    }

    public function test_products_index_is_accessible(): void
    {
        $response = $this->get('/products');

        $response->assertOk();
        $response->assertSee('Tenant Products');
    }

    public function test_product_can_be_created_updated_and_deleted(): void
    {
        $createResponse = $this->post('/products', [
            'name' => 'Milk',
            'price' => '12.50',
        ]);
        $createResponse->assertRedirect('/products');
        $this->assertDatabaseHas('products', ['name' => 'Milk']);

        /** @var Product $product */
        $product = Product::query()->firstOrFail();

        $editResponse = $this->get("/products/{$product->id}/edit");
        $editResponse->assertOk();
        $editResponse->assertSee('Edit Product');

        $updateResponse = $this->put("/products/{$product->id}", [
            'name' => 'Milk 2',
            'price' => '14.00',
        ]);
        $updateResponse->assertRedirect('/products');
        $this->assertDatabaseHas('products', ['name' => 'Milk 2']);

        $deleteResponse = $this->delete("/products/{$product->id}");
        $deleteResponse->assertRedirect('/products');
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }
}
