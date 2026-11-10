<?php

declare(strict_types=1);

namespace Tests\Feature\Articles;

use App\Enums\PermissionCode as P;
use App\Models\Articulo;
use App\Models\CategoriaArticulo;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Articles\ArticleAdministrationService;
use App\Services\Articles\ArticleCatalogService;
use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockEightRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\TestCase;

final class ArticleCatalogWebTest extends TestCase
{
    use CreatesBlockEightRecords;
    use CreatesBlockOneRecords;
    use RefreshDatabase;

    #[DataProvider('endpoints')]
    public function test_all_routes_require_authentication_and_effective_management_permission(string $kind, string $action, string $method): void
    {
        $entry = $this->entry($kind);
        $url = route('catalog.'.$kind.'.'.$action, in_array($action, ['edit', 'update', 'activate', 'deactivate'], true) ? $entry : []);
        $this->{$method}($url, [])->assertRedirect(route('login'));
        $namedOwner = $this->actor([], 'Administrador propietario');
        $this->actingAs($namedOwner)->{$method}($url, [])->assertForbidden();
        $cashier = $this->actor([P::ArticlesView, P::SalesCreate, P::CashSessionsOpen], 'cajera');
        $this->actingAs($cashier)->{$method}($url, [])->assertForbidden();
        $this->assertSame(1, $entry->newQuery()->count());
        $this->assertTrue($entry->fresh()->activo);
    }

    public static function endpoints(): array
    {
        $cases = [];
        foreach (['categories', 'units'] as $kind) {
            foreach (['index' => 'get', 'create' => 'get', 'store' => 'post', 'edit' => 'get', 'update' => 'put', 'activate' => 'post', 'deactivate' => 'post'] as $action => $method) {
                $cases[$kind.'-'.$action] = [$kind, $action, $method];
            }
        }

        return $cases;
    }

    #[DataProvider('kinds')]
    public function test_manager_can_create_search_edit_deactivate_and_reactivate_without_other_permissions(string $kind): void
    {
        $manager = $this->actor([P::ArticlesManage]);
        $payload = $this->payload($kind);
        $prefix = 'catalog.'.$kind;
        $this->actingAs($manager)->get(route($prefix.'.create'))->assertOk();
        $this->post(route($prefix.'.store'), [...$payload, 'codigo' => ' '.strtoupper($payload['codigo']).' ', 'nombre' => ' '.$payload['nombre'].' ', 'activo' => false])
            ->assertSessionHasNoErrors()->assertRedirect(route($prefix.'.index'));
        $entry = $this->model($kind)::query()->sole();
        $this->assertSame($payload['codigo'], $entry->codigo);
        $this->assertSame($payload['nombre'], $entry->nombre);
        $this->assertTrue($entry->activo);
        $this->get(route($prefix.'.index', ['q' => strtoupper($payload['nombre'])]))->assertOk()->assertSee($payload['nombre']);
        $this->get(route($prefix.'.edit', $entry))->assertOk()->assertSee('Guardar cambios');
        $changed = [...$payload, 'codigo' => 'catalogo_editado', 'nombre' => 'Nombre editado'];
        $this->put(route($prefix.'.update', $entry), $changed)->assertSessionHasNoErrors()->assertRedirect(route($prefix.'.index'));
        $this->assertSame('Nombre editado', $entry->fresh()->nombre);
        $this->post(route($prefix.'.deactivate', $entry))->assertSessionHasNoErrors();
        $this->assertFalse($entry->fresh()->activo);
        $this->get(route($prefix.'.index', ['activo' => 'activos']))->assertOk()->assertDontSee('Nombre editado');
        $this->get(route($prefix.'.index', ['activo' => 'inactivos']))->assertOk()->assertSee('Nombre editado');
        $this->post(route($prefix.'.activate', $entry))->assertSessionHasNoErrors();
        $this->post(route($prefix.'.activate', $entry))->assertSessionHasNoErrors();
        $this->assertTrue($entry->fresh()->activo);
        $this->assertSame(1, $entry->newQuery()->count());
        $this->assertSame(0, DB::table('articulos')->count());
        $this->assertSame(0, DB::table('movimientos_inventario')->count());
    }

    public static function kinds(): array
    {
        return [['categories'], ['units']];
    }

    #[DataProvider('duplicates')]
    public function test_duplicates_are_rejected_after_trimming_and_without_case_distinctions(string $kind, string $field): void
    {
        $manager = $this->actor([P::ArticlesManage]);
        $entry = $this->entry($kind, $this->payload($kind));
        $payload = [...$this->payload($kind), 'codigo' => 'otro_codigo', 'nombre' => 'Otro nombre'];
        if ($kind === 'units') {
            $payload['simbolo'] = 'otro';
        }
        $payload[$field] = ' '.mb_strtoupper($entry->{$field}).' ';
        $this->actingAs($manager)->post(route('catalog.'.$kind.'.store'), $payload)->assertSessionHasErrors($field);
        $this->assertSame(1, $entry->newQuery()->count());
        $other = $this->entry($kind);
        $this->put(route('catalog.'.$kind.'.update', $other), $payload)->assertSessionHasErrors($field);
        $this->assertNotSame($entry->{$field}, $other->fresh()->{$field});
    }

    public static function duplicates(): array
    {
        return [['categories', 'codigo'], ['categories', 'nombre'], ['units', 'codigo'], ['units', 'nombre'], ['units', 'simbolo']];
    }

    #[DataProvider('invalidFields')]
    public function test_catalog_fields_follow_the_real_schema(string $kind, string $field, mixed $value): void
    {
        $manager = $this->actor([P::ArticlesManage]);
        $this->actingAs($manager)->from(route('catalog.'.$kind.'.create'))->post(route('catalog.'.$kind.'.store'), [...$this->payload($kind), $field => $value])
            ->assertSessionHasErrors($field)->assertRedirect(route('catalog.'.$kind.'.create'));
        $this->assertSame(0, $this->model($kind)::query()->count());
    }

    public static function invalidFields(): array
    {
        $cases = [];
        foreach (['categories', 'units'] as $kind) {
            foreach ([['codigo', ''], ['codigo', '1incorrecto'], ['codigo', 'con espacios'], ['codigo', str_repeat('a', 51)], ['codigo', ['x']], ['nombre', '   '], ['nombre', str_repeat('n', 101)], ['nombre', ['x']]] as [$field, $value]) {
                $cases[] = [$kind, $field, $value];
            }
        }
        return [...$cases, ['categories', 'descripcion', ['x']], ['units', 'simbolo', ' '], ['units', 'simbolo', str_repeat('s', 21)], ['units', 'magnitud', 'longitud'], ['units', 'magnitud', '']];
    }

    #[DataProvider('kinds')]
    public function test_inactive_records_can_be_edited_without_reactivation_and_missing_records_are_rejected(string $kind): void
    {
        $manager = $this->actor([P::ArticlesManage]);
        $entry = $this->entry($kind, ['activo' => false]);
        $this->actingAs($manager)->get(route('catalog.'.$kind.'.edit', $entry))->assertOk()->assertSee('Inactiva');
        $this->put(route('catalog.'.$kind.'.update', $entry), $this->payload($kind))->assertSessionHasNoErrors();
        $this->assertFalse($entry->fresh()->activo);
        $this->put(route('catalog.'.$kind.'.update', 999999), $this->payload($kind))->assertNotFound();
        $this->post(route('catalog.'.$kind.'.deactivate', 999999))->assertNotFound();
        $this->delete(url('/catalogo/'.($kind === 'units' ? 'unidades' : 'categorias').'/'.$entry->id))->assertStatus(405);
        $this->assertNotNull($entry->fresh());
    }

    public function test_navigation_and_endpoints_recheck_effective_permissions_without_granting_them_to_cashiers(): void
    {
        $manager = $this->actor([P::ArticlesManage]);
        $cashier = $this->actor([P::ArticlesView], 'cajera');
        $assignments = DB::table('rol_permiso')->orderBy('rol_id')->orderBy('permiso_id')->get()->toJson();
        $this->actingAs($manager)->get(route('catalog.categories.index'))->assertOk()
            ->assertSee(route('catalog.units.index'), false);
        $this->actingAs($cashier)->get(route('inicio'))->assertOk()
            ->assertDontSee(route('catalog.categories.index'), false)->assertDontSee(route('catalog.units.index'), false);
        $this->assertSame($assignments, DB::table('rol_permiso')->orderBy('rol_id')->orderBy('permiso_id')->get()->toJson());
        DB::table('rol_permiso')->where('rol_id', $manager->rol_id)->delete();
        $this->actingAs($manager)->get(route('catalog.units.index'))->assertForbidden();
    }

    public function test_new_article_empty_catalogs_are_explained_without_creating_records(): void
    {
        $manager = $this->actor([P::ArticlesManage]);
        $this->actingAs($manager)->get(route('articles.create'))->assertOk()
            ->assertSee('No hay categorías disponibles.')->assertSee('No hay unidades de medida disponibles.')
            ->assertSee(route('catalog.categories.create'), false)->assertSee(route('catalog.units.create'), false);
        $this->assertSame(0, CategoriaArticulo::query()->count());
        $this->assertSame(0, UnidadMedida::query()->count());
        $this->assertSame(0, Articulo::query()->count());
    }

    public function test_reventa_created_from_web_catalogs_preserves_barcode_and_zero_stock(): void
    {
        $manager = $this->actor([P::ArticlesManage, P::ArticlesView]);
        $this->actingAs($manager)->post(route('catalog.categories.store'), $this->payload('categories'))->assertSessionHasNoErrors();
        $this->post(route('catalog.units.store'), $this->payload('units'))->assertSessionHasNoErrors();
        $category = CategoriaArticulo::query()->sole();
        $unit = UnidadMedida::query()->sole();
        $this->post(route('articles.store'), [...$this->articleData($category->id, $unit->id), 'stock_actual' => '999.000'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $article = Articulo::query()->sole();
        $this->assertSame('reventa', $article->tipo_articulo);
        $this->assertSame('0001234567890', $article->codigo_barras);
        $this->assertSame($category->id, $article->categoria_articulo_id);
        $this->assertSame($unit->id, $article->unidad_id);
        $this->assertSame('0.000', $article->stock_actual);
        $this->assertSame('3.000', $article->stock_minimo);
        $this->assertSame(0, DB::table('movimientos_inventario')->count());
        $this->assertSame(1, DB::table('historial_precios_venta')->count());
        $this->get(route('articles.show', $article))->assertOk()->assertSee('Bebidas')->assertSee('0001234567890');
    }

    public function test_new_article_selectors_exclude_inactive_entries(): void
    {
        $manager = $this->actor([P::ArticlesManage]);
        $category = $this->entry('categories', ['nombre' => 'Categoría disponible']);
        $unit = $this->entry('units', ['nombre' => 'Unidad disponible']);
        $this->entry('categories', ['nombre' => 'Categoría oculta', 'activo' => false]);
        $this->entry('units', ['nombre' => 'Unidad oculta', 'activo' => false]);
        $response = $this->actingAs($manager)->get(route('articles.create'))->assertOk();
        $response->assertSee('Categoría disponible')->assertSee('Unidad disponible')->assertDontSee('Categoría oculta')->assertDontSee('Unidad oculta');
        $this->assertContains((string) $category->id, $this->optionValues($response->getContent(), 'categoria_articulo_id'));
        $this->assertContains((string) $unit->id, $this->optionValues($response->getContent(), 'unidad_id'));
    }

    public function test_existing_article_keeps_its_inactive_references_and_can_still_be_edited(): void
    {
        $manager = $this->actor([P::ArticlesManage, P::ArticlesView]);
        $category = $this->entry('categories');
        $unit = $this->entry('units');
        $data = $this->articleData($category->id, $unit->id);
        $article = app(ArticleAdministrationService::class)->create($manager, $data);
        $this->actingAs($manager)->post(route('catalog.categories.deactivate', $category))->assertSessionHasNoErrors();
        $this->post(route('catalog.units.deactivate', $unit))->assertSessionHasNoErrors();
        $this->entry('categories', ['nombre' => 'Otra categoría inactiva', 'activo' => false]);
        $this->entry('units', ['nombre' => 'Otra unidad inactiva', 'activo' => false]);
        $response = $this->get(route('articles.edit', $article))->assertOk()->assertSee('(inactiva, asignada)')
            ->assertDontSee('Otra categoría inactiva')->assertDontSee('Otra unidad inactiva');
        $this->assertContains((string) $category->id, $this->optionValues($response->getContent(), 'categoria_articulo_id', true));
        $this->assertContains((string) $unit->id, $this->optionValues($response->getContent(), 'unidad_id', true));
        $this->put(route('articles.update', $article), [...$data, 'nombre' => 'Jugo actualizado'])->assertSessionHasNoErrors();
        $this->assertSame($category->id, $article->fresh()->categoria_articulo_id);
        $this->assertSame($unit->id, $article->fresh()->unidad_id);
        $this->assertSame('Jugo actualizado', $article->fresh()->nombre);
        $this->assertSame('0.000', $article->fresh()->stock_actual);
        $this->assertSame(0, DB::table('movimientos_inventario')->count());
    }

    #[DataProvider('invalidArticleReferences')]
    public function test_invalid_article_references_are_rejected_and_input_is_retained(string $field, string $state): void
    {
        $manager = $this->actor([P::ArticlesManage]);
        $category = $this->entry('categories');
        $unit = $this->entry('units');
        $data = $this->articleData($category->id, $unit->id);
        $data[$field] = $state === 'missing' ? '' : ($state === 'unknown' ? 999999 : $this->entry($field === 'unidad_id' ? 'units' : 'categories', ['activo' => false])->id);
        $this->actingAs($manager)->from(route('articles.create'))->post(route('articles.store'), $data)
            ->assertSessionHasErrors($field)->assertSessionHasInput('nombre', 'Jugo en lata')->assertSessionHasInput('codigo_barras', '0001234567890');
        $this->get(route('articles.create'))->assertOk()->assertSee('Jugo en lata')->assertSee('0001234567890');
        $this->assertSame(0, Articulo::query()->count());
        $this->assertSame(0, DB::table('movimientos_inventario')->count());
    }

    public static function invalidArticleReferences(): array
    {
        $cases = [];
        foreach (['categoria_articulo_id', 'unidad_id'] as $field) {
            foreach (['missing', 'unknown', 'inactive'] as $state) {
                $cases[] = [$field, $state];
            }
        }
        return $cases;
    }

    public function test_edit_cannot_switch_to_a_different_inactive_category_or_unit(): void
    {
        $manager = $this->actor([P::ArticlesManage]);
        $category = $this->entry('categories');
        $unit = $this->entry('units');
        $data = $this->articleData($category->id, $unit->id);
        $article = app(ArticleAdministrationService::class)->create($manager, $data);
        $otherCategory = $this->entry('categories', ['activo' => false]);
        $otherUnit = $this->entry('units', ['activo' => false]);
        $this->actingAs($manager)->put(route('articles.update', $article), [...$data, 'categoria_articulo_id' => $otherCategory->id, 'unidad_id' => $otherUnit->id])
            ->assertSessionHasErrors(['categoria_articulo_id', 'unidad_id']);
        $this->assertSame($category->id, $article->fresh()->categoria_articulo_id);
        $this->assertSame($unit->id, $article->fresh()->unidad_id);
    }

    #[DataProvider('protectedUnitFields')]
    public function test_referenced_units_cannot_change_quantity_identifiers(string $field, string $value): void
    {
        $manager = $this->actor([P::ArticlesManage]);
        $category = $this->entry('categories');
        $unit = $this->entry('units', $this->payload('units'));
        $article = app(ArticleAdministrationService::class)->create($manager, $this->articleData($category->id, $unit->id));
        $before = $unit->fresh()->getAttributes();
        $this->actingAs($manager)->put(route('catalog.units.update', $unit), [...$this->payload('units'), $field => $value])->assertSessionHasErrors($field);
        $this->assertSame($before, $unit->fresh()->getAttributes());
        $this->assertSame($unit->id, $article->fresh()->unidad_id);
        $this->assertSame('0.000', $article->fresh()->stock_actual);
    }

    public static function protectedUnitFields(): array
    {
        return [['codigo', 'otra_unidad'], ['simbolo', 'g'], ['magnitud', 'masa']];
    }

    public function test_catalog_changes_preserve_historical_sale_snapshots_and_article_references(): void
    {
        $manager = $this->actor([P::ArticlesManage]);
        $category = $this->entry('categories', $this->payload('categories'));
        $unit = $this->entry('units', $this->payload('units'));
        $article = app(ArticleAdministrationService::class)->create($manager, $this->articleData($category->id, $unit->id));
        $register = DB::table('cajas')->insertGetId(['codigo' => 'historica', 'nombre' => 'Caja histórica']);
        $session = DB::table('sesiones_caja')->insertGetId(['caja_id' => $register, 'usuario_apertura_id' => $manager->id, 'monto_inicial' => '0', 'abierta_en' => '2026-09-24 12:00:00-03']);
        $this->createSaleSequence('2026-09-24');
        $sale = $this->createSale($manager->id, $session, (string) Str::uuid());
        $this->createSaleDetail($sale, $article->id, ['unidad_codigo_snapshot' => $unit->codigo, 'unidad_simbolo_snapshot' => $unit->simbolo]);
        $before = DB::table('detalle_ventas')->get()->toJson();
        $this->actingAs($manager)->put(route('catalog.units.update', $unit), [...$this->payload('units'), 'nombre' => 'Unidad corregida'])->assertSessionHasNoErrors();
        $this->put(route('catalog.categories.update', $category), [...$this->payload('categories'), 'nombre' => 'Bebidas envasadas'])->assertSessionHasNoErrors();
        $this->post(route('catalog.categories.deactivate', $category))->assertSessionHasNoErrors();
        $this->post(route('catalog.units.deactivate', $unit))->assertSessionHasNoErrors();
        $this->assertSame($before, DB::table('detalle_ventas')->get()->toJson());
        $this->assertSame($category->id, $article->fresh()->categoria_articulo_id);
        $this->assertSame($unit->id, $article->fresh()->unidad_id);
        $this->assertTrue($article->fresh()->activo);
        $this->assertSame('0.000', $article->fresh()->stock_actual);
        $this->assertSame(0, DB::table('movimientos_inventario')->count());
    }

    public function test_direct_services_validate_permissions_and_catalog_availability(): void
    {
        $outsider = $this->actor([], 'Administrador');
        try {
            app(ArticleCatalogService::class)->saveCategory($outsider, $this->payload('categories'));
            $this->fail('Missing permission was accepted.');
        } catch (AuthorizationException) {
            $this->assertSame(0, CategoriaArticulo::query()->count());
        }
        $manager = $this->actor([P::ArticlesManage]);
        $category = $this->entry('categories', ['activo' => false]);
        $unit = $this->entry('units');
        try {
            app(ArticleAdministrationService::class)->create($manager, $this->articleData($category->id, $unit->id));
            $this->fail('Inactive category was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('categoria_articulo_id', $exception->errors());
            $this->assertSame(0, Articulo::query()->count());
        }
    }

    private function actor(array $permissions, string $name = 'Operador'): User
    {
        $token = strtolower(Str::random(12));
        $role = $this->createRole(['codigo' => 'catalog_'.$token, 'nombre' => $name]);
        foreach ($permissions as $code) {
            $id = DB::table('permisos')->where('codigo', $code->value)->value('id') ?? $this->createPermission(['codigo' => $code->value, 'nombre' => $code->label(), 'modulo' => $code->module()]);
            $this->attachPermission($role, (int) $id);
        }
        return User::query()->findOrFail($this->createUser($role, ['nombre_usuario' => 'catalog_'.$token]));
    }

    private function model(string $kind): string
    {
        return $kind === 'units' ? UnidadMedida::class : CategoriaArticulo::class;
    }

    private function entry(string $kind, array $overrides = []): CategoriaArticulo|UnidadMedida
    {
        $token = strtolower(Str::random(10));
        $values = ['codigo' => 'entry_'.$token, 'nombre' => 'Nombre '.$token, 'activo' => true];
        if ($kind === 'units') {
            $values += ['simbolo' => 'u'.$token, 'magnitud' => 'unidad'];
        }
        $model = $this->model($kind);
        $entry = new $model;
        $entry->forceFill([...$values, ...$overrides])->save();
        return $entry;
    }

    private function payload(string $kind): array
    {
        return $kind === 'units' ? ['codigo' => 'unidad', 'nombre' => 'Unidad', 'simbolo' => 'u', 'magnitud' => 'unidad']
            : ['codigo' => 'bebidas', 'nombre' => 'Bebidas', 'descripcion' => 'Bebidas envasadas'];
    }

    private function articleData(int $category, int $unit): array
    {
        return ['sku' => 'jugo_lata', 'nombre' => 'Jugo en lata', 'tipo_articulo' => 'reventa', 'categoria_articulo_id' => $category, 'unidad_id' => $unit,
            'codigo_barras' => '0001234567890', 'stock_minimo' => '3.000', 'precio_venta' => '1200'];
    }

    private function optionValues(string $html, string $field, bool $selectedOnly = false): array
    {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);
        $values = [];
        foreach ($xpath->query('//select[@name="'.$field.'"]/option'.($selectedOnly ? '[@selected]' : '')) as $option) {
            $values[] = $option->getAttribute('value');
        }
        return $values;
    }
}
