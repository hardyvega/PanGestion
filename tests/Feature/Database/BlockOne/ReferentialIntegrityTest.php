<?php

namespace Tests\Feature\Database\BlockOne;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockOneRecords;
use Tests\TestCase;

class ReferentialIntegrityTest extends TestCase
{
    use CreatesBlockOneRecords;
    use RefreshDatabase;

    public function test_role_referenced_by_user_cannot_be_deleted(): void
    {
        $roleId = $this->createRole();
        $this->createUser($roleId);

        $this->expectException(QueryException::class);

        DB::table('roles')->where('id', $roleId)->delete();
    }

    public function test_role_referenced_by_role_permission_cannot_be_deleted(): void
    {
        $roleId = $this->createRole();
        $permissionId = $this->createPermission();
        $this->attachPermission($roleId, $permissionId);

        $this->expectException(QueryException::class);

        DB::table('roles')->where('id', $roleId)->delete();
    }

    public function test_permission_referenced_by_role_permission_cannot_be_deleted(): void
    {
        $roleId = $this->createRole();
        $permissionId = $this->createPermission();
        $this->attachPermission($roleId, $permissionId);

        $this->expectException(QueryException::class);

        DB::table('permisos')->where('id', $permissionId)->delete();
    }
}
