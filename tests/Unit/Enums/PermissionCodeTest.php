<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\PermissionCode;
use PHPUnit\Framework\TestCase;

final class PermissionCodeTest extends TestCase
{
    private const VALUES = [
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
        'caja.sesiones.ver',
        'caja.sesiones.abrir',
        'caja.sesiones.cerrar',
        'caja.fondos.autorizar',
        'caja.movimientos.ver',
        'caja.movimientos.gestionar',
        'produccion.confirmar',
        'produccion.anular',
        'produccion.ver',
        'produccion.gestionar',
        'personal.ver',
        'personal.gestionar',
        'personal.tarifas.ver',
        'personal.tarifas.gestionar',
        'personal.pago_hora.ver',
        'asistencia.marcar',
        'asistencia.gestionar',
        'usuarios.ver',
        'usuarios.gestionar',
        'roles.ver',
        'roles.gestionar',
        'permisos.ver',
        'roles.permisos.gestionar',
    ];

    private const ELEVATED = [
        'caja.fondos.autorizar',
        'inventario.recepciones.origen_otro',
        'inventario.recepciones.retroactivas',
        'inventario.saldo_inicial.ejecutar',
        'inventario.ajustes.retroactivos',
        'inventario.mermas.retroactivas',
        'inventario.stock_diario.recalcular',
        'personal.gestionar',
        'personal.tarifas.ver',
        'personal.tarifas.gestionar',
        'personal.pago_hora.ver',
        'asistencia.gestionar',
    ];

    public function test_enum_contains_exactly_the_fifty_six_approved_codes(): void
    {
        $actual = array_map(
            static fn (PermissionCode $permission): string => $permission->value,
            PermissionCode::cases(),
        );
        $expected = self::VALUES;
        sort($actual);
        sort($expected);

        $this->assertSame($expected, $actual);
        $this->assertCount(56, $actual);
    }

    public function test_every_permission_round_trips_its_backed_code(): void
    {
        foreach (self::VALUES as $code) {
            $this->assertSame($code, PermissionCode::from($code)->value);
        }
    }

    public function test_every_permission_exposes_coherent_nonempty_metadata(): void
    {
        foreach (PermissionCode::cases() as $permission) {
            $expectedModule = explode('.', $permission->value, 2)[0];

            $this->assertSame($expectedModule, $permission->module(), $permission->value);
            $this->assertNotSame('', trim($permission->label()), $permission->value);
            $this->assertNotSame('', trim($permission->description()), $permission->value);
            $this->assertContains($permission->level(), ['ordinario', 'elevado'], $permission->value);
        }
    }

    public function test_permission_levels_are_exactly_forty_four_ordinary_and_twelve_elevated(): void
    {
        $ordinary = [];
        $elevated = [];

        foreach (PermissionCode::cases() as $permission) {
            match ($permission->level()) {
                'ordinario' => $ordinary[] = $permission->value,
                'elevado' => $elevated[] = $permission->value,
            };
        }

        $expectedElevated = self::ELEVATED;
        sort($elevated);
        sort($expectedElevated);

        $this->assertCount(44, $ordinary);
        $this->assertCount(12, $elevated);
        $this->assertSame($expectedElevated, $elevated);
    }
}
