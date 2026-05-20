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

    public static function levelLabel(string $level): string
    {
        return self::LEVEL_LABELS[$level] ?? self::LEVEL_LABELS[self::LEVEL_MEDIUM];
    }

    /**
     * Стоимость одного назначения.
     * Если у назначения нет специалиста или у специалиста нулевая ставка — 0.
     *
     * @param array $assignment ['specialistId' => string|null, 'hours' => float]
     * @param array $team       [specialistId => ['rate' => float, ...]]
     */
    public static function assignmentCost(array $assignment, array $team): float
    {
        $specId = $assignment['specialistId'] ?? null;
        if ($specId === null || !isset($team[$specId])) {
            return 0.0;
        }
        $rate  = (float)($team[$specId]['rate'] ?? 0);
        $hours = (float)($assignment['hours'] ?? 0);
        return $rate * $hours;
    }

    /**
     * Стоимость роли в услуге — сумма стоимостей всех назначений.
     */
    public static function roleCost(array $role, array $team): float
    {
        $sum = 0.0;
        foreach (($role['assignments'] ?? []) as $a) {
            $sum += self::assignmentCost($a, $team);
        }
        return $sum;
    }

    /**
     * Стоимость услуги — сумма ролей × коэффициент уровня обслуживания.
     */
    public static function serviceCost(array $service, array $team): float
    {
        $base = 0.0;
        foreach (($service['roles'] ?? []) as $role) {
            $base += self::roleCost($role, $team);
        }
        $level = (string)($service['serviceLevel'] ?? self::LEVEL_MEDIUM);
        return round($base * self::getLevelCoefficient($level));
    }

    /**
     * Итоговая сумма корзины: сумма по всем услугам.
     *
     * @param array $cart ['team' => [...], 'services' => [serviceId => ...]]
     */
    public static function cartTotal(array $cart): float
    {
        $team  = self::indexTeam($cart['team'] ?? []);
        $total = 0.0;
        foreach (($cart['services'] ?? []) as $service) {
            $total += self::serviceCost($service, $team);
        }
        return round($total);
    }

    /**
     * Преобразовать массив команды в словарь по specialistId.
     * Принимает как уже индексированный массив, так и список объектов.
     */
    public static function indexTeam(array $team): array
    {
        if (empty($team)) {
            return [];
        }
        $first = reset($team);
        if (is_array($first) && isset($first['id'])) {
            $indexed = [];
            foreach ($team as $member) {
                $indexed[$member['id']] = $member;
            }
            return $indexed;
        }
        return $team;
    }
}
