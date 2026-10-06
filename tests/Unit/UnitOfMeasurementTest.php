<?php

namespace Tests\Unit;

use App\Enums\UnitOfMeasurement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The unit catalogue a plan's target and achievement are counted in. A plan
 * stores the backed value; the spreadsheet import resolves whatever a person
 * types (a label in either language, or just its abbreviation).
 */
class UnitOfMeasurementTest extends TestCase
{
    public function test_the_catalogue_is_complete_and_unambiguous(): void
    {
        $units = UnitOfMeasurement::cases();

        $this->assertCount(55, $units);
        $this->assertCount(14, array_unique(array_map(fn ($u) => $u->group()->value, $units)));

        foreach ($units as $unit) {
            $this->assertNotSame('', trim($unit->labelEn()), "{$unit->value} has no English label");
            $this->assertNotSame('', trim($unit->labelId()), "{$unit->value} has no Indonesian label");
        }

        // Labels must not collide, or a typed label could resolve to the wrong unit.
        $en = array_map(fn ($u) => mb_strtolower($u->labelEn()), $units);
        $id = array_map(fn ($u) => mb_strtolower($u->labelId()), $units);
        $this->assertSame(count($en), count(array_unique($en)));
        $this->assertSame(count($id), count(array_unique($id)));
    }

    public function test_every_unit_is_reachable_by_its_value_and_both_labels(): void
    {
        foreach (UnitOfMeasurement::cases() as $unit) {
            $this->assertSame($unit, UnitOfMeasurement::tryFromLoose($unit->value));
            $this->assertSame($unit, UnitOfMeasurement::tryFromLoose($unit->labelEn()));
            $this->assertSame($unit, UnitOfMeasurement::tryFromLoose($unit->labelId()));
        }
    }

    /** @return array<string, array{string, string}> */
    public static function abbreviations(): array
    {
        return [
            'hectare' => ['ha', 'Hectare'],
            'kilogram' => ['kg', 'Kilogram'],
            'square metre' => ['m²', 'Square'],
            'speed' => ['km/h', 'Kilometer'],
            'rupiah' => ['Rp', 'Rupiah'],
            'percent' => ['%', 'Percent'],
        ];
    }

    #[DataProvider('abbreviations')]
    public function test_an_abbreviation_alone_resolves(string $typed, string $labelStartsWith): void
    {
        $unit = UnitOfMeasurement::tryFromLoose($typed);

        $this->assertNotNull($unit, "'{$typed}' did not resolve");
        $this->assertStringStartsWith($labelStartsWith, $unit->labelEn());
    }

    public function test_case_and_spacing_are_ignored_and_junk_resolves_to_nothing(): void
    {
        $this->assertSame(UnitOfMeasurement::tryFromLoose('kg'), UnitOfMeasurement::tryFromLoose('  KG '));
        $this->assertNull(UnitOfMeasurement::tryFromLoose('bananas'));
        $this->assertNull(UnitOfMeasurement::tryFromLoose(''));
        $this->assertNull(UnitOfMeasurement::tryFromLoose(null));
    }
}
