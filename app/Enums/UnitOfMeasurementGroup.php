<?php

namespace App\Enums;

/**
 * What a unit of measurement measures.
 *
 * The plan does NOT store a group — it stores the unit, and the unit knows its
 * group. This exists so the picker can say what a unit is for ("Hectare (ha)"
 * under "Area") and so the catalogue can be listed in a readable order.
 *
 * Case order is the order the picker and the spreadsheet's reference tab list
 * the groups in, which is the order the client supplied them in.
 */
enum UnitOfMeasurementGroup: string
{
    case Time = 'time';
    case Quantity = 'quantity';
    case Area = 'area';
    case WeightVolume = 'weight_volume';
    case ProductionRate = 'production_rate';
    case Currency = 'currency';
    case Speed = 'speed';
    case Temperature = 'temperature';
    case Energy = 'energy';
    case Power = 'power';
    case Frequency = 'frequency';
    case FuelConsumption = 'fuel_consumption';
    case StorageCapacity = 'storage_capacity';
    case Other = 'other';

    public function labelEn(): string
    {
        return match ($this) {
            self::Time => 'Time',
            self::Quantity => 'Quantity',
            self::Area => 'Area',
            self::WeightVolume => 'Weight/Volume',
            self::ProductionRate => 'Production Rate',
            self::Currency => 'Currency',
            self::Speed => 'Speed',
            self::Temperature => 'Temperature',
            self::Energy => 'Energy',
            self::Power => 'Power',
            self::Frequency => 'Frequency',
            self::FuelConsumption => 'Fuel Consumption',
            self::StorageCapacity => 'Storage Capacity',
            self::Other => 'Other',
        };
    }

    public function labelId(): string
    {
        return match ($this) {
            self::Time => 'Waktu',
            self::Quantity => 'Kuantitas',
            self::Area => 'Luas',
            self::WeightVolume => 'Berat/Volume',
            self::ProductionRate => 'Laju Produksi',
            self::Currency => 'Mata Uang',
            self::Speed => 'Kecepatan',
            self::Temperature => 'Suhu',
            self::Energy => 'Energi',
            self::Power => 'Daya',
            self::Frequency => 'Frekuensi',
            self::FuelConsumption => 'Konsumsi Bahan Bakar',
            self::StorageCapacity => 'Kapasitas Penyimpanan',
            self::Other => 'Lainnya',
        };
    }
}
