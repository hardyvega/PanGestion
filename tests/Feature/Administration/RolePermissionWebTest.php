<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Enums\PermissionCode;
use App\Models\Rol;
use App\Models\User;
use App\Services\Administration\OperationalAdminGuard;
use App\Support\PermissionPresentation;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockOneRecords;
use Tests\TestCase;

final class RolePermissionWebTest extends TestCase
{
    use CreatesBlockOneRecords;
    use RefreshDatabase;

    private User $actor;
    private Rol $role;
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (PermissionCode::cases() as $permission) {
            $this->ids[$permission->value] = $this->createPermission([
                'codigo' => $permission->value, 'nombre' => $permission->label(),
                'descripcion' => $permission->description(), 'modulo' => $permission->module(),
            ]);
        }
        $adminRole = $this->createRole(['codigo' => 'editor_ui', 'nombre' => 'Editor UI']);
        foreach (OperationalAdminGuard::requiredPermissions() as $permission) {
            $this->attachPermission($adminRole, $this->ids[$permission->value]);
        }
        $this->actor = User::query()->findOrFail($this->createUser($adminRole));
        $this->role = Rol::query()->findOrFail($this->createRole(['codigo' => 'rol_ui', 'nombre' => 'Rol de prueba UI']));
    }

    public function test_all_active_permissions_have_descriptions_without_visible_technical_codes(): void
    {
        $before = DB::table('permisos')->orderBy('id')->get()->toJson();
        $response = $this->actingAs($this->actor)->get(route('admin.roles.show', $this->role))->assertOk();
        $xpath = $this->html($response->getContent());
        $rows = $xpath->query('//label[@data-role-permission]');
        $this->assertCount(count(PermissionCode::cases()), $rows);
        foreach ($rows as $row) {
            $code = $row->getAttribute('data-permission-code');
            $this->assertArrayHasKey($code, $this->ids);
            $this->assertSame((string) $this->ids[$code], $row->getElementsByTagName('input')->item(0)->getAttribute('value'));
            $this->assertStringNotContainsString($code, $row->textContent);
            $this->assertSame(PermissionPresentation::description($code), trim($row->getElementsByTagName('small')->item(0)->textContent));
        }
        $this->assertSame($before, DB::table('permisos')->orderBy('id')->get()->toJson());
        $response->assertSee('Buscar permiso o acción...')->assertSee('Asignados')->assertSee('Sin asignar')
            ->assertSee('Este rol todavía no tiene permisos asignados.')->assertSee('name="_token"', false)->assertSee('value="PUT"', false);
    }

    public function test_saved_selection_is_rendered_without_default_or_name_based_grants(): void
    {
        $id = $this->ids['articulos.ver'];
        $this->attachPermission((int) $this->role->id, $id);
        $this->actingAs($this->actor);
        $this->assertSame([$id], $this->checkedIds($this->get(route('admin.roles.show', $this->role))->assertOk()->getContent()));
        DB::table('roles')->where('id', $this->role->id)->update(['nombre' => 'Cajera']);
        $this->assertSame([$id], $this->checkedIds($this->get(route('admin.roles.show', $this->role))->assertOk()->getContent()));
        $this->assertSame([$id], $this->assignedIds());
    }

    public function test_saving_persists_exactly_the_selected_permission_ids(): void
    {
        $this->attachPermission((int) $this->role->id, $this->ids['roles.ver']);
        $expected = [$this->ids['ventas.crear'], $this->ids['articulos.ver']];
        $this->actingAs($this->actor)->from(route('admin.roles.show', $this->role))
            ->put(route('admin.roles.permissions.update', $this->role), ['permissions' => $expected, '_role_permissions' => '1'])
            ->assertRedirect(route('admin.roles.show', $this->role))
            ->assertSessionHas('success', 'Permisos del rol actualizados correctamente.');
        sort($expected);
        $this->assertSame($expected, $this->assignedIds());
    }

    public function test_explicit_empty_selection_clears_only_the_target_role(): void
    {
        $this->attachPermission((int) $this->role->id, $this->ids['articulos.ver']);
        $this->actingAs($this->actor)->put(route('admin.roles.permissions.update', $this->role), ['_role_permissions' => '1'])->assertSessionHasNoErrors();
        $this->assertSame([], $this->assignedIds());
        $this->assertSame(6, DB::table('rol_permiso')->where('rol_id', $this->actor->rol_id)->count());
    }

    public function test_old_selection_survives_an_error_including_an_intentionally_empty_selection(): void
    {
        $this->attachPermission((int) $this->role->id, $this->ids['articulos.ver']);
        $this->actingAs($this->actor)->withSession(['_old_input' => ['_role_permissions' => '1', 'permissions' => [$this->ids['ventas.crear']]]]);
        $this->assertSame([$this->ids['ventas.crear']], $this->checkedIds($this->get(route('admin.roles.show', $this->role))->getContent()));
        $this->withSession(['_old_input' => ['_role_permissions' => '1']]);
        $this->assertSame([], $this->checkedIds($this->get(route('admin.roles.show', $this->role))->getContent()));
        $this->assertSame([$this->ids['articulos.ver']], $this->assignedIds());
    }

    public function test_inactive_and_nonexistent_permissions_are_not_accepted(): void
    {
        $inactive = $this->ids['articulos.ver'];
        DB::table('permisos')->where('id', $inactive)->update(['activo' => false]);
        $this->actingAs($this->actor)->from(route('admin.roles.show', $this->role));
        foreach ([$inactive, 999999999] as $id) {
            $this->put(route('admin.roles.permissions.update', $this->role), ['permissions' => [$id]])->assertSessionHasErrors([
                'permissions.0' => 'Uno de los permisos seleccionados ya no está disponible. Revisa la selección antes de guardar.',
            ]);
            $this->assertSame([], $this->assignedIds());
        }
        $xpath = $this->html($this->get(route('admin.roles.show', $this->role))->getContent());
        $this->assertSame(0, $xpath->query('//input[@name="permissions[]" and @value="'.$inactive.'"]')->length);
    }

    public function test_read_only_role_view_does_not_offer_permission_mutation(): void
    {
        $role = $this->createRole(['codigo' => 'lector_roles_ui']);
        $this->attachPermission($role, $this->ids['roles.ver']);
        $reader = User::query()->findOrFail($this->createUser($role, ['nombre_usuario' => 'lector_ui']));
        $this->attachPermission((int) $this->role->id, $this->ids['articulos.ver']);
        $this->actingAs($reader)->get(route('admin.roles.show', $this->role))->assertOk()
            ->assertDontSee('data-role-permission-form', false)->assertSee(PermissionPresentation::description('articulos.ver'));
        $this->put(route('admin.roles.permissions.update', $this->role), ['permissions' => []])->assertForbidden();
        $this->assertSame([$this->ids['articulos.ver']], $this->assignedIds());
    }

    public function test_unauthorized_user_cannot_read_or_submit_role_permissions(): void
    {
        $user = User::query()->findOrFail($this->createUser($this->createRole(['codigo' => 'sin_acceso_ui']), ['nombre_usuario' => 'sin_acceso_ui']));
        $this->actingAs($user)->get(route('admin.roles.show', $this->role))->assertForbidden();
        $this->put(route('admin.roles.permissions.update', $this->role), ['permissions' => [$this->ids['articulos.ver']]])->assertForbidden();
        $this->assertSame([], $this->assignedIds());
    }

    public function test_http_save_preserves_last_administrator_protection(): void
    {
        $before = DB::table('rol_permiso')->where('rol_id', $this->actor->rol_id)->orderBy('permiso_id')->pluck('permiso_id')->all();
        $this->actingAs($this->actor)->from(route('admin.roles.show', $this->actor->rol_id))
            ->put(route('admin.roles.permissions.update', $this->actor->rol_id), ['_role_permissions' => '1'])
            ->assertSessionHasErrors('operacion');
        $this->assertSame($before, DB::table('rol_permiso')->where('rol_id', $this->actor->rol_id)->orderBy('permiso_id')->pluck('permiso_id')->all());
    }

    public function test_success_notice_has_a_role_scoped_positive_style(): void
    {
        $this->actingAs($this->actor)->withSession(['success' => 'Rol creado correctamente.'])
            ->get(route('admin.roles.show', $this->role))->assertOk()->assertSee('Rol creado correctamente.')
            ->assertSee('role-administration-page', false)->assertSee('form-alert-success', false);
    }

    public function test_inactive_assignments_are_explained_but_are_not_offered_as_active_access(): void
    {
        $id = $this->ids['articulos.ver'];
        $this->attachPermission((int) $this->role->id, $id);
        DB::table('permisos')->where('id', $id)->update(['activo' => false]);
        $response = $this->actingAs($this->actor)->get(route('admin.roles.show', $this->role))->assertOk();
        $response->assertSee('permisos inactivos vinculados')->assertSee('No otorgan acceso.');
        $this->assertSame(0, $this->html($response->getContent())->query('//input[@name="permissions[]" and @value="'.$id.'"]')->length);
        $this->assertSame([$id], $this->assignedIds());
    }

    public function test_self_lockout_remains_blocked_even_when_another_administrator_exists(): void
    {
        $otherRole = $this->createRole(['codigo' => 'otro_admin_ui']);
        foreach (OperationalAdminGuard::requiredPermissions() as $permission) $this->attachPermission($otherRole, $this->ids[$permission->value]);
        $this->createUser($otherRole, ['nombre_usuario' => 'otro_admin_ui']);
        $this->actingAs($this->actor)->from(route('admin.roles.show', $this->actor->rol_id))
            ->put(route('admin.roles.permissions.update', $this->actor->rol_id), ['_role_permissions' => '1'])
            ->assertSessionHasErrors('operacion');
        $this->assertSame(6, DB::table('rol_permiso')->where('rol_id', $this->actor->rol_id)->count());
        $this->assertSame(6, DB::table('rol_permiso')->where('rol_id', $otherRole)->count());
    }

    private function assignedIds(): array
    {
        return DB::table('rol_permiso')->where('rol_id', $this->role->id)->orderBy('permiso_id')->pluck('permiso_id')->map(fn ($id) => (int) $id)->all();
    }

    private function checkedIds(string $html): array
    {
        $ids = [];
        foreach ($this->html($html)->query('//input[@name="permissions[]" and @checked]') as $input) $ids[] = (int) $input->getAttribute('value');
        sort($ids);
        return $ids;
    }

    private function html(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new DOMXPath($document);
    }
}
