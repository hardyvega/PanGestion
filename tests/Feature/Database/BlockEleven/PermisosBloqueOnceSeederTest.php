<?php

declare(strict_types=1);

namespace Tests\Feature\Database\BlockEleven;

use Carbon\CarbonImmutable;
use Database\Seeders\PermisosBloqueOnceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PermisosBloqueOnceSeederTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSION_CODES = [
        'articulos.ver',
        'articulos.gestionar',
        'proveedores.ver',
        'proveedores.gestionar',
        'inventario.costos.ver',
        'recetas.ver',
        'recetas.gestionar',
        'inventario.recepciones.ver',
        'inventario.recepciones.crear',
        'inventario.recepciones.editar',
        'inventario.recepciones.confirmar',
        'inventario.recepciones.anular',
        'inventario.recepciones.origen_otro',
        'inventario.recepciones.retroactivas',
        'inventario.costos.registrar',
        'inventario.saldo_inicial.ejecutar',
        'inventario.ajustes.entrada',
        'inventario.ajustes.salida',
        'inventario.ajustes.retroactivos',
        'inventario.conteos.gestionar',
        'inventario.conteos.confirmar',
        'inventario.mermas.registrar',
        'inventario.mermas.anular',
        'inventario.mermas.retroactivas',
        'inventario.movimientos.ver',
        'inventario.stock_diario.recalcular',
        'ventas.crear',
        'ventas.anular',
        'pedidos.crear',
        'pedidos.gestionar',
        'pedidos.cancelar',
        'pedidos.entregar',
        'pedidos.cobrar',
        'caja.movimientos.ver',
        'caja.sesiones.abrir',
        'caja.sesiones.cerrar',
        'caja.sesiones.ver',
        'caja.movimientos.gestionar',
        'produccion.confirmar',
        'produccion.anular',
        'produccion.ver',
        'produccion.gestionar',
    ];

    public function test_first_run_creates_the_exact_active_catalog_with_complete_metadata(): void
    {
        $this->seed(PermisosBloqueOnceSeeder::class);

        $rows = DB::table('permisos')->orderBy('codigo')->get();
        $expectedCodes = self::PERMISSION_CODES;
        sort($expectedCodes);

        $this->assertCount(42, $rows);
        $this->assertSame($expectedCodes, $rows->pluck('codigo')->all());

        foreach ($rows as $row) {
            $this->assertSame(explode('.', $row->codigo, 2)[0], $row->modulo);
            $this->assertNotSame('', trim($row->nombre));
            $this->assertNotSame('', trim((string) $row->descripcion));
            $this->assertTrue($this->databaseBoolean($row->activo));
        }

        $this->assertSame(0, DB::table('rol_permiso')->count());
    }

    public function test_second_run_is_idempotent_and_preserves_existing_ids(): void
    {
        $this->seed(PermisosBloqueOnceSeeder::class);
        $firstIds = DB::table('permisos')->orderBy('codigo')->pluck('id', 'codigo')->all();

        $this->seed(PermisosBloqueOnceSeeder::class);
        $secondIds = DB::table('permisos')->orderBy('codigo')->pluck('id', 'codigo')->all();

        $this->assertCount(42, $secondIds);
        $this->assertSame($firstIds, $secondIds);
        $this->assertSame(42, DB::table('permisos')->distinct()->count('codigo'));
    }

    public function test_existing_administrative_customizations_and_timestamps_are_preserved(): void
    {
        $createdAt = CarbonImmutable::parse('2026-01-10 12:00:00', 'UTC');
        $updatedAt = CarbonImmutable::parse('2026-02-11 13:30:00', 'UTC');
        $id = (int) DB::table('permisos')->insertGetId([
            'codigo' => 'inventario.recepciones.ver',
            'nombre' => 'Consulta personalizada',
            'modulo' => 'inventario',
            'descripcion' => 'Descripcion administrada localmente',
            'activo' => false,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);
        $before = DB::table('permisos')->where('id', $id)->first();

        $this->seed(PermisosBloqueOnceSeeder::class);

        $after = DB::table('permisos')->where('id', $id)->first();
        $this->assertNotNull($before);
        $this->assertNotNull($after);
        $this->assertSame((array) $before, (array) $after);
        $this->assertSame(42, DB::table('permisos')->count());
    }

    public function test_external_permission_is_neither_changed_nor_deleted(): void
    {
        $id = (int) DB::table('permisos')->insertGetId([
            'codigo' => 'reportes.operativos.ver',
            'nombre' => 'Ver reportes operativos',
            'modulo' => 'reportes',
            'descripcion' => 'Permiso externo al catalogo de inventario',
            'activo' => false,
        ]);
        $before = DB::table('permisos')->where('id', $id)->first();

        $this->seed(PermisosBloqueOnceSeeder::class);

        $after = DB::table('permisos')->where('id', $id)->first();
        $this->assertNotNull($before);
        $this->assertNotNull($after);
        $this->assertSame((array) $before, (array) $after);
        $this->assertSame(43, DB::table('permisos')->count());
    }

    public function test_seeder_does_not_assign_permissions_to_named_roles(): void
    {
        foreach (['dueno', 'admin', 'owner'] as $code) {
            DB::table('roles')->insert([
                'codigo' => $code,
                'nombre' => ucfirst($code),
                'descripcion' => null,
                'activo' => true,
            ]);
        }

        $this->seed(PermisosBloqueOnceSeeder::class);

        $this->assertSame(3, DB::table('roles')->count());
        $this->assertSame(42, DB::table('permisos')->count());
        $this->assertSame(0, DB::table('rol_permiso')->count());
    }

    public function test_incompatible_existing_module_is_rejected_without_partial_inserts(): void
    {
        $code = 'inventario.recepciones.ver';
        $id = (int) DB::table('permisos')->insertGetId([
            'codigo' => $code,
            'nombre' => 'Permiso incompatible',
            'modulo' => 'ventas',
            'descripcion' => 'Modulo estable incompatible',
            'activo' => true,
        ]);
        $before = DB::table('permisos')->where('id', $id)->first();

        try {
            $this->seed(PermisosBloqueOnceSeeder::class);
            $this->fail('The seeder accepted an incompatible stable module.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($code, $exception->getMessage());
        }

        $after = DB::table('permisos')->where('id', $id)->first();
        $this->assertNotNull($before);
        $this->assertNotNull($after);
        $this->assertSame((array) $before, (array) $after);
        $this->assertSame(1, DB::table('permisos')->count());
    }

    private function databaseBoolean(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 't'
            || $value === 'true';
    }
}
