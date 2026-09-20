<?php

namespace Tests\Feature\Database\BlockTwo;

use Database\Seeders\MetodoPagoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class MetodoPagoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_run_creates_exactly_the_three_approved_payment_methods(): void
    {
        $this->seed(MetodoPagoSeeder::class);

        $methods = DB::table('metodos_pago')
            ->orderBy('orden_presentacion')
            ->get()
            ->map(fn (object $method): array => $this->methodValues($method))
            ->all();

        $this->assertSame([
            [
                'codigo' => 'efectivo',
                'nombre' => 'Efectivo',
                'afecta_efectivo' => true,
                'orden_presentacion' => 10,
                'activo' => true,
            ],
            [
                'codigo' => 'debito',
                'nombre' => 'Débito',
                'afecta_efectivo' => false,
                'orden_presentacion' => 20,
                'activo' => true,
            ],
            [
                'codigo' => 'credito',
                'nombre' => 'Crédito',
                'afecta_efectivo' => false,
                'orden_presentacion' => 30,
                'activo' => true,
            ],
        ], $methods);
        $this->assertSame(3, DB::table('metodos_pago')->count());
    }

    public function test_second_run_is_idempotent_and_keeps_the_same_records(): void
    {
        $this->seed(MetodoPagoSeeder::class);

        $before = DB::table('metodos_pago')
            ->orderBy('codigo')
            ->get()
            ->map(static fn (object $method): array => (array) $method)
            ->all();

        $this->seed(MetodoPagoSeeder::class);

        $after = DB::table('metodos_pago')
            ->orderBy('codigo')
            ->get()
            ->map(static fn (object $method): array => (array) $method)
            ->all();

        $this->assertCount(3, $after);
        $this->assertSame($before, $after);
    }

    public function test_existing_valid_method_preserves_customized_fields(): void
    {
        $existingId = (int) DB::table('metodos_pago')->insertGetId([
            'codigo' => 'debito',
            'nombre' => 'Tarjeta local',
            'afecta_efectivo' => false,
            'orden_presentacion' => 75,
            'activo' => false,
        ]);
        $before = DB::table('metodos_pago')->where('id', $existingId)->first();

        $this->seed(MetodoPagoSeeder::class);

        $after = DB::table('metodos_pago')->where('codigo', 'debito')->first();

        $this->assertNotNull($before);
        $this->assertNotNull($after);
        $this->assertSame($existingId, (int) $after->id);
        $this->assertSame('Tarjeta local', $after->nombre);
        $this->assertFalse($this->databaseBoolean($after->afecta_efectivo));
        $this->assertSame(75, (int) $after->orden_presentacion);
        $this->assertFalse($this->databaseBoolean($after->activo));
        $this->assertSame($before->created_at, $after->created_at);
        $this->assertSame($before->updated_at, $after->updated_at);
        $this->assertSame(3, DB::table('metodos_pago')->count());
        $this->assertSame(
            ['credito', 'debito', 'efectivo'],
            DB::table('metodos_pago')->orderBy('codigo')->pluck('codigo')->all(),
        );
    }

    #[DataProvider('semanticMismatches')]
    public function test_semantic_mismatch_is_rejected_before_inserting_missing_methods(
        string $codigo,
        string $nombre,
        bool $afectaEfectivo,
        int $orden,
    ): void {
        $existingId = (int) DB::table('metodos_pago')->insertGetId([
            'codigo' => $codigo,
            'nombre' => $nombre,
            'afecta_efectivo' => $afectaEfectivo,
            'orden_presentacion' => $orden,
            'activo' => true,
        ]);

        try {
            $this->seed(MetodoPagoSeeder::class);
            $this->fail('The seeder accepted an invalid payment-method semantic configuration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("[{$codigo}]", $exception->getMessage());
        }

        $this->assertSame(1, DB::table('metodos_pago')->count());
        $this->assertSame($existingId, (int) DB::table('metodos_pago')->value('id'));
        $this->assertSame([$codigo], DB::table('metodos_pago')->pluck('codigo')->all());
    }

    public static function semanticMismatches(): array
    {
        return [
            'cash marked as non-cash' => ['efectivo', 'Efectivo', false, 10],
            'debit marked as cash' => ['debito', 'Débito', true, 20],
            'credit marked as cash' => ['credito', 'Crédito', true, 30],
        ];
    }

    public function test_conflicting_default_name_is_rejected_without_partial_inserts(): void
    {
        $existingId = (int) DB::table('metodos_pago')->insertGetId([
            'codigo' => 'pago_alterno',
            'nombre' => 'EFECTIVO',
            'afecta_efectivo' => false,
            'orden_presentacion' => 5,
            'activo' => true,
        ]);
        $before = DB::table('metodos_pago')->where('id', $existingId)->first();

        try {
            $this->seed(MetodoPagoSeeder::class);
            $this->fail('The seeder accepted a conflicting official payment-method name.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('[efectivo]', $exception->getMessage());
        }

        $after = DB::table('metodos_pago')->where('id', $existingId)->first();

        $this->assertNotNull($before);
        $this->assertNotNull($after);
        $this->assertSame((array) $before, (array) $after);
        $this->assertSame(1, DB::table('metodos_pago')->count());
        $this->assertSame(['pago_alterno'], DB::table('metodos_pago')->pluck('codigo')->all());
    }

    private function methodValues(object $method): array
    {
        return [
            'codigo' => $method->codigo,
            'nombre' => $method->nombre,
            'afecta_efectivo' => $this->databaseBoolean($method->afecta_efectivo),
            'orden_presentacion' => (int) $method->orden_presentacion,
            'activo' => $this->databaseBoolean($method->activo),
        ];
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
