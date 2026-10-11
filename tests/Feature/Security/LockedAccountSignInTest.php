<?php

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\User;

/**
 * A locked account is refused at the door, whichever of the four kinds it is.
 *
 * This is the half of the lock feature that makes the icon worth having: the
 * Actions column writes is_active, and this file proves that writing it actually
 * stops the person signing in. Nothing in the login path is touched to make these
 * pass — canAccessAdmin() already requires is_active and LoginRequest already has
 * the sentence — so a failure here means somebody changed the login rules, not
 * that a message is missing.
 *
 * All four kinds are asserted separately even though they are one code path,
 * because they are four rows in one table only as an implementation detail: the
 * owner thinks of a handler, a sponsorship and a monitoring account as different
 * things, and "I locked the handler and he still got in" is the bug this file
 * exists to catch.
 */
class LockedAccountSignInTest extends SecurityTestCase
{
    private const GENERIC = 'These credentials do not match our records.';

    /** The four account types, exactly as the four tabs create them. */
    private function accounts(): array
    {
        return [
            'user' => $this->userWithRole('administrator'),
            'handler' => $this->flagged(Role::HANDLER, 'is_handler'),
            'sponsor' => $this->flagged(Role::SPONSOR, 'is_sponsor'),
            'monitor' => $this->flagged(Role::MONITOR, 'is_monitor'),
        ];
    }

    private function flagged(string $slug, string $flag): User
    {
        $user = $this->userWithRole($slug);
        $user->forceFill([$flag => true])->save();

        return $user;
    }

    public function test_a_locked_account_of_every_kind_is_refused_with_the_deactivated_message(): void
    {
        foreach ($this->accounts() as $kind => $user) {
            $user->forceFill(['is_active' => false])->save();

            $this->signIn($user->username)->assertSessionHasErrors('username');

            $message = session('errors')->first('username');

            // The EXISTING sentence, not a new one. The generic message would send
            // somebody hunting a password that was never wrong.
            $this->assertStringContainsString('deactivated', $message, $kind);
            $this->assertNotSame(self::GENERIC, $message, $kind);
            $this->assertGuest();
        }

        // A permissions wall, not an attack: the Security Log is for refusals about
        // where a request came from.
        $this->assertSame(0, SecurityEvent::query()->count());
    }

    public function test_every_locked_refusal_is_in_the_activity_log(): void
    {
        foreach ($this->accounts() as $kind => $user) {
            $user->forceFill(['is_active' => false])->save();

            $this->signIn($user->username)->assertSessionHasErrors('username');

            $this->assertDatabaseHas('activity_logs', [
                'action' => 'auth.denied',
                'user_id' => $user->id,
            ]);
        }
    }

    public function test_unlocking_an_account_of_every_kind_restores_sign_in(): void
    {
        foreach ($this->accounts() as $kind => $user) {
            $user->forceFill(['is_active' => false])->save();

            $this->signIn($user->username)->assertSessionHasErrors('username');
            $this->assertGuest();

            $user->forceFill(['is_active' => true])->save();

            /*
             | No landing route is asserted. A handler and a monitor are sent
             | somewhere of their own after signing in, and pinning that here would
             | make this file fail the next time either landing changes, which has
             | nothing to do with whether the lock works.
             */
            $this->signIn($user->username)->assertSessionHasNoErrors();
            $this->assertAuthenticatedAs($user->fresh(), 'web');

            $this->post(route('admin.logout'));
        }
    }
}
