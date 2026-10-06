<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\EmployeeScopeService;
use App\Support\AccessCache;
use Illuminate\Database\Eloquent\Builder;
use Tests\TestCase;

/**
 * Who may see which employees for a data-access capability (here, the IDP):
 * Superadmin sees everyone; a scoped basic role sees its scope; a data-access
 * role that applies to the user grants their own record (ic_*) and/or their
 * team (pm_*); anyone reached by none of these sees nothing.
 *
 * No database: the data-access role list is primed into the cache it is read
 * from, the user's roles and corporate record are set in memory, and each case
 * asserts on the query the service BUILDS rather than running it.
 */
class EmployeeScopeServiceTest extends TestCase
{
    private const SELF = 'E100';

    /**
     * @param  list<array{business_unit?: list<string>, company?: list<string>, location?: list<string>, permissions: list<string>}>  $dataRoles
     * @param  list<Role>  $roles  the user's assigned (basic) roles
     */
    private function scenario(array $dataRoles, array $roles = [], string $unit = 'Plantations', string $team = 'E200|E201'): array
    {
        AccessCache::flush();
        AccessCache::remember('data-access-roles', fn () => array_map(fn (array $r) => $r + [
            'business_unit' => [], 'company' => [], 'location' => [],
        ], $dataRoles));

        $user = (new User)->forceFill(['id' => 1, 'employee_id' => self::SELF]);
        $user->setRelation('roles', collect($roles));

        $employee = (new Employee)->forceFill([
            'employee_id' => self::SELF,
            'group_company' => $unit,
            'company_name' => 'PT One',
            'office_area' => 'Jakarta',
            // Read by reporteeIds() before it would fall back to a query.
            'direct_reportee_employee_id' => $team,
        ]);

        $scope = new EmployeeScopeService;
        $memo = new \ReflectionProperty($scope, 'employees');
        $memo->setValue($scope, [self::SELF => $employee]);

        return [$scope, $user];
    }

    private function role(string $name, array $attributes = []): Role
    {
        return (new Role)->forceFill(array_merge(['name' => $name, 'is_data_access' => false], $attributes));
    }

    private function idp(EmployeeScopeService $scope, User $user): Builder
    {
        return $scope->accessibleQuery($user, ...EmployeeScopeService::IDP_VIEW);
    }

    public function test_superadmin_sees_everyone_whatever_the_spelling(): void
    {
        [$scope, $user] = $this->scenario([], [$this->role('superadmin')]);

        $query = $this->idp($scope, $user);

        $this->assertStringNotContainsString('where', strtolower($query->toSql()));
    }

    public function test_nobody_reached_sees_nothing(): void
    {
        [$scope, $user] = $this->scenario([]);

        $this->assertStringContainsString('1 = 0', $this->idp($scope, $user)->toSql());
    }

    public function test_an_unscoped_data_role_grants_the_own_record(): void
    {
        [$scope, $user] = $this->scenario([['permissions' => ['ic_view_idp']]]);

        $query = $this->idp($scope, $user);

        $this->assertSame([self::SELF], $query->getBindings());
    }

    public function test_the_people_manager_capability_grants_the_team(): void
    {
        [$scope, $user] = $this->scenario([['permissions' => ['pm_view_idp']]]);

        $this->assertEqualsCanonicalizing(['E200', 'E201'], $this->idp($scope, $user)->getBindings());
    }

    public function test_team_access_lets_a_manager_see_but_not_manage(): void
    {
        // pm_view_idp reaches L1 and L2 reports alike; managing a plan is for
        // layer 1 only, so the "may manage" form of the query leaves the team out.
        [$scope, $user] = $this->scenario([['permissions' => ['pm_view_idp']]]);

        $this->assertEqualsCanonicalizing(['E200', 'E201'], $this->idp($scope, $user)->getBindings());
        $this->assertStringContainsString(
            '1 = 0',
            $scope->accessibleQuery($user, ...[...EmployeeScopeService::IDP_VIEW, false])->toSql(),
        );
    }

    public function test_without_the_team_the_own_record_is_still_managed(): void
    {
        [$scope, $user] = $this->scenario([['permissions' => ['ic_view_idp', 'pm_view_idp']]]);

        $query = $scope->accessibleQuery($user, ...[...EmployeeScopeService::IDP_VIEW, false]);

        $this->assertSame([self::SELF], $query->getBindings());
    }

    public function test_a_data_role_scoped_elsewhere_does_not_apply(): void
    {
        [$scope, $user] = $this->scenario([['business_unit' => ['Property'], 'permissions' => ['ic_view_idp', 'pm_view_idp']]]);

        $this->assertSame([], $scope->effectiveDataPermissions($user));
        $this->assertStringContainsString('1 = 0', $this->idp($scope, $user)->toSql());
    }

    public function test_a_scoped_data_role_applies_only_when_every_dimension_matches(): void
    {
        [$scope, $user] = $this->scenario([
            ['business_unit' => ['Plantations'], 'company' => ['PT One'], 'permissions' => ['ic_view_idp']],
            ['business_unit' => ['Plantations'], 'company' => ['PT Two'], 'permissions' => ['pm_view_idp']],
        ]);

        $this->assertSame(['ic_view_idp'], $scope->effectiveDataPermissions($user));
    }

    public function test_a_scoped_basic_role_sees_its_scope(): void
    {
        [$scope, $user] = $this->scenario([], [$this->role('HC Site Cement', ['business_unit' => ['Cement']])]);

        $query = $this->idp($scope, $user);

        $this->assertStringContainsString('group_company', $query->toSql());
        $this->assertSame(['Cement'], $query->getBindings());
    }

    public function test_a_basic_role_without_a_scope_does_not_widen_visibility(): void
    {
        [$scope, $user] = $this->scenario([], [$this->role('Admin')]);

        $this->assertStringContainsString('1 = 0', $this->idp($scope, $user)->toSql());
    }

    public function test_the_idp_view_capabilities_are_the_ones_the_screen_uses(): void
    {
        $this->assertSame(['ic_view_idp', 'pm_view_idp'], EmployeeScopeService::IDP_VIEW);
    }
}
