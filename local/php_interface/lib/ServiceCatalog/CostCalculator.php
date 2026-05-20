<?php

namespace Ithive\Goalsbazhen\ServiceCatalog;


class CostCalculator
{
    public const LEVEL_LOW    = 'low';
    public const LEVEL_MEDIUM = 'medium';
    public const LEVEL_HIGH   = 'high';

    private const LEVEL_COEFFICIENTS = [
        self::LEVEL_LOW    => 0.77,
        self::LEVEL_MEDIUM => 1.0,
        self::LEVEL_HIGH   => 1.3,
    ];

    public const LEVEL_LABELS = [
        self::LEVEL_LOW    => 'Низкий',
        self::LEVEL_MEDIUM => 'Средний',
        self::LEVEL_HIGH   => 'Высокий',
    ];


    public static function getLevelCoefficient(string $level): float
    {
        return self::LEVEL_COEFFICIENTS[$level] ?? 1.0;
    }


    public static function roleCost(float $rate, float $hours): float
    {
        return $rate * $hours;
    }


    public static function serviceCost(array $roles, string $level = self::LEVEL_MEDIUM): float
    {
        $base = 0.0;
        foreach ($roles as $role) {
            $base += self::roleCost(
                (float)($role['RATE'] ?? 0),
                (float)($role['HOURS'] ?? 0)
            );
        }
        return round($base * self::getLevelCoefficient($level));
    }


    public static function cartTotal(array $cart): float
    {
        $total = 0.0;
        foreach ($cart as $service) {
            $total += self::serviceCost(
                $service['ROLES'] ?? [],
                $service['SERVICE_LEVEL'] ?? self::LEVEL_MEDIUM
            );
        }
        return round($total);
    }


    public static function levelLabel(string $level): string
    {
        return self::LEVEL_LABELS[$level] ?? self::LEVEL_LABELS[self::LEVEL_MEDIUM];
    }
}