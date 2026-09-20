<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait CreatesBlockTwoRecords
{
    protected function unitOfMeasureData(array $overrides = []): array
    {
        return array_merge([
            'codigo' => 'kg',
            'nombre' => 'Kilogramo',
            'simbolo' => 'kg',
            'magnitud' => 'masa',
        ], $overrides);
    }

    protected function createUnitOfMeasure(array $overrides = []): int
    {
        return (int) DB::table('unidades_medida')->insertGetId($this->unitOfMeasureData($overrides));
    }

    protected function articleCategoryData(array $overrides = []): array
    {
        return array_merge([
            'codigo' => 'panes',
            'nombre' => 'Panes',
        ], $overrides);
    }

    protected function createArticleCategory(array $overrides = []): int
    {
        return (int) DB::table('categorias_articulo')->insertGetId($this->articleCategoryData($overrides));
    }

    protected function paymentMethodData(array $overrides = []): array
    {
        return array_merge([
            'codigo' => 'pago_prueba',
            'nombre' => 'Pago de prueba',
        ], $overrides);
    }

    protected function createPaymentMethod(array $overrides = []): int
    {
        return (int) DB::table('metodos_pago')->insertGetId($this->paymentMethodData($overrides));
    }

    protected function cashRegisterData(array $overrides = []): array
    {
        return array_merge([
            'codigo' => 'caja_principal',
            'nombre' => 'Caja principal',
        ], $overrides);
    }

    protected function createCashRegister(array $overrides = []): int
    {
        return (int) DB::table('cajas')->insertGetId($this->cashRegisterData($overrides));
    }
}
