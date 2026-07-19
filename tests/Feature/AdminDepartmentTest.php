<?php

namespace Tests\Feature;

use App\Models\AdminDepartment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDepartmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    public function test_the_four_baseline_admin_departments_exist_after_migrating(): void
    {
        $this->assertDatabaseHas('admin_departments', ['name' => 'Examination Department']);
        $this->assertDatabaseHas('admin_departments', ['name' => 'IT Department']);
        $this->assertDatabaseHas('admin_departments', ['name' => 'Registrar Office']);
        $this->assertDatabaseHas('admin_departments', ['name' => 'Transport Department']);
    }

    public function test_admin_can_list_admin_departments(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/admin-departments')
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    public function test_admin_can_create_an_admin_department(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin-departments', ['name' => 'Library Department'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Library Department');

        $this->assertDatabaseHas('admin_departments', ['name' => 'Library Department']);
    }

    public function test_admin_department_name_must_be_unique(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin-departments', ['name' => 'Examination Department'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_admin_can_update_an_admin_department(): void
    {
        $department = AdminDepartment::where('name', 'Registrar Office')->firstOrFail();

        $this->actingAs($this->admin)
            ->putJson("/api/admin-departments/{$department->id}", ['name' => 'Registrar Department'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Registrar Department');
    }

    public function test_non_admin_cannot_manage_admin_departments(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'status' => 'active']);

        $this->actingAs($staff)->getJson('/api/admin-departments')->assertForbidden();
        $this->actingAs($staff)->postJson('/api/admin-departments', ['name' => 'x'])->assertForbidden();
    }
}
