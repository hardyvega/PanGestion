<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryStockMutation;
use App\Enums\InventoryMovementType;
use App\Enums\PermissionCode;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Support\CommercialDate;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CountService
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly InventoryLockManager $lockManager,
        private readonly InventoryStockService $stockService,
        private readonly StockDailyRebuilder $rebuilder,
    ) {
    }

    public function create(
        User $actor,
        string $commandUuid,
        array $lines,
        ?string $observacion = null,
    ): object {
        return DB::transaction(function () use ($actor, $commandUuid, $lines, $observacion): object {
            $this->permissions->authorize($actor, PermissionCode::InventoryCountsManage);
            $commandUuid = $this->normalizeUuid($commandUuid);
            $normalizedLines = $this->normalizeLines($lines);
            $observacion = $this->normalizeObservation($observacion);

            $existing = DB::table('conteos_inventario')
                ->where('clave_idempotencia_creacion', $commandUuid)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $this->assertCompatibleCreate($existing, $normalizedLines, $actor, $observacion);

                return $existing;
            }

            $draft = DB::table('conteos_inventario')
                ->where('estado', 'borrador')
                ->lockForUpdate()
                ->first();

            if ($draft !== null) {
                throw new InventoryDomainException('Ya existe un conteo de inventario en borrador.');
            }

            $articleIds = array_keys($normalizedLines);
            $locked = $this->lockManager->lockArticles($articleIds);
            $units = $this->unitSnapshots($locked);
            $observed = $this->latestMovementIds($articleIds);
            $now = CarbonImmutable::now('UTC');
            $actorId = (int) $actor->getKey();

            $countId = (int) DB::table('conteos_inventario')->insertGetId([
                'usuario_creador_id' => $actorId,
                'usuario_responsable_id' => $actorId,
                'estado' => 'borrador',
                'observacion' => $observacion,
                'clave_idempotencia_creacion' => $commandUuid,
                'clave_idempotencia_confirmacion' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($articleIds as $articleId) {
                $article = $locked->get($articleId);
                $unit = $units[$articleId];

                DB::table('detalle_conteos_inventario')->insert([
                    'conteo_inventario_id' => $countId,
                    'articulo_id' => $articleId,
                    'articulo_sku_snapshot' => $article->getAttribute('sku'),
                    'articulo_nombre_snapshot' => $article->getAttribute('nombre'),
                    'unidad_codigo_snapshot' => $unit->codigo,
                    'unidad_simbolo_snapshot' => $unit->simbolo,
                    'cantidad_contada' => $normalizedLines[$articleId],
                    'contada_en' => $now,
                    'stock_teorico_snapshot' => null,
                    'movimiento_inventario_observado_id' => $observed[$articleId] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return DB::table('conteos_inventario')->where('id', $countId)->first();
        });
    }

    public function confirm(User $actor, int $countId, string $commandUuid): object
    {
        return DB::transaction(function () use ($actor, $countId, $commandUuid): object {
            $this->permissions->authorize($actor, PermissionCode::InventoryCountsConfirm);

            if ($countId <= 0) {
                throw new InvalidArgumentException('El conteo debe tener un ID positivo.');
            }

            $commandUuid = $this->normalizeUuid($commandUuid);
            $count = DB::table('conteos_inventario')
                ->where('id', $countId)
                ->lockForUpdate()
                ->first();

            if ($count === null) {
                throw (new ModelNotFoundException())->setModel('conteos_inventario', [$countId]);
            }

            if ($count->estado === 'confirmado') {
                if ($count->clave_idempotencia_confirmacion === $commandUuid
                    && (int) $count->usuario_confirmador_id === (int) $actor->getKey()) {
                    return $count;
                }

                throw new IdempotencyConflictException('El conteo ya fue confirmado con otra intencion.');
            }

            if ($count->estado !== 'borrador') {
                throw new InventoryDomainException('El conteo no se encuentra en estado borrador.');
            }

            $confirmationCollision = DB::table('conteos_inventario')
                ->where('clave_idempotencia_confirmacion', $commandUuid)
                ->where('id', '<>', $countId)
                ->lockForUpdate()
                ->first();

            if ($confirmationCollision !== null) {
                throw new IdempotencyConflictException('El UUID de confirmacion ya pertenece a otro conteo.');
            }

            if (DB::table('movimientos_inventario')->where('clave_idempotencia', $commandUuid)->exists()) {
                throw new IdempotencyConflictException('El UUID de confirmacion ya pertenece a otro movimiento.');
            }

            $details = DB::table('detalle_conteos_inventario')
                ->where('conteo_inventario_id', $countId)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            if ($details->isEmpty()) {
                throw new InventoryDomainException('El conteo debe tener al menos una linea.');
            }

            $articleIds = $details->pluck('articulo_id')->map(static fn ($id): int => (int) $id)->all();
            sort($articleIds, SORT_NUMERIC);
            $locked = $this->lockManager->lockArticles($articleIds);
            $latest = $this->latestMovementIds($articleIds);

            foreach ($details as $detail) {
                $articleId = (int) $detail->articulo_id;
                $observed = $detail->movimiento_inventario_observado_id === null
                    ? null
                    : (int) $detail->movimiento_inventario_observado_id;
                $current = $latest[$articleId] ?? null;

                if ($observed !== $current) {
                    throw new InventoryDomainException('El conteo esta obsoleto y debe realizarse nuevamente.');
                }
            }

            $now = CarbonImmutable::now('UTC');
            $mutations = [];
            $snapshots = [];

            foreach ($details as $detail) {
                $articleId = (int) $detail->articulo_id;
                $stock = $locked->get($articleId)->getAttribute('stock_actual');

                if (! is_string($stock)) {
                    throw new InventoryDomainException('El stock teorico debe ser decimal.');
                }

                $stock = Decimal::quantity($stock);
                $counted = Decimal::quantity((string) $detail->cantidad_contada);
                $delta = Decimal::subtract($counted, $stock, 3);
                $snapshots[(int) $detail->id] = $stock;

                if (Decimal::isZero($delta)) {
                    continue;
                }

                $mutations[] = new InventoryStockMutation(
                    articleId: $articleId,
                    type: Decimal::sign($delta) > 0
                        ? InventoryMovementType::PositiveCountAdjustment
                        : InventoryMovementType::NegativeCountAdjustment,
                    delta: $delta,
                    position: 1,
                    occurredAt: $now,
                    actorId: (int) $actor->getKey(),
                    authorizerId: null,
                    reason: null,
                    unitCode: $detail->unidad_codigo_snapshot,
                    unitSymbol: $detail->unidad_simbolo_snapshot,
                    saleDetailId: null,
                    receiptDetailId: null,
                    productionDetailId: null,
                    productionConsumptionId: null,
                    wasteId: null,
                    inventoryCountDetailId: (int) $detail->id,
                    reversedMovementId: null,
                );
            }

            $mutatedArticleIds = array_values(array_unique(array_map(
                static fn (InventoryStockMutation $mutation): int => $mutation->articleId,
                $mutations,
            )));
            sort($mutatedArticleIds, SORT_NUMERIC);

            if ($mutations !== []) {
                $mutatedArticles = LockedArticleSet::fromArticles(array_map(
                    static fn (int $articleId) => $locked->get($articleId),
                    $mutatedArticleIds,
                ));
                $simulation = $this->stockService->simulatePlan($mutatedArticles, $commandUuid, $mutations);
                $movements = $this->stockService->applyPlan($simulation);

                if (count($movements) !== count($mutations)) {
                    throw new InventoryDomainException('El conteo debe producir un movimiento por cada diferencia no cero.');
                }
            }

            foreach ($snapshots as $detailId => $stock) {
                $updated = DB::table('detalle_conteos_inventario')
                    ->where('id', $detailId)
                    ->update([
                        'stock_teorico_snapshot' => $stock,
                        'updated_at' => $now,
                    ]);

                if ($updated !== 1) {
                    throw new InventoryDomainException('No se pudo guardar el stock teorico del conteo.');
                }
            }

            $updated = DB::table('conteos_inventario')
                ->where('id', $countId)
                ->where('estado', 'borrador')
                ->update([
                    'estado' => 'confirmado',
                    'usuario_confirmador_id' => (int) $actor->getKey(),
                    'confirmada_en' => $now,
                    'clave_idempotencia_confirmacion' => $commandUuid,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                throw new InventoryDomainException('No se pudo confirmar el conteo.');
            }

            if ($mutatedArticleIds !== []) {
                $this->rebuilder->rebuild($mutatedArticleIds, CommercialDate::fromInstant($now));
            }

            return DB::table('conteos_inventario')->where('id', $countId)->first();
        });
    }

    /** @return array<int, string> */
    private function normalizeLines(array $lines): array
    {
        if ($lines === [] || ! array_is_list($lines)) {
            throw new InvalidArgumentException('El conteo requiere una lista de lineas.');
        }

        $normalized = [];

        foreach ($lines as $line) {
            if (! is_array($line)
                || ! array_key_exists('articleId', $line)
                || ! array_key_exists('countedQuantity', $line)
                || array_diff(array_keys($line), ['articleId', 'countedQuantity']) !== []) {
                throw new InvalidArgumentException('Cada linea solo admite articulo y cantidad contada.');
            }

            $articleId = $line['articleId'];

            if (! is_int($articleId) || $articleId <= 0) {
                throw new InvalidArgumentException('El articulo debe tener un ID entero positivo.');
            }

            if (! is_string($line['countedQuantity'])) {
                throw new InvalidArgumentException('La cantidad contada debe ser decimal string.');
            }

            $quantity = Decimal::quantity($line['countedQuantity']);

            if (Decimal::sign($quantity) < 0) {
                throw new InvalidArgumentException('La cantidad contada no puede ser negativa.');
            }

            if (isset($normalized[$articleId])) {
                throw new InvalidArgumentException('Un articulo solo puede aparecer una vez por conteo.');
            }

            $normalized[$articleId] = $quantity;
        }

        ksort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function normalizeObservation(?string $observacion): ?string
    {
        if ($observacion === null) {
            return null;
        }

        $observacion = trim($observacion);

        if ($observacion === '') {
            throw new InvalidArgumentException('La observacion no puede estar vacia.');
        }

        return $observacion;
    }

    private function normalizeUuid(string $uuid): string
    {
        if (! Str::isUuid($uuid)) {
            throw new InvalidArgumentException('El comando debe tener un UUID valido.');
        }

        return strtolower($uuid);
    }

    /** @param array<int, string> $lines */
    private function assertCompatibleCreate(
        object $existing,
        array $lines,
        User $actor,
        ?string $observacion,
    ): void {
        if ((int) $existing->usuario_creador_id !== (int) $actor->getKey()
            || (int) $existing->usuario_responsable_id !== (int) $actor->getKey()
            || $existing->observacion !== $observacion) {
            throw new IdempotencyConflictException('El UUID de creacion ya fue usado con otra intencion.');
        }

        $details = DB::table('detalle_conteos_inventario')
            ->where('conteo_inventario_id', $existing->id)
            ->orderBy('articulo_id')
            ->lockForUpdate()
            ->get(['articulo_id', 'cantidad_contada']);

        $persisted = [];

        foreach ($details as $detail) {
            $persisted[(int) $detail->articulo_id] = Decimal::quantity((string) $detail->cantidad_contada);
        }

        if (array_keys($persisted) !== array_keys($lines)) {
            throw new IdempotencyConflictException('El UUID de creacion ya fue usado con otros articulos.');
        }

        foreach ($lines as $articleId => $quantity) {
            if (Decimal::compare($persisted[$articleId], $quantity, 3) !== 0) {
                throw new IdempotencyConflictException('El UUID de creacion ya fue usado con otras cantidades.');
            }
        }
    }

    /** @return array<int, object> */
    private function unitSnapshots(LockedArticleSet $articles): array
    {
        $unitIds = [];

        foreach ($articles as $articleId => $article) {
            $unitIds[$articleId] = (int) $article->getAttribute('unidad_id');
        }

        $units = DB::table('unidades_medida')
            ->whereIn('id', array_values($unitIds))
            ->get(['id', 'codigo', 'simbolo'])
            ->keyBy('id');
        $snapshots = [];

        foreach ($unitIds as $articleId => $unitId) {
            $unit = $units->get($unitId);

            if ($unit === null) {
                throw new InventoryDomainException('El articulo requiere una unidad de medida existente.');
            }

            $snapshots[$articleId] = $unit;
        }

        return $snapshots;
    }

    /** @param array<int, int> $articleIds */
    private function latestMovementIds(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }

        $rows = DB::table('movimientos_inventario')
            ->whereIn('articulo_id', $articleIds)
            ->orderBy('articulo_id', 'asc')
            ->orderBy('id', 'desc')
            ->get(['id', 'articulo_id']);
        $latest = [];

        foreach ($rows as $row) {
            $articleId = (int) $row->articulo_id;

            if (! array_key_exists($articleId, $latest)) {
                $latest[$articleId] = (int) $row->id;
            }
        }

        return $latest;
    }
}
