<?php

namespace Tests\Unit;

use App\Models\BusinessUnit;
use PHPUnit\Framework\TestCase;

/**
 * Corporate tables spell business units their own way ("KPN Plantations");
 * every option list and import folds them onto the master's names.
 */
class BusinessUnitResolveNameTest extends TestCase
{
    private const NAMES = ['Plantations', 'Property', 'Cement', 'Katingan', 'KPN Corporation', 'Downstream', 'Others'];

    public function test_an_exact_match_wins_case_insensitively(): void
    {
        $this->assertSame('Property', BusinessUnit::resolveName('property', self::NAMES));
        $this->assertSame('KPN Corporation', BusinessUnit::resolveName(' kpn corporation ', self::NAMES));
    }

    public function test_a_longer_corporate_spelling_folds_onto_the_master_name(): void
    {
        $this->assertSame('Plantations', BusinessUnit::resolveName('KPN Plantations', self::NAMES));
    }

    public function test_an_exact_match_is_preferred_over_a_containing_one(): void
    {
        // "KPN Corporation" contains neither of the others, but must not be
        // matched by containment ahead of its own exact hit.
        $this->assertSame('KPN Corporation', BusinessUnit::resolveName('KPN Corporation', ['Corporation Group', 'KPN Corporation']));
    }

    public function test_a_unit_the_master_does_not_carry_resolves_to_nothing(): void
    {
        $this->assertNull(BusinessUnit::resolveName('KPN Sugar', self::NAMES));
        $this->assertNull(BusinessUnit::resolveName('   ', self::NAMES));
    }
}
