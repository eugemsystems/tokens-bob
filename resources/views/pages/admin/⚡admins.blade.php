<?php

use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Admins')] class extends Component
{
    // ── Create form ──────────────────────────────────────────────────────────

    public bool $showCreateModal = false;

    public string $newName = '';
    public string $newEmail = '';
    public string $newPassword = '';
    public string $newPasswordConfirmation = '';

    // ── Change password form ─────────────────────────────────────────────────

    public bool $showPasswordModal = false;
    public ?int $editingId = null;
    public string $editingName = '';

    public string $changePassword = '';
    public string $changePasswordConfirmation = '';

    // ── Delete confirmation ──────────────────────────────────────────────────

    public bool $showDeleteConfirm = false;
    public ?int $deletingId = null;

    // ── Computed ─────────────────────────────────────────────────────────────

    /** @return Collection<int, User> */
    #[Computed]
    public function admins(): Collection
    {
        return User::orderBy('name')->get();
    }

    // ── Create ───────────────────────────────────────────────────────────────

    public function openCreate(): void
    {
        $this->reset(['newName', 'newEmail', 'newPassword', 'newPasswordConfirmation']);
        $this->resetValidation();
        $this->showCreateModal = true;
    }

    public function create(): void
    {
        $this->validate([
            'newName'     => 'required|string|max:255',
            'newEmail'    => 'required|email|max:255|unique:users,email',
            'newPassword' => 'required|string|min:8|same:newPasswordConfirmation',
        ]);

        User::create([
            'name'     => $this->newName,
            'email'    => $this->newEmail,
            'password' => $this->newPassword,
        ]);

        $this->showCreateModal = false;
        unset($this->admins);
        Flux::toast(variant: 'success', text: 'Admin created.');
    }

    // ── Change password ───────────────────────────────────────────────────────

    public function openChangePassword(int $id): void
    {
        $admin = User::findOrFail($id);
        $this->editingId   = $id;
        $this->editingName = $admin->name;
        $this->reset(['changePassword', 'changePasswordConfirmation']);
        $this->resetValidation();
        $this->showPasswordModal = true;
    }

    public function savePassword(): void
    {
        $this->validate([
            'changePassword' => 'required|string|min:8|same:changePasswordConfirmation',
        ]);

        User::findOrFail($this->editingId)->update([
            'password' => Hash::make($this->changePassword),
        ]);

        $this->showPasswordModal = false;
        Flux::toast(variant: 'success', text: 'Password updated.');
    }

    // ── Delete ───────────────────────────────────────────────────────────────

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        $this->showDeleteConfirm = true;
    }

    public function destroy(): void
    {
        if ($this->deletingId === auth()->id()) {
            Flux::toast(variant: 'danger', text: 'You cannot delete your own account.');
            $this->showDeleteConfirm = false;

            return;
        }

        if (User::count() <= 1) {
            Flux::toast(variant: 'danger', text: 'Cannot delete the last admin account.');
            $this->showDeleteConfirm = false;

            return;
        }

        User::findOrFail($this->deletingId)->delete();
        $this->showDeleteConfirm = false;
        $this->deletingId = null;
        unset($this->admins);
        Flux::toast(variant: 'success', text: 'Admin deleted.');
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Admins</flux:heading>
        <flux:button wire:click="openCreate" variant="primary" icon="plus">
            New Admin
        </flux:button>
    </div>

    <div class="mt-6">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Email</flux:table.column>
                <flux:table.column>Joined</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->admins as $admin)
                    <flux:table.row :key="$admin->id">
                        <flux:table.cell>
                            <div class="flex items-center gap-3">
                                <flux:avatar :name="$admin->name" size="sm" />
                                <span class="font-medium">{{ $admin->name }}</span>
                                @if ($admin->id === auth()->id())
                                    <flux:badge size="sm" color="violet">You</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>{{ $admin->email }}</flux:table.cell>

                        <flux:table.cell>{{ $admin->created_at->format('d M Y') }}</flux:table.cell>

                        <flux:table.cell align="end">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button
                                    wire:click="openChangePassword({{ $admin->id }})"
                                    variant="ghost"
                                    size="sm"
                                    icon="lock-closed"
                                />
                                @if ($admin->id !== auth()->id())
                                    <flux:button
                                        wire:click="confirmDelete({{ $admin->id }})"
                                        variant="ghost"
                                        size="sm"
                                        icon="trash"
                                        class="text-red-500 hover:text-red-400"
                                    />
                                @endif
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="py-12 text-center text-zinc-500">
                            No admins found.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    {{-- ── Create Admin Modal ── --}}
    <flux:modal wire:model="showCreateModal" flyout class="md:w-96">
        <flux:heading size="lg">New Admin</flux:heading>
        <flux:text class="mt-1">The new admin will be able to log in immediately.</flux:text>

        <form wire:submit="create" class="mt-6 space-y-5">
            <flux:input
                wire:model="newName"
                label="Full Name"
                placeholder="Jane Smith"
                required
                autofocus
            />

            <flux:input
                wire:model="newEmail"
                label="Email Address"
                type="email"
                placeholder="jane@example.com"
                required
            />

            <flux:input
                wire:model="newPassword"
                label="Password"
                type="password"
                placeholder="Min. 8 characters"
                viewable
                required
            />

            <flux:input
                wire:model="newPasswordConfirmation"
                label="Confirm Password"
                type="password"
                placeholder="Repeat password"
                viewable
                required
            />

            <div class="flex justify-end gap-2 pt-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Create Admin</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- ── Change Password Modal ── --}}
    <flux:modal wire:model="showPasswordModal" flyout class="md:w-96">
        <flux:heading size="lg">Change Password</flux:heading>
        <flux:text class="mt-1">Set a new password for <strong>{{ $editingName }}</strong>.</flux:text>

        <form wire:submit="savePassword" class="mt-6 space-y-5">
            <flux:input
                wire:model="changePassword"
                label="New Password"
                type="password"
                placeholder="Min. 8 characters"
                viewable
                required
                autofocus
            />

            <flux:input
                wire:model="changePasswordConfirmation"
                label="Confirm New Password"
                type="password"
                placeholder="Repeat password"
                viewable
                required
            />

            <div class="flex justify-end gap-2 pt-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Update Password</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- ── Delete Confirmation ── --}}
    <flux:modal wire:model="showDeleteConfirm" class="md:w-80">
        <div class="space-y-4">
            <flux:heading size="lg">Delete Admin?</flux:heading>
            <flux:text class="text-zinc-500">
                This admin will immediately lose access. This cannot be undone.
            </flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button wire:click="destroy" variant="danger">Delete</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
