<?php

declare(strict_types=1);

namespace App\Services\Administration;

use App\Enums\PermissionCode;
use App\Models\Permiso;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;

final class PermissionAdministrationService
{
    public function __construct(private readonly EffectivePermissionResolver $permissions)
    {
    }

    public function list(User $actor, string $term = '', string $module = '')
    {
        $this->permissions->authorize($actor, PermissionCode::PermissionsView);

        return Permiso::query()
            ->withCount('roles')
            ->when($term !== '', static function ($query) use ($term): void {
                $escaped = addcslashes($term, '\\%_');
                $query->where(static function ($nested) use ($escaped): void {
                    $nested->where('codigo', 'ilike', '%'.$escaped.'%')
                        ->orWhere('nombre', 'ilike', '%'.$escaped.'%');
                });
            })
            ->when($module !== '', static fn ($query) => $query->where('modulo', $module))
            ->orderBy('modulo')->orderBy('codigo')
            ->paginate(20)
            ->withQueryString();
    }

    public function modules(User $actor): array
    {
        $this->permissions->authorize($actor, PermissionCode::PermissionsView);

        return Permiso::query()->distinct()->orderBy('modulo')->pluck('modulo')->all();
    }
}
