<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

class BootstrapAdminCommand extends Command
{
    protected $signature = 'pangestion:bootstrap-admin';

    protected $description = 'Crea el primer usuario administrador de PanGestion.';

    private const ADMIN_ROLE_CODE = 'administrador';

    private const ADMIN_ROLE_NAME = 'Administrador';

    private const BOOTSTRAP_LOCK_KEY = 202609200044;

    public function handle(): int
    {
        if (DB::table('usuarios')->exists()) {
            $this->error('El bootstrap inicial ya fue realizado.');

            return self::FAILURE;
        }

        $input = $this->promptForInput();

        if ($input === null) {
            return self::FAILURE;
        }

        try {
            $userId = DB::transaction(function () use ($input): int {
                DB::select('SELECT pg_advisory_xact_lock(?)', [self::BOOTSTRAP_LOCK_KEY]);

                if (DB::table('usuarios')->exists()) {
                    throw new BootstrapAlreadyCompletedException();
                }

                $roleId = $this->resolveAdministratorRole();
                $permissions = DB::table('permisos')
                    ->where('activo', true)
                    ->orderBy('id')
                    ->get(['id']);

                if ($permissions->isEmpty()) {
                    throw new BootstrapConfigurationException(
                        'No existen permisos activos para asignar al administrador inicial.',
                    );
                }

                DB::table('rol_permiso')->insertOrIgnore(
                    $permissions
                        ->map(static fn (object $permission): array => [
                            'rol_id' => $roleId,
                            'permiso_id' => (int) $permission->id,
                        ])
                        ->all(),
                );

                $now = now();

                return (int) DB::table('usuarios')->insertGetId([
                    'rol_id' => $roleId,
                    'nombre' => $input['nombre'],
                    'apellido' => $input['apellido'],
                    'nombre_usuario' => $input['nombre_usuario'],
                    'correo' => $input['correo'],
                    'password' => Hash::make($input['password']),
                    'activo' => true,
                    'ultimo_acceso' => null,
                    'remember_token' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        } catch (BootstrapAlreadyCompletedException) {
            $this->error('El bootstrap inicial ya fue realizado.');

            return self::FAILURE;
        } catch (BootstrapConfigurationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('No se pudo completar el bootstrap inicial. No se guardaron cambios.');

            return self::FAILURE;
        }

        unset($input);

        $this->info('Administrador inicial creado correctamente.');

        return self::SUCCESS;
    }

    private function promptForInput(): ?array
    {
        $input = [
            'nombre' => trim((string) $this->ask('Nombre')),
            'apellido' => trim((string) $this->ask('Apellido')),
            'nombre_usuario' => trim((string) $this->ask('Nombre de usuario')),
            'correo' => trim((string) $this->ask('Correo (opcional)')),
            'password' => (string) $this->secret('Contraseña'),
            'password_confirmation' => (string) $this->secret('Confirmación de contraseña'),
        ];

        $validator = Validator::make($input, [
            'nombre' => ['required', 'string', 'max:100'],
            'apellido' => ['required', 'string', 'max:100'],
            'nombre_usuario' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'correo' => ['nullable', 'email', 'max:254'],
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()],
            'password_confirmation' => ['same:password'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return null;
        }

        $input['correo'] = $input['correo'] === '' ? null : $input['correo'];

        unset($input['password_confirmation']);

        return $input;
    }

    private function resolveAdministratorRole(): int
    {
        $role = DB::table('roles')
            ->whereRaw('lower(codigo) = ?', [self::ADMIN_ROLE_CODE])
            ->lockForUpdate()
            ->first(['id', 'activo']);

        if ($role === null) {
            return (int) DB::table('roles')->insertGetId([
                'codigo' => self::ADMIN_ROLE_CODE,
                'nombre' => self::ADMIN_ROLE_NAME,
                'descripcion' => 'Rol administrativo inicial de PanGestion.',
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! $role->activo) {
            DB::table('roles')->where('id', $role->id)->update([
                'activo' => true,
                'updated_at' => now(),
            ]);
        }

        return (int) $role->id;
    }
}

final class BootstrapAlreadyCompletedException extends \RuntimeException
{
}

final class BootstrapConfigurationException extends \RuntimeException
{
}
