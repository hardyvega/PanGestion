<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PermissionCode;
use App\Support\PermissionPresentation;
use PHPUnit\Framework\TestCase;

final class PermissionPresentationTest extends TestCase
{
    public function test_every_approved_code_has_a_human_description_and_category(): void
    {
        foreach (PermissionCode::cases() as $permission) {
            $text = PermissionPresentation::description($permission->value);
            $this->assertGreaterThan(35, mb_strlen($text));
            $this->assertStringNotContainsString($permission->value, $text);
            $this->assertStringNotContainsString('requiere revisión administrativa', $text);
            $this->assertNotEmpty(PermissionPresentation::category($permission->module()));
        }
    }

    public function test_descriptions_explain_independent_permissions_and_do_not_offer_manual_costs(): void
    {
        $this->assertStringContainsString('requieren el permiso', PermissionPresentation::description('proveedores.ver'));
        $this->assertStringContainsString('También requiere', PermissionPresentation::description('inventario.recepciones.confirmar'));
        $this->assertStringContainsString('No habilita el ingreso manual', PermissionPresentation::description('inventario.costos.registrar'));
        $this->assertStringContainsString('permiso independiente', PermissionPresentation::description('roles.gestionar'));
        $this->assertStringContainsString('requiere revisión administrativa', PermissionPresentation::description('externo.desconocido'));
    }
}
