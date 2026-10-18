<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Production;

use App\Data\Production\AnnulProductionCommand;
use App\Data\Production\ConfirmProductionCommand;
use App\Enums\InventoryMovementType;
use App\Enums\PermissionCode;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Inventory\InventoryLedgerWriter;
use App\Services\Inventory\InventoryLockManager;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\StockDailyRebuilder;
use App\Services\Production\ProductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockElevenRecords;
use Tests\Support\CreatesBlockFiveRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\Support\CreatesBlockNineRecords;
use Tests\TestCase;

final class ProductionServiceTest extends TestCase
{
    use CreatesBlockElevenRecords;
    use CreatesBlockFiveRecords;
    use CreatesBlockNineRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private User $actor;

    private int $productionId;

    private int $productId;

    private int $componentId;

    private int $productionDetailId;

    private int $consumptionId;

    private int $roleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleId = $this->createRole(['codigo' => 'produccion_operador']);
        foreach ([PermissionCode::ProductionConfirm, PermissionCode::ProductionAnnul] as $permission) {
            $permissionId = $this->createPermission([
                'codigo' => $permission->value,
                'nombre' => $permission->label(),
                'modulo' => $permission->module(),
            ]);
            $this->attachPermission($this->roleId, $permissionId);
        }

        $this->actor = User::query()->findOrFail($this->createUser($this->roleId, [
            'nombre_usuario' => 'production_service_actor',
        ]));

        $categoryId = $this->createArticleCategory();
        $unitId = $this->createUnitOfMeasure();
        $this->productId = $this->createArticle($categoryId, $unitId, [
            'sku' => 'production_service_product',
            'nombre' => 'Production service product',
            'tipo_articulo' => 'elaborado',
            'stock_actual' => '2.000',
        ]);
        $this->componentId = $this->createArticle($categoryId, $unitId, [
            'sku' => 'production_service_component',
            'nombre' => 'Production service component',
            'tipo_articulo' => 'materia_prima',
            'stock_actual' => '10.000',
        ]);

        $this->createInventoryMovement(
            $this->productId,
            (int) $this->actor->getKey(),
            InventoryMovementType::InitialBalance->value,
            'a1000000-0000-4000-8000-000000000001',
            [
                'usuario_autorizador_id' => (int) $this->actor->getKey(),
                'cantidad_delta' => '2.000',
                'stock_anterior' => '0.000',
                'stock_posterior' => '2.000',
                'motivo' => 'Saldo inicial de prueba',
            ],
        );
        $this->createInventoryMovement(
            $this->componentId,
            (int) $this->actor->getKey(),
            InventoryMovementType::InitialBalance->value,
            'a1000000-0000-4000-8000-000000000002',
            [
                'usuario_autorizador_id' => (int) $this->actor->getKey(),
                'cantidad_delta' => '10.000',
                'stock_anterior' => '0.000',
                'stock_posterior' => '10.000',
                'motivo' => 'Saldo inicial de prueba',
            ],
        );

        $recipeId = $this->createRecipe($this->productId, ['activa' => true]);
        $recipeDetailId = $this->createRecipeDetail($recipeId, $this->componentId, ['cantidad' => '1.000']);
        $this->productionId = $this->createProduction(
            $recipeId,
            (int) $this->actor->getKey(),
            (int) $this->actor->getKey(),
        );
        $this->productionDetailId = $this->createProductionDetail($this->productionId, $this->productId, [
            'cantidad_real' => '3.000',
        ]);
        $this->consumptionId = $this->createProductionConsumption(
            $this->productionId,
            $this->componentId,
            $recipeDetailId,
            '1.000',
            ['cantidad_real' => '2.000'],
        );
    }

    public function test_confirmation_updates_stock_ledger_and_daily_projection_atomically(): void
    {
        $production = $this->service()->confirm(
            $this->actor,
            new ConfirmProductionCommand($this->productionId, 'a2000000-0000-4000-8000-000000000001'),
        );

        $this->assertSame('confirmada', $production->estado);
        $this->assertSame('confirmada', DB::table('producciones')->where('id', $this->productionId)->value('estado'));
        $this->assertSame('5.000', (string) DB::table('articulos')->where('id', $this->productId)->value('stock_actual'));
        $this->assertSame('8.000', (string) DB::table('articulos')->where('id', $this->componentId)->value('stock_actual'));

        $movements = DB::table('movimientos_inventario')
            ->where('clave_idempotencia', 'a2000000-0000-4000-8000-000000000001')
            ->orderBy('secuencia_idempotencia')
            ->get();

        $this->assertSame([
            InventoryMovementType::ProductionConsumption->value,
            InventoryMovementType::ProductionInput->value,
        ], $movements->pluck('tipo_movimiento')->sort()->values()->all());
        $this->assertSame(1, $movements->where('detalle_produccion_id', $this->productionDetailId)->count());
        $this->assertSame(1, $movements->where('consumo_produccion_id', $this->consumptionId)->count());
        $this->assertDatabaseHas('movimientos_inventario', [
            'detalle_produccion_id' => $this->productionDetailId,
            'tipo_movimiento' => InventoryMovementType::ProductionInput->value,
            'cantidad_delta' => '3.000',
            'stock_anterior' => '2.000',
            'stock_posterior' => '5.000',
        ]);
        $this->assertDatabaseHas('movimientos_inventario', [
            'consumo_produccion_id' => $this->consumptionId,
            'tipo_movimiento' => InventoryMovementType::ProductionConsumption->value,
            'cantidad_delta' => '-2.000',
            'stock_anterior' => '10.000',
            'stock_posterior' => '8.000',
        ]);

        $this->assertSame('5.000', (string) DB::table('stock_diario')
            ->where('articulo_id', $this->productId)
            ->orderByDesc('fecha_comercial')
            ->value('stock_cierre'));
        $this->assertSame('8.000', (string) DB::table('stock_diario')
            ->where('articulo_id', $this->componentId)
            ->orderByDesc('fecha_comercial')
            ->value('stock_cierre'));
    }

    public function test_confirmation_retry_does_not_duplicate_inventory(): void
    {
        $command = new ConfirmProductionCommand($this->productionId, 'a2000000-0000-4000-8000-000000000002');
        $this->service()->confirm($this->actor, $command);
        $this->service()->confirm($this->actor, $command);

        $this->assertSame('5.000', (string) DB::table('articulos')->where('id', $this->productId)->value('stock_actual'));
        $this->assertSame('8.000', (string) DB::table('articulos')->where('id', $this->componentId)->value('stock_actual'));
        $this->assertSame(2, DB::table('movimientos_inventario')
            ->where('clave_idempotencia', $command->commandUuid)
            ->count());
    }

    public function test_insufficient_component_stock_rolls_back_everything(): void
    {
        DB::table('articulos')->where('id', $this->componentId)->update(['stock_actual' => '1.000']);

        $this->expectException(InsufficientStockException::class);
        $this->service()->confirm(
            $this->actor,
            new ConfirmProductionCommand($this->productionId, 'a2000000-0000-4000-8000-000000000003'),
        );

        $this->assertSame('borrador', DB::table('producciones')->where('id', $this->productionId)->value('estado'));
        $this->assertSame(2, DB::table('movimientos_inventario')->count());
        $this->assertSame('1.000', (string) DB::table('articulos')->where('id', $this->componentId)->value('stock_actual'));
    }

    public function test_inactive_article_rejects_confirmation_without_effects(): void
    {
        DB::table('articulos')->where('id', $this->componentId)->update(['activo' => false]);

        $this->expectException(InventoryDomainException::class);
        $this->service()->confirm(
            $this->actor,
            new ConfirmProductionCommand($this->productionId, 'a2000000-0000-4000-8000-000000000004'),
        );

        $this->assertSame('borrador', DB::table('producciones')->where('id', $this->productionId)->value('estado'));
        $this->assertSame(2, DB::table('movimientos_inventario')->count());
    }

    public function test_annulment_reverses_each_original_and_retry_is_idempotent(): void
    {
        $confirmCommand = new ConfirmProductionCommand($this->productionId, 'a2000000-0000-4000-8000-000000000005');
        $this->service()->confirm($this->actor, $confirmCommand);
        $annulCommand = new AnnulProductionCommand(
            $this->productionId,
            'a2000000-0000-4000-8000-000000000006',
            'Correccion de produccion',
        );

        $production = $this->service()->annul($this->actor, $annulCommand);
        $this->service()->annul($this->actor, $annulCommand);

        $this->assertSame('anulada', $production->estado);
        $this->assertSame('2.000', (string) DB::table('articulos')->where('id', $this->productId)->value('stock_actual'));
        $this->assertSame('10.000', (string) DB::table('articulos')->where('id', $this->componentId)->value('stock_actual'));
        $this->assertSame(2, DB::table('movimientos_inventario')
            ->where('clave_idempotencia', $annulCommand->commandUuid)
            ->count());
        $this->assertSame(1, DB::table('movimientos_inventario')
            ->where('tipo_movimiento', InventoryMovementType::ProductionInputReversal->value)
            ->where('movimiento_revertido_id', DB::table('movimientos_inventario')
                ->where('detalle_produccion_id', $this->productionDetailId)
                ->where('tipo_movimiento', InventoryMovementType::ProductionInput->value)
                ->value('id'))
            ->count());
        $this->assertSame('2.000', (string) DB::table('stock_diario')
            ->where('articulo_id', $this->productId)
            ->orderByDesc('fecha_comercial')
            ->value('stock_cierre'));
    }

    public function test_annulment_rejects_when_the_produced_stock_is_no_longer_available(): void
    {
        $this->service()->confirm(
            $this->actor,
            new ConfirmProductionCommand($this->productionId, 'a2000000-0000-4000-8000-000000000007'),
        );
        DB::table('articulos')->where('id', $this->productId)->update(['stock_actual' => '1.000']);

        $this->expectException(InsufficientStockException::class);
        $this->service()->annul(
            $this->actor,
            new AnnulProductionCommand(
                $this->productionId,
                'a2000000-0000-4000-8000-000000000008',
                'No retirar stock inexistente',
            ),
        );

        $this->assertSame('confirmada', DB::table('producciones')->where('id', $this->productionId)->value('estado'));
        $this->assertSame(0, DB::table('movimientos_inventario')
            ->whereIn('tipo_movimiento', [
                InventoryMovementType::ProductionInputReversal->value,
                InventoryMovementType::ProductionConsumptionReversal->value,
            ])
            ->count());
    }

    private function service(): ProductionService
    {
        return new ProductionService(
            new EffectivePermissionResolver(),
            new InventoryLockManager(),
            new InventoryStockService(new InventoryLedgerWriter()),
            new StockDailyRebuilder(),
        );
    }
}
