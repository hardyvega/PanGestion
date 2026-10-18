<?php

declare(strict_types=1);

namespace App\Services\Administration;

use App\Enums\PermissionCode;
use App\Exceptions\OperationalAdminException;
use App\Models\Rol;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class UserAdministrationService
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly OperationalAdminGuard $operationalAdmins,
    ) {
    }

    public function list(User $actor, array $filters)
    {
        $this->permissions->authorize($actor, PermissionCode::UsersView);

        return User::query()
            ->with('rol')
            ->when($filters['q'] !== '', static function ($query) use ($filters): void {
                $term = addcslashes($filters['q'], '\\%_');
                $query->where(static function ($nested) use ($term): void {
                    $nested->where('nombre', 'ilike', '%'.$term.'%')
                        ->orWhere('apellido', 'ilike', '%'.$term.'%')
                        ->orWhere('nombre_usuario', 'ilike', '%'.$term.'%')
                        ->orWhere('correo', 'ilike', '%'.$term.'%');
                });
            })
            ->when($filters['activo'] === 'activos', static fn ($query) => $query->where('activo', true))
            ->when($filters['activo'] === 'inactivos', static fn ($query) => $query->where('activo', false))
            ->when($filters['rol_id'] !== '', static fn ($query) => $query->where('rol_id', (int) $filters['rol_id']))
            ->orderBy('apellido')->orderBy('nombre')->orderBy('id')
            ->paginate(15)
            ->withQueryString();
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): User
    {
        $this->permissions->authorize($actor, PermissionCode::UsersManage);

        return DB::transaction(function () use ($data): User {
            $role = $this->lockActiveRole((int) $data['rol_id']);
            $now = now();
            $id = DB::table('usuarios')->insertGetId([
                'rol_id' => $role->id,
                'nombre' => trim((string) $data['nombre']),
                'apellido' => trim((string) $data['apellido']),
                'nombre_usuario' => trim((string) $data['nombre_usuario']),
                'correo' => $this->nullableText($data['correo'] ?? null),
                'password' => Hash::make((string) $data['password']),
                'activo' => true,
                'ultimo_acceso' => null,
                'remember_token' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return User::query()->with('rol')->findOrFail($id);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, User $user, array $data): User
    {
        $this->permissions->authorize($actor, PermissionCode::UsersManage);

        return DB::transaction(function () use ($actor, $user, $data): User {
            $this->operationalAdmins->lockAuthorizationState();
            $locked = $this->lockUser($user->getKey());
            $role = $this->lockActiveRole((int) $data['rol_id']);

            if ($locked->getKey() === $actor->getKey() && ! (bool) $data['activo']) {
                throw new OperationalAdminException('No puedes desactivar tu propia cuenta.');
            }

            $updates = [
                'rol_id' => $role->id,
                'nombre' => trim((string) $data['nombre']),
                'apellido' => trim((string) $data['apellido']),
                'nombre_usuario' => trim((string) $data['nombre_usuario']),
                'correo' => $this->nullableText($data['correo'] ?? null),
                'activo' => (bool) $data['activo'],
                'updated_at' => now(),
            ];

            if (array_key_exists('password', $data) && trim((string) $data['password']) !== '') {
                $updates['password'] = Hash::make((string) $data['password']);
            }

            DB::table('usuarios')->where('id', $locked->getKey())->update($updates);

            $this->operationalAdmins->assertAtLeastOne();
            if ($locked->getKey() === $actor->getKey()) {
                $this->operationalAdmins->assertActorRemains($actor);
            }

            return User::query()->with('rol')->findOrFail($locked->getKey());
        });
    }

    public function setActive(User $actor, User $user, bool $active): User
    {
        $this->permissions->authorize($actor, PermissionCode::UsersManage);

        return DB::transaction(function () use ($actor, $user, $active): User {
            $this->operationalAdmins->lockAuthorizationState();
            $locked = $this->lockUser($user->getKey());

            if ($locked->getKey() === $actor->getKey() && ! $active) {
                throw new OperationalAdminException('No puedes desactivar tu propia cuenta.');
            }

            DB::table('usuarios')->where('id', $locked->getKey())->update([
                'activo' => $active,
                'updated_at' => now(),
            ]);

            $this->operationalAdmins->assertAtLeastOne();
            if ($locked->getKey() === $actor->getKey()) {
                $this->operationalAdmins->assertActorRemains($actor);
            }

            return User::query()->with('rol')->findOrFail($locked->getKey());
        });
    }

    private function lockUser(int $id): User
    {
        $row = DB::table('usuarios')->where('id', $id)->lockForUpdate()->first();
        if ($row === null) {
            throw (new ModelNotFoundException())->setModel(User::class, [$id]);
        }

        return User::query()->findOrFail($id);
    }

    private function lockActiveRole(int $id): object
    {
        $role = DB::table('roles')->where('id', $id)->where('activo', true)->lockForUpdate()->first();
        if ($role === null) {
            throw new OperationalAdminException('El rol seleccionado no existe o está inactivo.');
        }

        return $role;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
