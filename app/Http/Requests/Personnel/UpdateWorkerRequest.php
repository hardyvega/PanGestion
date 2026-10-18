<?php

declare(strict_types=1);

namespace App\Http\Requests\Personnel;

use App\Enums\PermissionCode;
use App\Models\Trabajador;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateWorkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::PersonnelManage);
    }

    public function rules(): array
    {
        $worker = $this->route('trabajador');
        $workerId = $worker instanceof Trabajador ? $worker->getKey() : $worker;

        return [
            'codigo' => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', Rule::unique('trabajadores', 'codigo')->ignore($workerId)],
            'nombres' => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            'fecha_ingreso' => ['required', 'date_format:Y-m-d'],
            'fecha_termino' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_ingreso'],
            'usuario_id' => ['nullable', 'integer', 'exists:usuarios,id', Rule::unique('trabajadores', 'usuario_id')->ignore($workerId)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'codigo' => is_string($this->codigo) ? trim($this->codigo) : $this->codigo,
            'nombres' => is_string($this->nombres) ? trim($this->nombres) : $this->nombres,
            'apellidos' => is_string($this->apellidos) ? trim($this->apellidos) : $this->apellidos,
        ]);
    }
}
