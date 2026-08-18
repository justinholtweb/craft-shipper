<?php

namespace justinholtweb\shipper\helpers;

use craft\commerce\Plugin as Commerce;

/**
 * Unit conversion between Commerce's store units and the two vocabularies ShipStation uses:
 * the Custom Store XML (`Pounds`/`Ounces`/`Grams`, `in`/`cm`) and the v2 REST API
 * (`pound`/`ounce`/`gram`/`kilogram`, `inch`/`centimeter`).
 */
abstract class Units
{
    /**
     * Grams per one unit of each weight unit Commerce offers.
     */
    private const WEIGHT_IN_GRAMS = [
        'g' => 1.0,
        'kg' => 1000.0,
        'lb' => 453.59237,
        'oz' => 28.349523125,
    ];

    /**
     * Millimetres per one unit of each dimension unit that turns up in the wild.
     */
    private const LENGTH_IN_MM = [
        'mm' => 1.0,
        'cm' => 10.0,
        'm' => 1000.0,
        'in' => 25.4,
        'ft' => 304.8,
    ];

    /**
     * The store's configured weight unit, lower-cased.
     */
    public static function storeWeightUnit(): string
    {
        $unit = Commerce::getInstance()?->getSettings()->weightUnits ?? 'g';

        return strtolower((string)$unit);
    }

    /**
     * The store's configured dimension unit, lower-cased.
     */
    public static function storeDimensionUnit(): string
    {
        $unit = Commerce::getInstance()?->getSettings()->dimensionUnits ?? 'mm';

        return strtolower((string)$unit);
    }

    public static function convertWeight(float $value, string $from, string $to): float
    {
        $from = strtolower($from);
        $to = strtolower($to);

        if ($value == 0.0 || $from === $to) {
            return $value;
        }

        $fromFactor = self::WEIGHT_IN_GRAMS[$from] ?? null;
        $toFactor = self::WEIGHT_IN_GRAMS[$to] ?? null;

        // An unrecognised unit is passed through untouched: a wrong number is worse than an
        // unconverted one, and ShipStation is told which unit it is receiving either way.
        if ($fromFactor === null || $toFactor === null) {
            return $value;
        }

        return ($value * $fromFactor) / $toFactor;
    }

    public static function convertLength(float $value, string $from, string $to): float
    {
        $from = strtolower($from);
        $to = strtolower($to);

        if ($value == 0.0 || $from === $to) {
            return $value;
        }

        $fromFactor = self::LENGTH_IN_MM[$from] ?? null;
        $toFactor = self::LENGTH_IN_MM[$to] ?? null;

        if ($fromFactor === null || $toFactor === null) {
            return $value;
        }

        return ($value * $fromFactor) / $toFactor;
    }

    /**
     * The unit the XML export should quote weights in, and the label ShipStation expects for it.
     * Metric stores stay metric (Grams); everything else is sent as Pounds.
     *
     * @return array{0: string, 1: string} `[commerce unit, ShipStation label]`
     */
    public static function xmlWeightUnit(): array
    {
        return match (self::storeWeightUnit()) {
            'g', 'kg' => ['g', 'Grams'],
            'oz' => ['oz', 'Ounces'],
            default => ['lb', 'Pounds'],
        };
    }

    /**
     * As above, for the v2 REST API's vocabulary.
     *
     * @return array{0: string, 1: string}
     */
    public static function apiWeightUnit(): array
    {
        return match (self::storeWeightUnit()) {
            'g' => ['g', 'gram'],
            'kg' => ['kg', 'kilogram'],
            'oz' => ['oz', 'ounce'],
            default => ['lb', 'pound'],
        };
    }

    /**
     * Dimension unit for the XML export.
     *
     * @return array{0: string, 1: string}
     */
    public static function xmlDimensionUnit(): array
    {
        return match (self::storeDimensionUnit()) {
            'mm', 'cm', 'm' => ['cm', 'cm'],
            default => ['in', 'in'],
        };
    }

    /**
     * Dimension unit for the v2 REST API.
     *
     * @return array{0: string, 1: string}
     */
    public static function apiDimensionUnit(): array
    {
        return match (self::storeDimensionUnit()) {
            'mm', 'cm', 'm' => ['cm', 'centimeter'],
            default => ['in', 'inch'],
        };
    }
}
