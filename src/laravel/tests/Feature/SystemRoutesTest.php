<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The system pages: what changes something is POST (CSRF token), and only system admins reach it. */
class SystemRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const CHANGING = [
        'system/log/clear',
        'system/log/delete/laravel.log',
        'system/backup/run',
        'system/backup/delete/2026-10-01',
        'system/cache/clear',
        'system/jobs/clear',
        'system/jobs/rerun',
        'system/jobs/stop',
    ];

    private function admin(): User
    {
        $user = User::factory()->create();
        config(['boilerplate.system_admins' => [(string) $user->id]]);

        return $user->fresh();
    }

    public function test_nothing_that_changes_something_answers_a_get(): void
    {
        $this->actingAs($this->admin());

        foreach (self::CHANGING as $url) {
            $this->get('/'.$url)->assertStatus(405);
        }
    }

    public function test_only_system_admins_can_post_to_them(): void
    {
        foreach (self::CHANGING as $url) {
            $this->post('/'.$url)->assertRedirect('/login');
        }

        // The first user of an installation is a system admin; the second is not.
        User::factory()->create();
        $this->actingAs(User::factory()->create());
        foreach (self::CHANGING as $url) {
            $this->post('/'.$url)->assertForbidden();
        }
    }

    public function test_the_pages_post_with_a_csrf_token(): void
    {
        $this->withoutVite()->actingAs($this->admin());

        foreach (['system/cache', 'system/jobs', 'system/log', 'system/backup'] as $page) {
            $html = $this->get('/'.$page)->assertOk()->getContent();
            $this->assertStringNotContainsString("window.location.href = '", $html, $page);
            $this->assertDoesNotMatchRegularExpression('/<a [^>]*href=[\'"][^\'"]*\/(clear|delete|run|rerun|stop)\b/', $html, $page);
        }
    }

    public function test_an_admin_can_clear_the_cache_with_a_post(): void
    {
        $this->actingAs($this->admin());

        $this->post('/system/cache/clear')->assertRedirect(route('system.cache.index'));
    }
}
