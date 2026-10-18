<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\CorrelativoVentaDiario;
use App\Models\Caja;
use App\Models\Cliente;
use App\Models\DetallePedido;
use App\Models\DetalleVenta;
use App\Models\MetodoPago;
use App\Models\MovimientoCaja;
use App\Models\PagoPedido;
use App\Models\PagoVenta;
use App\Models\Pedido;
use App\Models\SesionCaja;
use App\Models\TransicionPedido;
use App\Models\Venta;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Enums\SaleStatus;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

final class SalesOrderModelsTest extends TestCase
{
    public function test_models_map_to_the_authorized_tables(): void
    {
        $expected = [
            Cliente::class => 'clientes',
            Caja::class => 'cajas',
            MetodoPago::class => 'metodos_pago',
            Venta::class => 'ventas',
            DetalleVenta::class => 'detalle_ventas',
            PagoVenta::class => 'pagos_venta',
            Pedido::class => 'pedidos',
            DetallePedido::class => 'detalle_pedidos',
            PagoPedido::class => 'pagos_pedido',
            CorrelativoVentaDiario::class => 'correlativos_venta_diarios',
            SesionCaja::class => 'sesiones_caja',
            MovimientoCaja::class => 'movimientos_caja',
            TransicionPedido::class => 'transiciones_pedido',
        ];

        foreach ($expected as $modelClass => $table) {
            $this->assertSame($table, (new $modelClass)->getTable(), $modelClass);
            $this->assertSame(['*'], (new $modelClass)->getGuarded(), $modelClass);
        }
    }

    public function test_expected_relations_are_declared_without_querying_the_database(): void
    {
        $relations = [
            [Cliente::class, 'pedidos'], [Cliente::class, 'ventas'],
            [Caja::class, 'sesiones'],
            [MetodoPago::class, 'pagosVenta'], [MetodoPago::class, 'pagosPedido'],
            [Venta::class, 'usuario'], [Venta::class, 'pedido'], [Venta::class, 'sesionCaja'], [Venta::class, 'cliente'],
            [Venta::class, 'usuarioAnulador'], [Venta::class, 'detalles'], [Venta::class, 'pagos'],
            [DetalleVenta::class, 'venta'], [DetalleVenta::class, 'articulo'], [DetalleVenta::class, 'movimientosInventario'],
            [PagoVenta::class, 'venta'], [PagoVenta::class, 'sesionCaja'], [PagoVenta::class, 'usuario'], [PagoVenta::class, 'metodoPago'], [PagoVenta::class, 'movimientoCaja'],
            [Pedido::class, 'usuarioCreador'], [Pedido::class, 'cliente'], [Pedido::class, 'detalles'], [Pedido::class, 'pagos'], [Pedido::class, 'venta'], [Pedido::class, 'transiciones'],
            [DetallePedido::class, 'pedido'], [DetallePedido::class, 'articulo'],
            [PagoPedido::class, 'pedido'], [PagoPedido::class, 'sesionCaja'], [PagoPedido::class, 'usuario'], [PagoPedido::class, 'metodoPago'], [PagoPedido::class, 'movimientoCaja'],
            [SesionCaja::class, 'caja'], [SesionCaja::class, 'usuarioApertura'], [SesionCaja::class, 'usuarioCierre'], [SesionCaja::class, 'ventas'], [SesionCaja::class, 'pagosVenta'], [SesionCaja::class, 'pagosPedido'], [SesionCaja::class, 'movimientos'],
            [MovimientoCaja::class, 'sesionCaja'], [MovimientoCaja::class, 'usuario'], [MovimientoCaja::class, 'pagoVenta'], [MovimientoCaja::class, 'pagoPedido'],
            [TransicionPedido::class, 'pedido'], [TransicionPedido::class, 'usuario'],
        ];

        foreach ($relations as [$modelClass, $relation]) {
            $reflection = new ReflectionClass($modelClass);
            $this->assertTrue($reflection->hasMethod($relation), $modelClass.'::'.$relation);

            $method = $reflection->getMethod($relation);
            $returnType = $method->getReturnType();

            $this->assertSame(0, $method->getNumberOfParameters(), $modelClass.'::'.$relation);
            $this->assertInstanceOf(ReflectionNamedType::class, $returnType, $modelClass.'::'.$relation);
            $this->assertTrue(
                is_a($returnType->getName(), Relation::class, true),
                $modelClass.'::'.$relation,
            );
        }
    }

    public function test_keyless_daily_counter_and_non_updated_operational_records_are_configured(): void
    {
        $counter = new CorrelativoVentaDiario;

        $this->assertSame('fecha_comercial', $counter->getKeyName());
        $this->assertFalse($counter->getIncrementing());
        $this->assertSame('string', $counter->getKeyType());
        foreach ([
            MovimientoCaja::class,
            PagoVenta::class,
            PagoPedido::class,
            DetalleVenta::class,
            TransicionPedido::class,
        ] as $modelClass) {
            $reflection = new ReflectionClass($modelClass);

            $this->assertSame('created_at', $reflection->getConstant('CREATED_AT'), $modelClass);
            $this->assertNull($reflection->getConstant('UPDATED_AT'), $modelClass);
        }
    }

    public function test_critical_casts_preserve_enum_and_decimal_semantics(): void
    {
        $this->assertSame(SaleStatus::class, (new Venta)->getCasts()['estado']);
        $this->assertSame('decimal:2', (new Venta)->getCasts()['total']);
        $this->assertSame('decimal:3', (new DetalleVenta)->getCasts()['cantidad']);
        $this->assertSame('decimal:2', (new DetalleVenta)->getCasts()['subtotal']);
        $this->assertSame(PaymentType::class, (new PagoVenta)->getCasts()['tipo']);
        $this->assertSame(OrderStatus::class, (new Pedido)->getCasts()['estado']);
        $this->assertSame('decimal:3', (new DetallePedido)->getCasts()['cantidad']);
        $this->assertSame(PaymentType::class, (new PagoPedido)->getCasts()['tipo']);
        $this->assertSame('integer', (new CorrelativoVentaDiario)->getCasts()['ultimo_numero']);
        $this->assertSame('decimal:2', (new SesionCaja)->getCasts()['monto_inicial']);
        $this->assertSame('boolean', (new Cliente)->getCasts()['activo']);
        $this->assertSame('boolean', (new Caja)->getCasts()['activo']);
        $this->assertSame('boolean', (new MetodoPago)->getCasts()['afecta_efectivo']);
        $this->assertSame('integer', (new MetodoPago)->getCasts()['orden_presentacion']);
        $this->assertSame('boolean', (new MetodoPago)->getCasts()['activo']);
    }

    public function test_catalog_and_client_models_use_integer_keys_and_timestamps(): void
    {
        foreach ([Cliente::class, Caja::class, MetodoPago::class] as $modelClass) {
            $model = new $modelClass;

            $this->assertSame('id', $model->getKeyName(), $modelClass);
            $this->assertTrue($model->getIncrementing(), $modelClass);
            $this->assertSame('int', $model->getKeyType(), $modelClass);
            $this->assertTrue($model->usesTimestamps(), $modelClass);
        }
    }

    public function test_client_sensitive_columns_are_hidden_from_serialization(): void
    {
        $model = new Cliente;

        $this->assertContains('telefono_cifrado', $model->getHidden());
        $this->assertContains('telefono_busqueda_hash', $model->getHidden());
    }
}
