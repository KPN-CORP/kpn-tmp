<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every app screen, document and write sits behind sign-in: a guest is sent
 * to the login page and never reaches a controller (so no database is touched).
 */
class GuestAccessTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function protectedRoutes(): array
    {
        return [
            'home' => ['GET', '/'],
            'facecard list' => ['GET', '/facecard'],
            'employee profile' => ['GET', '/employee/01124090037'],
            'my development plan' => ['GET', '/idp/my'],
            'team development plan' => ['GET', '/idp'],
            'an employee IDP' => ['GET', '/idp/01124090037'],
            'IDP PDF' => ['GET', '/idp/01124090037/pdf'],
            'task box' => ['GET', '/approvals'],
            'import center' => ['GET', '/import-center'],
            'roles' => ['GET', '/admin/roles'],
            'role user search' => ['GET', '/admin/roles/users?q=a'],
            'add a plan' => ['POST', '/idp'],
            'submit a plan' => ['POST', '/idp/01124090037/submit-planning'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_a_guest_is_sent_to_the_login_page(string $method, string $uri): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->call($method, $uri)
            ->assertRedirect(route('login'));
    }

    public function test_the_login_page_is_reachable_by_a_guest(): void
    {
        $this->get(route('login'))->assertOk();
    }
}
