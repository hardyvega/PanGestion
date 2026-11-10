<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

final class UniqueCatalogValue implements ValidationRule
{
    public function __construct(private readonly string $table, private readonly string $column, private readonly ?int $ignore = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $column = DB::connection()->getQueryGrammar()->wrap($this->column);
        $exists = DB::table($this->table)->whereRaw('lower('.$column.') = lower(?)', [trim($value)])
            ->when($this->ignore !== null, fn ($query) => $query->where('id', '<>', $this->ignore))->exists();

        if ($exists) {
            $label = ['codigo' => 'código', 'nombre' => 'nombre', 'simbolo' => 'símbolo'][$this->column];
            $fail('Ya existe un registro con este '.$label.'.');
        }
    }
}
