<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ── Access ────────────────────────────────────────────────────────────────────

it('renders the admins page for authenticated users', function () {
    $admin = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.admins')
        ->assertOk();
});

// ── List ──────────────────────────────────────────────────────────────────────

it('lists all admin users', function () {
    $a = User::factory()->create(['name' => 'Alice']);
    $b = User::factory()->create(['name' => 'Bob']);

    Livewire::actingAs($a)
        ->test('pages::admin.admins')
        ->assertSee('Alice')
        ->assertSee('Bob');
});

// ── Create ────────────────────────────────────────────────────────────────────

it('creates a new admin', function () {
    $admin = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.admins')
        ->call('openCreate')
        ->assertSet('showCreateModal', true)
        ->set('newName', 'New Admin')
        ->set('newEmail', 'new@example.com')
        ->set('newPassword', 'password123')
        ->set('newPasswordConfirmation', 'password123')
        ->call('create')
        ->assertSet('showCreateModal', false);

    expect(User::where('email', 'new@example.com')->exists())->toBeTrue();
});

it('validates required fields on create', function () {
    $admin = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.admins')
        ->call('create')
        ->assertHasErrors(['newName', 'newEmail', 'newPassword']);
});

it('validates email uniqueness on create', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $admin = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.admins')
        ->set('newName', 'Duplicate')
        ->set('newEmail', 'taken@example.com')
        ->set('newPassword', 'password123')
        ->set('newPasswordConfirmation', 'password123')
        ->call('create')
        ->assertHasErrors(['newEmail']);
});

it('validates password confirmation on create', function () {
    $admin = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.admins')
        ->set('newName', 'Test')
        ->set('newEmail', 'test@example.com')
        ->set('newPassword', 'password123')
        ->set('newPasswordConfirmation', 'different')
        ->call('create')
        ->assertHasErrors(['newPassword']);
});

// ── Change Password ───────────────────────────────────────────────────────────

it('changes an admin password', function () {
    $admin  = User::factory()->create();
    $target = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.admins')
        ->call('openChangePassword', $target->id)
        ->assertSet('showPasswordModal', true)
        ->assertSet('editingId', $target->id)
        ->set('changePassword', 'newpassword99')
        ->set('changePasswordConfirmation', 'newpassword99')
        ->call('savePassword')
        ->assertSet('showPasswordModal', false);

    expect(Hash::check('newpassword99', $target->fresh()->password))->toBeTrue();
});

it('validates password confirmation on change', function () {
    $admin  = User::factory()->create();
    $target = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.admins')
        ->call('openChangePassword', $target->id)
        ->set('changePassword', 'newpassword99')
        ->set('changePasswordConfirmation', 'mismatch')
        ->call('savePassword')
        ->assertHasErrors(['changePassword']);
});

// ── Delete ────────────────────────────────────────────────────────────────────

it('deletes another admin', function () {
    $admin  = User::factory()->create();
    $target = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.admins')
        ->call('confirmDelete', $target->id)
        ->assertSet('showDeleteConfirm', true)
        ->call('destroy');

    expect(User::find($target->id))->toBeNull();
});

it('cannot delete own account', function () {
    $admin  = User::factory()->create();
    User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.admins')
        ->call('confirmDelete', $admin->id)
        ->call('destroy');

    expect(User::find($admin->id))->not->toBeNull();
});

it('cannot delete the last admin account', function () {
    $admin = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.admins')
        ->call('confirmDelete', $admin->id)
        ->call('destroy');

    expect(User::count())->toBe(1);
});
