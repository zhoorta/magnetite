<?php

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->admin = User::factory()->create(['username' => 'boss', 'is_admin' => true]);
    $this->user = User::factory()->create(['username' => 'ana', 'password' => 'old-password']);
});

it('lets a signed-in user change their password with the current one and signs their tokens out', function () {
    $this->user->createToken('phone');
    $this->actingAs($this->user)->get(route('password'))->assertOk();

    $this->actingAs($this->user)->put(route('password.update'), ['current_password' => 'wrong', 'password' => 'new-password', 'password_confirmation' => 'new-password'])
        ->assertSessionHasErrors('current_password');
    $this->actingAs($this->user)->put(route('password.update'), ['current_password' => 'old-password', 'password' => 'old-password', 'password_confirmation' => 'old-password'])
        ->assertSessionHasErrors('password');
    expect($this->user->tokens()->count())->toBe(1);

    $this->actingAs($this->user)->put(route('password.update'), ['current_password' => 'old-password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])
        ->assertSessionHas('status');

    expect(Hash::check('new-password', $this->user->fresh()->password))->toBeTrue()->and($this->user->tokens()->count())->toBe(0);
});

it('lets only administrators make a reset link, and shows it once', function () {
    $this->actingAs($this->user)->post(route('admin.users.reset', $this->admin))->assertNotFound();

    $this->actingAs($this->admin)->post(route('admin.users.reset', $this->user))->assertRedirect(route('admin.users'))->assertSessionHas('reset_link');
    expect(session('reset_link.url'))->toContain('/reset/')->and(Invitation::count())->toBe(1);

    $this->actingAs($this->admin)->get(route('admin.users'))->assertSee('Password resets waiting');
});

it('sets a new password from the link, signs the person in, signs every device out and uses the link up', function () {
    $this->user->createToken('phone');
    config(['session.driver' => 'database']);
    DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $this->user->id, 'ip_address' => '1.1.1.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
    [, $token] = Invitation::issueReset($this->admin, $this->user);
    [, $other] = Invitation::issueReset($this->admin, $this->user);

    $this->get(route('reset.show', $token))->assertOk()->assertSee('ana');
    $this->post(route('reset.accept', $token), ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
    $this->post(route('reset.accept', $token), ['password' => 'new-password', 'password_confirmation' => 'new-password'])->assertRedirect(route('library'));

    expect(Hash::check('new-password', $this->user->fresh()->password))->toBeTrue()
        ->and($this->user->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', 'old-session')->exists())->toBeFalse();
    $this->assertAuthenticatedAs($this->user);

    auth()->logout();
    $this->get(route('reset.show', $token))->assertNotFound();
    $this->get(route('reset.show', $other))->assertNotFound();
});

it('keeps invitation and reset links apart and refuses expired ones', function () {
    [, $invite] = Invitation::issue($this->admin, 'x', 7);
    [$reset, $resetToken] = Invitation::issueReset($this->admin, $this->user);

    $this->get(route('reset.show', $invite))->assertNotFound();
    $this->get(route('invite.show', $resetToken))->assertNotFound();

    $reset->update(['expires_at' => now()->subMinute()]);
    $this->get(route('reset.show', $resetToken))->assertNotFound();
});
