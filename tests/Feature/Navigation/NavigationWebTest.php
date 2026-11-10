<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Enums\PermissionCode;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockOneRecords;
use Tests\TestCase;

final class NavigationWebTest extends TestCase
{
    use CreatesBlockOneRecords;
    use RefreshDatabase;

    private const MODULE_ROUTES = [
        'inicio', 'sales.create', 'orders.index',
        'inventory.index', 'receipts.index', 'inventory.movements',
        'inventory.counts', 'inventory.wastes', 'inventory.adjustments.create',
        'productions.index', 'recipes.index', 'articles.index', 'catalog.categories.index', 'catalog.units.index', 'suppliers.index',
        'cash.index', 'cash.movements', 'personnel.index', 'attendance.mark',
        'attendance.index', 'personnel.pay-report', 'admin.users.index',
        'admin.roles.index', 'admin.permissions.index',
    ];

    public function test_every_module_is_reachable_and_keeps_the_same_complete_sidebar(): void
    {
        $this->actingAs($this->actor(PermissionCode::cases()));
        $expected = array_map(fn (string $route): string => route($route), self::MODULE_ROUTES);

        foreach (self::MODULE_ROUTES as $route) {
            $response = $this->get(route($route))->assertOk();
            $response->assertDontSee('Próximo')->assertDontSee('módulo en preparación');
            $xpath = $this->sidebar($response->getContent());
            $this->assertPublicIdentity($xpath);
            $links = $xpath->query('//aside[@id="app-sidebar"]/nav//a');
            $actual = [];
            foreach ($links as $link) {
                $actual[] = $link->getAttribute('href');
                $this->assertNotSame('#', $link->getAttribute('href'));
            }
            $this->assertSame($expected, $actual, $route.' lost a module link');
            $this->assertSame(1, $xpath->query('//aside//a[@aria-current="page"]')->length, $route);
            $this->assertSame('⌂', trim($xpath->query('//aside//a[@href="'.route('inicio').'"]/span')->item(0)->textContent));
        }
    }

    public function test_creation_screens_use_the_complete_navigation_too(): void
    {
        $this->actingAs($this->actor(PermissionCode::cases()));
        foreach ([
            'articles.create', 'suppliers.create', 'recipes.create', 'productions.create',
            'receipts.create', 'inventory.counts.create', 'inventory.wastes.create',
            'orders.create', 'personnel.create', 'admin.users.create', 'admin.roles.create',
        ] as $route) {
            $response = $this->get(route($route))->assertOk();
            $xpath = $this->sidebar($response->getContent());
            $this->assertPublicIdentity($xpath);
            $this->assertSame(count(self::MODULE_ROUTES), $xpath->query('//aside/nav//a')->length, $route);
            $this->assertSame(1, $xpath->query('//aside//a[@aria-current="page"]')->length, $route);
        }
    }

    public function test_limited_user_sees_only_authorized_links_and_direct_access_is_forbidden(): void
    {
        $this->actingAs($this->actor([PermissionCode::ArticlesView, PermissionCode::InventoryMovementsView]));
        $response = $this->get(route('inventory.index'))->assertOk();
        $xpath = $this->sidebar($response->getContent());
        $actual = [];
        foreach ($xpath->query('//aside/nav//a') as $link) {
            $actual[] = $link->getAttribute('href');
        }
        $allowed = ['inicio', 'inventory.index', 'inventory.movements', 'articles.index'];
        $this->assertSame(array_map(fn ($route) => route($route), $allowed), $actual);

        foreach (array_diff(self::MODULE_ROUTES, $allowed) as $route) {
            $this->get(route($route))->assertForbidden();
        }
    }

    public function test_administration_details_and_edit_screens_are_reachable(): void
    {
        $actor = $this->actor(PermissionCode::cases());
        $this->actingAs($actor);
        foreach ([
            route('admin.users.show', $actor), route('admin.users.edit', $actor),
            route('admin.roles.show', $actor->rol_id), route('admin.roles.edit', $actor->rol_id),
        ] as $url) {
            $response = $this->get($url)->assertOk();
            $xpath = $this->sidebar($response->getContent());
            $this->assertPublicIdentity($xpath);
            $this->assertSame(count(self::MODULE_ROUTES), $xpath->query('//aside/nav//a')->length);
        }
    }

    #[DataProvider('partialPermissions')]
    public function test_partial_permissions_never_link_to_a_denied_read_screen(PermissionCode $permission, ?string $route): void
    {
        $actor = $this->actor([$permission]);
        $this->actingAs($actor);
        $response = $this->get(route('inicio'))->assertOk();
        $xpath = $this->sidebar($response->getContent());
        $urls = [];
        foreach ($xpath->query('//aside/nav//a') as $link) {
            $urls[] = $link->getAttribute('href');
            $this->get($link->getAttribute('href'))->assertOk();
        }
        $expected = match ($permission) {
            PermissionCode::CashMovementsView => [route('inicio'), route('cash.index'), route('cash.movements')],
            default => $route === null ? [route('inicio')] : [route('inicio'), route($route)],
        };
        $this->assertSame($expected, $urls);

        foreach ($xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " action-card ")]') as $action) {
            $this->get($action->getAttribute('href'))->assertOk();
        }

        DB::table('roles')->where('id', $actor->rol_id)->update(['activo' => false]);
        $response = $this->get(route('inicio'))->assertOk();
        $this->assertSame(1, $this->sidebar($response->getContent())->query('//aside/nav//a')->length);
        if ($route !== null) {
            $this->get(route($route))->assertForbidden();
        }
    }

    public static function partialPermissions(): array
    {
        return [
            'movements without stock access' => [PermissionCode::InventoryMovementsView, 'inventory.movements'],
            'count confirmation only' => [PermissionCode::InventoryCountsConfirm, 'inventory.counts'],
            'waste annulment only' => [PermissionCode::InventoryWasteAnnul, 'inventory.wastes'],
            'adjustment output only' => [PermissionCode::InventoryAdjustmentsOutput, 'inventory.adjustments.create'],
            'cash movements only' => [PermissionCode::CashMovementsView, 'cash.movements'],
            'production read' => [PermissionCode::ProductionView, 'productions.index'],
            'personnel read' => [PermissionCode::PersonnelView, 'personnel.index'],
            'attendance marking' => [PermissionCode::AttendanceMark, 'attendance.mark'],
            'hourly pay read' => [PermissionCode::HourlyPayView, 'personnel.pay-report'],
            'users read' => [PermissionCode::UsersView, 'admin.users.index'],
            'roles read' => [PermissionCode::RolesView, 'admin.roles.index'],
            'permissions read' => [PermissionCode::PermissionsView, 'admin.permissions.index'],
            'role permissions mutation does not grant read' => [PermissionCode::RolePermissionsManage, null],
        ];
    }

    private function assertPublicIdentity(DOMXPath $xpath): void
    {
        $this->assertStringEndsWith(' | Almapan', trim($xpath->evaluate('string(//title)')));
        $this->assertSame('Almapan', trim($xpath->evaluate('string(//aside//div[@class="sidebar-brand"]//strong)')));
        $this->assertSame('Almapan', trim($xpath->evaluate('string(//header//span[@class="topbar-kicker"])')));
        $this->assertSame('A', trim($xpath->evaluate('string(//aside//span[@class="brand-mark"])')));
    }

    private function actor(array $permissions): User
    {
        // The role name deliberately grants nothing beyond its effective assignments.
        $roleId = $this->createRole();
        foreach ($permissions as $permission) {
            $id = $this->createPermission([
                'codigo' => $permission->value, 'nombre' => $permission->label(),
                'modulo' => $permission->module(),
            ]);
            $this->attachPermission($roleId, $id);
        }

        return User::query()->findOrFail($this->createUser($roleId));
    }

    private function sidebar(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
