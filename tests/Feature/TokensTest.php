<?php

use App\Enums\TokenStatus;
use App\Enums\TransactionStatus;
use App\Models\Category;
use App\Models\Token;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

// ── Bulk delete ───────────────────────────────────────────────────────────────

it('selects all deletable tokens on the current page', function () {
    $admin    = User::factory()->create();
    $category = Category::factory()->create();
    $tokens   = Token::factory()->for($category)->count(3)->create(['status' => TokenStatus::Available]);
    Token::factory()->for($category)->create(['status' => TokenStatus::Sold]);

    $component = Livewire::actingAs($admin)
        ->test('pages::admin.tokens')
        ->call('selectAll');

    $ids = $tokens->pluck('id')->map(fn ($id) => (int) $id)->toArray();
    expect(array_diff($ids, $component->get('selected')))->toBeEmpty();
});

it('deselects all tokens', function () {
    $admin    = User::factory()->create();
    $category = Category::factory()->create();
    $token    = Token::factory()->for($category)->create(['status' => TokenStatus::Available]);

    Livewire::actingAs($admin)
        ->test('pages::admin.tokens')
        ->set('selected', [(int) $token->id])
        ->call('deselectAll')
        ->assertSet('selected', []);
});

it('opens bulk delete confirmation when tokens are selected', function () {
    $admin    = User::factory()->create();
    $category = Category::factory()->create();
    $token    = Token::factory()->for($category)->create(['status' => TokenStatus::Available]);

    Livewire::actingAs($admin)
        ->test('pages::admin.tokens')
        ->set('selected', [(int) $token->id])
        ->call('confirmBulkDelete')
        ->assertSet('showBulkDeleteConfirm', true);
});

it('does not open bulk delete confirmation when nothing is selected', function () {
    $admin = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.tokens')
        ->call('confirmBulkDelete')
        ->assertSet('showBulkDeleteConfirm', false);
});

it('bulk deletes selected available tokens', function () {
    $admin    = User::factory()->create();
    $category = Category::factory()->create();
    $t1 = Token::factory()->for($category)->create(['status' => TokenStatus::Available]);
    $t2 = Token::factory()->for($category)->create(['status' => TokenStatus::Available]);

    Livewire::actingAs($admin)
        ->test('pages::admin.tokens')
        ->set('selected', [(int) $t1->id, (int) $t2->id])
        ->call('bulkDestroy')
        ->assertSet('selected', [])
        ->assertSet('showBulkDeleteConfirm', false);

    expect(Token::find($t1->id))->toBeNull();
    expect(Token::find($t2->id))->toBeNull();
});

it('skips sold tokens during bulk delete', function () {
    $admin       = User::factory()->create();
    $category    = Category::factory()->create();
    $transaction = Transaction::factory()->create(['status' => TransactionStatus::Completed]);
    $available   = Token::factory()->for($category)->create(['status' => TokenStatus::Available]);
    $sold        = Token::factory()->for($category)->create([
        'status'         => TokenStatus::Sold,
        'transaction_id' => $transaction->id,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.tokens')
        ->set('selected', [(int) $available->id, (int) $sold->id])
        ->call('bulkDestroy');

    expect(Token::find($available->id))->toBeNull();
    expect(Token::find($sold->id))->not->toBeNull();
});

it('clears selection when filter changes', function () {
    $admin    = User::factory()->create();
    $category = Category::factory()->create();
    $token    = Token::factory()->for($category)->create(['status' => TokenStatus::Available]);

    Livewire::actingAs($admin)
        ->test('pages::admin.tokens')
        ->set('selected', [(int) $token->id])
        ->set('statusFilter', 'sold')
        ->assertSet('selected', []);
});
