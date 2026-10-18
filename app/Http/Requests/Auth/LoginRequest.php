<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombre_usuario' => trim((string) $this->input('nombre_usuario', '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'nombre_usuario' => ['bail', 'required', 'string', 'max:50'],
            'password' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'nombre_usuario' => 'nombre de usuario',
            'password' => 'contraseña',
        ];
    }

    public function normalizedUsername(): string
    {
        return Str::lower((string) $this->validated('nombre_usuario'));
    }

    public function throttleKey(): string
    {
        return $this->normalizedUsername().'|'.$this->ip();
    }
}
