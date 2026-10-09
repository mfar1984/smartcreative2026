<?php

namespace Tests\Feature\Security;

use App\Models\BannedIp;
use App\Support\LocalTime;

/**
 * The Banned IPs list on the Security tab.
 *
 * Covers: only bans in force are listed, with both times in the General Config
 * format; a quiet empty state; Remove lifts one ban and Clear all lifts every ban,
 * each writing an activity entry; both are refused without settings.security.update
 * and refused on GET; the list itself needs only settings.security.view.
 */
class BannedIpManagerTest extends SecurityTestCase
{
    private function securityTab()
    {
        return $this->get(route('admin.settings.general', ['tab' => 'security']));
    }

    public function test_only_bans_in_force_are_listed(): void
    {
        $active = $this->activeBan('203.0.113.30');

        BannedIp::create([
            'ip_address' => '203.0.113.31',
            'failed_attempts' => 12,
            'reason' => '12 failed sign-in attempts within 15 minutes',
            'banned_at' => now()->subHour(),
            'expires_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($this->superAdmin())
            ->securityTab()
            ->assertOk()
            ->assertSee('Banned IPs')
            ->assertSee('203.0.113.30')
            ->assertSee(LocalTime::format($active->banned_at))
            ->assertSee(LocalTime::format($active->expires_at))
            ->assertSee($active->reason)
            ->assertSee('Clear all')
            ->assertSee('Remove the ban on 203.0.113.30')
            ->assertDontSee('203.0.113.31');
    }

    public function test_an_empty_list_says_so_quietly(): void
    {
        $this->actingAs($this->superAdmin())
            ->securityTab()
            ->assertOk()
            ->assertSee('No IP address is banned right now.')
            ->assertDontSee('Clear all');
    }

    public function test_remove_lifts_one_ban_and_logs_it(): void
    {
        $gone = $this->activeBan('203.0.113.40');
        $kept = $this->activeBan('203.0.113.41');

        // Some failures still counted against the address being removed.
        $this->bans()->recordFailure('203.0.113.40');

        $this->actingAs($this->administrator())
            ->delete(route('admin.settings.security.bans.destroy', $gone))
            ->assertRedirect(route('admin.settings.general', ['tab' => 'security']))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('banned_ips', ['id' => $gone->id]);
        $this->assertDatabaseHas('banned_ips', ['id' => $kept->id]);
        $this->assertFalse($this->bans()->isBanned('203.0.113.40'));
        $this->assertTrue($this->bans()->isBanned('203.0.113.41'));
        $this->assertSame(0, $this->bans()->failures('203.0.113.40'));

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'settings.security.unban',
            'description' => 'Removed the sign-in ban on 203.0.113.40.',
        ]);
    }

    public function test_clear_all_lifts_every_ban_and_logs_it(): void
    {
        $this->activeBan('203.0.113.50');
        $this->activeBan('203.0.113.51');

        $this->actingAs($this->administrator())
            ->delete(route('admin.settings.security.bans.clear'))
            ->assertRedirect(route('admin.settings.general', ['tab' => 'security']))
            ->assertSessionHas('status', '2 bans lifted.');

        $this->assertSame(0, BannedIp::query()->count());
        $this->assertFalse($this->bans()->isBanned('203.0.113.50'));
        $this->assertFalse($this->bans()->isBanned('203.0.113.51'));

        $this->assertDatabaseHas('activity_logs', ['action' => 'settings.security.unban_all']);
    }

    public function test_viewing_needs_only_the_view_permission_and_changing_needs_update(): void
    {
        $ban = $this->activeBan('203.0.113.60');

        $reader = $this->userWith(['admin.access', 'settings.general.view', 'settings.security.view']);

        $this->actingAs($reader)
            ->securityTab()
            ->assertOk()
            ->assertSee('203.0.113.60')
            ->assertDontSee('Clear all')
            ->assertDontSee('Remove the ban on 203.0.113.60');

        $this->actingAs($reader)
            ->delete(route('admin.settings.security.bans.destroy', $ban))
            ->assertForbidden();

        $this->actingAs($reader)
            ->delete(route('admin.settings.security.bans.clear'))
            ->assertForbidden();

        $this->assertTrue($this->bans()->isBanned('203.0.113.60'));
        $this->assertDatabaseMissing('activity_logs', ['action' => 'settings.security.unban']);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'settings.security.unban_all']);
    }

    public function test_a_role_without_the_security_tab_cannot_see_the_list(): void
    {
        $this->activeBan('203.0.113.61');

        // The viewer role reads General Config but not its Security tab.
        $this->actingAs($this->userWithRole('viewer'))
            ->securityTab()
            ->assertOk()
            ->assertDontSee('203.0.113.61')
            ->assertDontSee('Banned IPs');
    }

    public function test_neither_action_answers_a_get(): void
    {
        $ban = $this->activeBan('203.0.113.70');
        $owner = $this->superAdmin();

        $this->actingAs($owner)
            ->get('/admin/settings/security/banned-ips/' . $ban->id)
            ->assertStatus(405);

        $this->actingAs($owner)
            ->get('/admin/settings/security/banned-ips')
            ->assertStatus(405);

        $this->assertTrue($this->bans()->isBanned('203.0.113.70'));
    }
}
