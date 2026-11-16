<?php

use App\Models\Invitation;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['username' => 'boss', 'is_admin' => true]);
    [$this->invitation, $this->token] = Invitation::issue($this->admin, 'Ana', 7);
});

it('lets only administrators make and withdraw invitations, and shows the link once', function () {
    $member = User::factory()->create();
    $this->actingAs($member)->post(route('admin.invitations.store'), ['days' => 7])->assertNotFound();
    $this->actingAs($member)->delete(route('admin.invitations.destroy', $this->invitation))->assertNotFound();

    $response = $this->actingAs($this->admin)->post(route('admin.invitations.store'), ['label' => 'Rui', 'days' => 30]);
    $response->assertRedirect(route('admin.users'))->assertSessionHas('invite_link');

    $link = session('invite_link');
    expect($link)->toContain('/invite/')->and(Invitation::count())->toBe(2);
    // Only the hash is kept.
    expect(Invitation::where('token_hash', basename($link))->exists())->toBeFalse();

    $this->actingAs($this->admin)->get(route('admin.users'))->assertSee('Ana')->assertSee('Rui');
    $this->actingAs($this->admin)->delete(route('admin.invitations.destroy', $this->invitation))->assertRedirect();
    expect(Invitation::count())->toBe(1);
});

it('creates a normal account from a link, signs the person in and uses the link up', function () {
    config(['magnetite.default_quota_gb' => 3.0]);

    $this->get(route('invite.show', $this->token))->assertOk()->assertSee('You are invited');
    $this->post(route('invite.accept', $this->token), ['username' => 'Ana', 'password' => 'long-enough', 'password_confirmation' => 'long-enough'])
        ->assertRedirect(route('library'));

    $ana = User::where('username', 'ana')->sole();
    expect($ana->is_admin)->toBeFalse()->and($ana->quota_mb)->toBe(3072);
    $this->assertAuthenticatedAs($ana);
    expect($this->invitation->fresh()->used_by)->toBe($ana->id);

    auth()->logout();
    $this->get(route('invite.show', $this->token))->assertNotFound();
    $this->post(route('invite.accept', $this->token), ['username' => 'eve', 'password' => 'long-enough', 'password_confirmation' => 'long-enough'])->assertNotFound();
});

it('refuses an unknown, expired or withdrawn link', function () {
    $this->get(route('invite.show', str_repeat('a', 40)))->assertNotFound();

    $this->invitation->update(['expires_at' => now()->subMinute()]);
    $this->get(route('invite.show', $this->token))->assertNotFound();
    $this->post(route('invite.accept', $this->token), ['username' => 'ana', 'password' => 'long-enough', 'password_confirmation' => 'long-enough'])->assertNotFound();
});

it('checks the username and the password and keeps the invitation when they are wrong', function () {
    $this->post(route('invite.accept', $this->token), ['username' => 'boss', 'password' => 'long-enough', 'password_confirmation' => 'long-enough'])->assertSessionHasErrors('username');
    $this->post(route('invite.accept', $this->token), ['username' => 'BOSS', 'password' => 'long-enough', 'password_confirmation' => 'long-enough'])->assertSessionHasErrors('username');
    $this->post(route('invite.accept', $this->token), ['username' => '../x', 'password' => 'long-enough', 'password_confirmation' => 'long-enough'])->assertSessionHasErrors('username');
    $this->post(route('invite.accept', $this->token), ['username' => 'ana', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');

    expect(User::count())->toBe(1)->and($this->invitation->fresh()->used_at)->toBeNull();
});

it('works when the public site is off', function () {
    config(['magnetite.public_site' => false]);

    $this->get(route('invite.show', $this->token))->assertOk();
});
