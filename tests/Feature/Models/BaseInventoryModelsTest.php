<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Articulo;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BaseInventoryModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_maps_roles_with_timestamps_and_boolean_active_cast(): void
    {
        $role = new Rol();
        $role->setRawAttributes(['activo' => 1]);

        $this->assertSame('roles', $role->getTable());
        $this->assertTrue($role->usesTimestamps());
        $this->assertSame('boolean', $role->getCasts()['activo']);
        $this->assertTrue($role->activo);
    }

    public function test_role_exposes_users_and_permissions_without_pivot_timestamps(): void
    {
        $role = new Rol();
        $users = $role->usuarios();
        $permissions = $role->permisos();

        $this->assertInstanceOf(HasMany::class, $users);
        $this->assertInstanceOf(User::class, $users->getRelated());
        $this->assertSame('rol_id', $users->getForeignKeyName());
        $this->assertSame('id', $users->getLocalKeyName());
        $this->assertInstanceOf(BelongsToMany::class, $permissions);
        $this->assertInstanceOf(Permiso::class, $permissions->getRelated());
        $this->assertSame('rol_permiso', $permissions->getTable());
        $this->assertSame('rol_id', $permissions->getForeignPivotKeyName());
        $this->assertSame('permiso_id', $permissions->getRelatedPivotKeyName());
        $this->assertSame([], $permissions->getPivotColumns());
    }

    public function test_permission_maps_permisos_with_timestamps_and_boolean_active_cast(): void
    {
        $permission = new Permiso();
        $permission->setRawAttributes(['activo' => 1]);

        $this->assertSame('permisos', $permission->getTable());
        $this->assertTrue($permission->usesTimestamps());
        $this->assertSame('boolean', $permission->getCasts()['activo']);
        $this->assertTrue($permission->activo);
    }

    public function test_permission_exposes_roles_through_the_timestamp_free_pivot(): void
    {
        $roles = (new Permiso())->roles();

        $this->assertInstanceOf(BelongsToMany::class, $roles);
        $this->assertInstanceOf(Rol::class, $roles->getRelated());
        $this->assertSame('rol_permiso', $roles->getTable());
        $this->assertSame('permiso_id', $roles->getForeignPivotKeyName());
        $this->assertSame('rol_id', $roles->getRelatedPivotKeyName());
        $this->assertSame([], $roles->getPivotColumns());
    }

    public function test_permission_stable_identity_is_not_mass_assignable(): void
    {
        $permission = new Permiso();

        $this->assertFalse($permission->isFillable('codigo'));
        $this->assertFalse($permission->isFillable('modulo'));
    }

    public function test_article_declares_only_schema_backed_inventory_casts(): void
    {
        $article = new Articulo();
        $casts = $article->getCasts();

        $this->assertSame('articulos', $article->getTable());
        $this->assertTrue($article->usesTimestamps());
        $this->assertSame('decimal:2', $casts['precio_venta_actual']);
        $this->assertSame('decimal:3', $casts['stock_actual']);
        $this->assertSame('decimal:3', $casts['stock_minimo']);
        $this->assertSame('boolean', $casts['activo']);
        $this->assertSame('immutable_datetime', $casts['created_at']);
        $this->assertSame('immutable_datetime', $casts['updated_at']);
    }

    public function test_article_hydrates_decimal_values_as_strings_and_dates_as_immutable(): void
    {
        $categoryId = $this->createArticleCategory();
        $unitId = $this->createUnitOfMeasure();
        $articleId = (int) DB::table('articulos')->insertGetId([
            'sku' => 'modelo_articulo',
            'nombre' => 'Articulo de modelo',
            'descripcion' => null,
            'tipo_articulo' => 'materia_prima',
            'categoria_articulo_id' => $categoryId,
            'unidad_id' => $unitId,
            'codigo_barras' => null,
            'precio_venta_actual' => '1250.50',
            'stock_actual' => '12.375',
            'stock_minimo' => '2.500',
            'activo' => true,
            'imagen_ruta' => null,
        ]);

        $article = Articulo::query()->findOrFail($articleId);

        $this->assertSame('1250.50', $article->precio_venta_actual);
        $this->assertSame('12.375', $article->stock_actual);
        $this->assertSame('2.500', $article->stock_minimo);
        $this->assertTrue($article->activo);
        $this->assertInstanceOf(CarbonImmutable::class, $article->created_at);
        $this->assertInstanceOf(CarbonImmutable::class, $article->updated_at);
    }

    public function test_article_current_stock_is_not_mass_assignable(): void
    {
        $this->assertFalse((new Articulo())->isFillable('stock_actual'));
    }

    private function createArticleCategory(): int
    {
        return (int) DB::table('categorias_articulo')->insertGetId([
            'codigo' => 'modelo_categoria',
            'nombre' => 'Categoria de modelo',
            'descripcion' => null,
            'activo' => true,
        ]);
    }

    private function createUnitOfMeasure(): int
    {
        return (int) DB::table('unidades_medida')->insertGetId([
            'codigo' => 'modelo_unidad',
            'nombre' => 'Unidad de modelo',
            'simbolo' => 'um',
            'magnitud' => 'unidad',
            'activo' => true,
        ]);
    }
}
