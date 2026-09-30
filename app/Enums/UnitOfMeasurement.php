<?php

namespace App\Enums;

/**
 * The unit a development plan's TARGET is counted in — the catalogue the client
 * supplied, 55 units across 14 groups.
 *
 * Deliberately an enum rather than another master table: the list is fixed,
 * shared by every employee and not something the business edits day to day, so
 * it needs none of the master machinery (its own screen, an active flag, delete
 * guards, an importer). If the client ever needs to edit it themselves, this is
 * the one place that defines it and it can be promoted to a master table then.
 *
 * A plan stores the BACKED VALUE, not a label — unlike the masters, whose name
 * is stored verbatim. That is what lets the same row read "Hectare (ha)" in
 * English and "Hektare (ha)" in Indonesian, and what keeps a stored unit
 * readable if a label is ever reworded.
 *
 * This enum is the SINGLE SOURCE for both languages: the labels travel to the
 * UI in the page payload rather than being restated in `Config/locales`, the
 * way master data already works here. At 55 units in two languages, a second
 * copy in the locale files would be a drift hazard, not a convenience.
 */
enum UnitOfMeasurement: string
{
    // Time
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    // Quantity
    case Unit = 'unit';
    case Number = 'number';

    // Area
    case Hectare = 'hectare';
    case SquareMeter = 'square_meter';

    // Weight / Volume
    case Kilogram = 'kilogram';
    case MetricTon = 'metric_ton';
    case Liter = 'liter';

    // Production rate
    case UnitPerHour = 'unit_per_hour';
    case ItemPerMinute = 'item_per_minute';
    case TonPerHour = 'ton_per_hour';

    // Currency
    case Rupiah = 'rupiah';
    case Dollar = 'dollar';
    case Euro = 'euro';
    case PoundSterling = 'pound_sterling';
    case Yen = 'yen';
    case Rupee = 'rupee';

    // Speed
    case KilometerPerHour = 'kilometer_per_hour';
    case MeterPerSecond = 'meter_per_second';
    case MilePerHour = 'mile_per_hour';

    // Temperature
    case Celsius = 'celsius';
    case Fahrenheit = 'fahrenheit';
    case Kelvin = 'kelvin';

    // Energy
    case KilowattHour = 'kilowatt_hour';
    case Megajoule = 'megajoule';
    case Kilojoule = 'kilojoule';
    case Joule = 'joule';
    case Kilocalorie = 'kilocalorie';
    case Calorie = 'calorie';

    // Power
    case Kilowatt = 'kilowatt';
    case Watt = 'watt';
    case Horsepower = 'horsepower';

    // Frequency
    case Gigahertz = 'gigahertz';
    case Megahertz = 'megahertz';
    case Kilohertz = 'kilohertz';
    case Hertz = 'hertz';

    // Fuel consumption
    case KilometerPerLiter = 'kilometer_per_liter';
    case LiterPer100Kilometer = 'liter_per_100_kilometer';

    // Storage capacity
    case Terabyte = 'terabyte';
    case Gigabyte = 'gigabyte';
    case Megabyte = 'megabyte';
    case Kilobyte = 'kilobyte';

    // Other
    case Percent = 'percent';
    case DollarPerYear = 'dollar_per_year';
    case DollarPerMonth = 'dollar_per_month';
    case DollarPerUnit = 'dollar_per_unit';
    case RupiahPerYear = 'rupiah_per_year';
    case RupiahPerMonth = 'rupiah_per_month';
    case RupiahPerUnit = 'rupiah_per_unit';
    case Event = 'event';
    case Person = 'person';
    case Other = 'other';

    /**
     * value => [group, English label, Indonesian label].
     *
     * One table rather than three `match` expressions of 55 arms each: a unit's
     * three facts are read together and are easiest to keep right on one line.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const CATALOGUE = [
        'day' => ['time', 'Day (d)', 'Hari (h)'],
        'week' => ['time', 'Week', 'Minggu'],
        'month' => ['time', 'Month', 'Bulan'],
        'year' => ['time', 'Year (yr)', 'Tahun (thn)'],

        'unit' => ['quantity', 'Unit', 'Unit'],
        'number' => ['quantity', 'Number', 'Jumlah'],

        'hectare' => ['area', 'Hectare (ha)', 'Hektare (ha)'],
        'square_meter' => ['area', 'Square Meter (m²)', 'Meter Persegi (m²)'],

        'kilogram' => ['weight_volume', 'Kilogram (kg)', 'Kilogram (kg)'],
        'metric_ton' => ['weight_volume', 'Metric ton (t)', 'Ton Metrik (t)'],
        'liter' => ['weight_volume', 'Liter (L)', 'Liter (L)'],

        'unit_per_hour' => ['production_rate', 'Unit per Hour (unit/h)', 'Unit per Jam (unit/jam)'],
        'item_per_minute' => ['production_rate', 'Item per Minute (item/min)', 'Item per Menit (item/mnt)'],
        'ton_per_hour' => ['production_rate', 'Ton per Hour (t/h)', 'Ton per Jam (t/jam)'],

        'rupiah' => ['currency', 'Rupiah (Rp)', 'Rupiah (Rp)'],
        'dollar' => ['currency', 'Dollar ($)', 'Dolar ($)'],
        'euro' => ['currency', 'Euro (€)', 'Euro (€)'],
        'pound_sterling' => ['currency', 'Pound Sterling (£)', 'Pound Sterling (£)'],
        'yen' => ['currency', 'Yen (¥)', 'Yen (¥)'],
        'rupee' => ['currency', 'Rupee (₹)', 'Rupee (₹)'],

        'kilometer_per_hour' => ['speed', 'Kilometer per Hour (km/h)', 'Kilometer per Jam (km/jam)'],
        'meter_per_second' => ['speed', 'Meter per Second (m/s)', 'Meter per Detik (m/dtk)'],
        'mile_per_hour' => ['speed', 'Mile per Hour (mph)', 'Mil per Jam (mph)'],

        'celsius' => ['temperature', 'Degree Celsius (°C)', 'Derajat Celsius (°C)'],
        'fahrenheit' => ['temperature', 'Degree Fahrenheit (°F)', 'Derajat Fahrenheit (°F)'],
        'kelvin' => ['temperature', 'Kelvin (K)', 'Kelvin (K)'],

        'kilowatt_hour' => ['energy', 'Kilowatt-hour (kWh)', 'Kilowatt-jam (kWh)'],
        'megajoule' => ['energy', 'Megajoule (MJ)', 'Megajoule (MJ)'],
        'kilojoule' => ['energy', 'Kilojoule (kJ)', 'Kilojoule (kJ)'],
        'joule' => ['energy', 'Joule (J)', 'Joule (J)'],
        'kilocalorie' => ['energy', 'Kilocalorie (kcal)', 'Kilokalori (kkal)'],
        'calorie' => ['energy', 'Calorie (cal)', 'Kalori (kal)'],

        'kilowatt' => ['power', 'Kilowatt (kW)', 'Kilowatt (kW)'],
        'watt' => ['power', 'Watt (W)', 'Watt (W)'],
        'horsepower' => ['power', 'Horsepower (hp)', 'Tenaga Kuda (hp)'],

        'gigahertz' => ['frequency', 'Gigahertz (GHz)', 'Gigahertz (GHz)'],
        'megahertz' => ['frequency', 'Megahertz (MHz)', 'Megahertz (MHz)'],
        'kilohertz' => ['frequency', 'Kilohertz (kHz)', 'Kilohertz (kHz)'],
        'hertz' => ['frequency', 'Hertz (Hz)', 'Hertz (Hz)'],

        'kilometer_per_liter' => ['fuel_consumption', 'Kilometer per Liter (km/L)', 'Kilometer per Liter (km/L)'],
        'liter_per_100_kilometer' => ['fuel_consumption', 'Liter per 100 Kilometer (L/100km)', 'Liter per 100 Kilometer (L/100km)'],

        'terabyte' => ['storage_capacity', 'Terabyte (TB)', 'Terabyte (TB)'],
        'gigabyte' => ['storage_capacity', 'Gigabyte (GB)', 'Gigabyte (GB)'],
        'megabyte' => ['storage_capacity', 'Megabyte (MB)', 'Megabyte (MB)'],
        'kilobyte' => ['storage_capacity', 'Kilobyte (KB)', 'Kilobyte (KB)'],

        'percent' => ['other', 'Percent (%)', 'Persen (%)'],
        'dollar_per_year' => ['other', 'Dollar per Year ($/year)', 'Dolar per Tahun ($/thn)'],
        'dollar_per_month' => ['other', 'Dollar per Month ($/month)', 'Dolar per Bulan ($/bln)'],
        'dollar_per_unit' => ['other', 'Dollar per Unit ($/unit)', 'Dolar per Unit ($/unit)'],
        'rupiah_per_year' => ['other', 'Rupiah per Year (Rp/year)', 'Rupiah per Tahun (Rp/thn)'],
        'rupiah_per_month' => ['other', 'Rupiah per Month (Rp/month)', 'Rupiah per Bulan (Rp/bln)'],
        // The supplied list wrote this one's abbreviation as ($/unit); read
        // against its own name that is a copy-paste of the dollar row above, so
        // it is spelled (Rp/unit) here.
        'rupiah_per_unit' => ['other', 'Rupiah per Unit (Rp/unit)', 'Rupiah per Unit (Rp/unit)'],
        'event' => ['other', 'Event', 'Kegiatan'],
        'person' => ['other', 'Person', 'Orang'],
        'other' => ['other', 'Other', 'Lainnya'],
    ];

    public function group(): UnitOfMeasurementGroup
    {
        return UnitOfMeasurementGroup::from(self::CATALOGUE[$this->value][0]);
    }

    public function labelEn(): string
    {
        return self::CATALOGUE[$this->value][1];
    }

    public function labelId(): string
    {
        return self::CATALOGUE[$this->value][2];
    }

    /**
     * The backed values, for validation.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The catalogue as the UI receives it: one row per unit, carrying both
     * languages and the group it belongs to, in the client's own order.
     *
     * @return array<int, array{value: string, label_en: string, label_id: string, group: string, group_en: string, group_id: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $unit) => [
            'value' => $unit->value,
            'label_en' => $unit->labelEn(),
            'label_id' => $unit->labelId(),
            'group' => $unit->group()->value,
            'group_en' => $unit->group()->labelEn(),
            'group_id' => $unit->group()->labelId(),
        ], self::cases());
    }

    /**
     * Resolve whatever a spreadsheet typed: the backed value, either language's
     * label, or a label's abbreviation on its own ("kg", "m²", "km/h") — which
     * is what anyone filling in a unit column actually writes. Case and spacing
     * are ignored. Null when it names no unit.
     */
    public static function tryFromLoose(?string $raw): ?self
    {
        $needle = mb_strtolower(trim((string) $raw));

        if ($needle === '') {
            return null;
        }

        return self::index()[$needle] ?? null;
    }

    /**
     * Every spelling that resolves to a unit. Built once per request; the FIRST
     * unit to claim a spelling keeps it, so a shared abbreviation can never
     * make the result depend on iteration order.
     *
     * @return array<string, self>
     */
    private static function index(): array
    {
        static $index = null;

        if ($index !== null) {
            return $index;
        }

        $index = [];

        foreach (self::cases() as $unit) {
            $spellings = [$unit->value, $unit->labelEn(), $unit->labelId()];

            // "Kilogram (kg)" also answers to "kg".
            foreach ([$unit->labelEn(), $unit->labelId()] as $label) {
                if (preg_match('/\(([^)]+)\)\s*$/u', $label, $m)) {
                    $spellings[] = $m[1];
                }
            }

            foreach ($spellings as $spelling) {
                $key = mb_strtolower(trim($spelling));

                if ($key !== '' && ! isset($index[$key])) {
                    $index[$key] = $unit;
                }
            }
        }

        return $index;
    }
}
