<?php

namespace Tests\Feature\Database\BlockOne;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockOneRecords;
use Tests\TestCase;

class RolePermissionConstraintsTest extends TestCase
{
    use CreatesBlockOneRecords;
    use RefreshDatabase;

    public function test_role_permission_can_be_created(): void
    {
        $roleId = $this->createRole();
        $permissionId = $this->createPermission();

        $this->attachPermission($roleId, $permissionId);

        $this->assertDatabaseHas('rol_permiso', [
            'rol_id' => $roleId,
            'permiso_id' => $permissionId,
        ]);
    }

    public function test_duplicate_role_permission_is_rejected(): void
    {
        $roleId = $this->createRole();
        $permissionId = $this->createPermission();
        $this->attachPermission($roleId, $permissionId);

        $this->expectException(QueryException::class);

        $this->attachPermission($roleId, $permissionId);
    }

    public function test_role_permission_rejects_nonexistent_role(): void
    {
        $permissionId = $this->createPermission();

        $this->expectException(QueryException::class);

        $this->attachPermission(999999, $permissionId);
    }

    public function test_role_permission_rejects_nonexistent_permission(): void
    {
        $roleId = $this->createRole();

        $this->expectException(QueryException::class);

        $this->attachPermission($roleId, 999999);
    }
}
