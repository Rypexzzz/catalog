<?php

namespace Ithive\Goalsbazhen\ServiceCatalog;

class CartService
{
    private string $cartKey;
    private Repository $repo;

    public function __construct(Repository $repo, ?int $userId = null)
    {
        $this->repo    = $repo;
        $suffix         = $userId ?: session_id();
        $this->cartKey  = 'SERVICE_CART_' . $suffix;

        if (!isset($_SESSION[$this->cartKey])) {
            $_SESSION[$this->cartKey] = [];
        }
    }

    public function getCartKey(): string
    {
        return $this->cartKey;
    }

    public function getAll(): array
    {
        return $_SESSION[$this->cartKey] ?? [];
    }

    public function get(int $id): ?array
    {
        return $_SESSION[$this->cartKey][$id] ?? null;
    }

    public function has(int $id): bool
    {
        return isset($_SESSION[$this->cartKey][$id]);
    }

    public function isEmpty(): bool
    {
        return empty($_SESSION[$this->cartKey]);
    }

    public function getTotal(): float
    {
        return CostCalculator::cartTotal($this->getAll());
    }


    public function canAddModel(string $newRootCode): bool
    {
        $models = $this->getModelsInCart();

        if ($newRootCode === 'cascade' && in_array('agile', $models, true)) return false;
        if ($newRootCode === 'agile' && in_array('cascade', $models, true)) return false;

        return true;
    }

    public function hasModelConflict(): bool
    {
        $models = $this->getModelsInCart();
        return in_array('cascade', $models, true) && in_array('agile', $models, true);
    }

    private function getModelsInCart(): array
    {
        $models = [];
        foreach ($this->getAll() as $svc) {
            if (!empty($svc['ROOT_SECTION_CODE'])) {
                $models[] = $svc['ROOT_SECTION_CODE'];
            }
        }
        return array_unique($models);
    }

    public function add(int $serviceId, array $options = []): bool
    {
        if ($this->has($serviceId)) return false;

        $el = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $this->repo->getServicesIblockId(), 'ID' => $serviceId],
            false, false, ['ID', 'NAME']
        )->Fetch();

        if (!$el) return false;

        [$composition] = $this->repo->getServiceComposition($serviceId);
        $grades = $this->repo->getGrades();

        $hoursData  = $options['hours']  ?? [];
        $gradesData = $options['grades'] ?? [];
        $level      = $options['level']  ?? CostCalculator::LEVEL_MEDIUM;

        foreach ($composition as $roleId => &$role) {
            if (isset($hoursData[$roleId])) {
                $role['HOURS'] = max(0, (int)$hoursData[$roleId]);
            }
            if (isset($gradesData[$roleId], $grades[$gradesData[$roleId]])) {
                $role['GRADE_ID'] = (int)$gradesData[$roleId];
                $role['RATE']     = $grades[$gradesData[$roleId]]['RATE'];
            }
            $role['COST'] = CostCalculator::roleCost($role['RATE'], $role['HOURS']);
        }
        unset($role);

        $_SESSION[$this->cartKey][$serviceId] = [
            'NAME'              => $el['NAME'],
            'ROLES'             => $composition,
            'SERVICE_LEVEL'     => $level,
            'ROOT_SECTION_CODE' => $options['rootSection']  ?? '',
            'SECTION_NAME'      => $options['sectionName']  ?? '',
        ];

        return true;
    }

    public function remove(int $id): void
    {
        unset($_SESSION[$this->cartKey][$id]);
    }

    public function clear(): void
    {
        $_SESSION[$this->cartKey] = [];
    }

    /**
     * Полная замена корзины (загрузка черновика).
     */
    public function replace(array $data): void
    {
        $_SESSION[$this->cartKey] = $data;
    }

    public function updateHours(int $serviceId, int $roleId, int $hours): ?array
    {
        $hours = max(0, $hours);
        if (!isset($_SESSION[$this->cartKey][$serviceId]['ROLES'][$roleId])) {
            return null;
        }

        $svc  = &$_SESSION[$this->cartKey][$serviceId];
        $rate = $svc['ROLES'][$roleId]['RATE'] ?? 0;

        $svc['ROLES'][$roleId]['HOURS'] = $hours;
        $svc['ROLES'][$roleId]['COST']  = CostCalculator::roleCost($rate, $hours);

        $level = $svc['SERVICE_LEVEL'] ?? CostCalculator::LEVEL_MEDIUM;
        $coeff = CostCalculator::getLevelCoefficient($level);

        $result = [
            'serviceTotal' => CostCalculator::serviceCost($svc['ROLES'], $level),
            'roleCost'     => round($rate * $hours * $coeff),
            'total'        => $this->getTotal(),
        ];
        unset($svc);
        return $result;
    }

    /**
     * Обновить категорию для роли.
     * @return array|null {serviceTotal, roleCost, roleRate, total}
     */
    public function updateGrade(int $serviceId, int $roleId, int $gradeId): ?array
    {
        $grades = $this->repo->getGrades();
        if (!isset($_SESSION[$this->cartKey][$serviceId]['ROLES'][$roleId], $grades[$gradeId])) {
            return null;
        }

        $svc     = &$_SESSION[$this->cartKey][$serviceId];
        $newRate = $grades[$gradeId]['RATE'];
        $hours   = $svc['ROLES'][$roleId]['HOURS'];

        $svc['ROLES'][$roleId]['GRADE_ID'] = $gradeId;
        $svc['ROLES'][$roleId]['RATE']     = $newRate;
        $svc['ROLES'][$roleId]['COST']     = CostCalculator::roleCost($newRate, $hours);

        $level = $svc['SERVICE_LEVEL'] ?? CostCalculator::LEVEL_MEDIUM;
        $coeff = CostCalculator::getLevelCoefficient($level);

        $result = [
            'serviceTotal' => CostCalculator::serviceCost($svc['ROLES'], $level),
            'roleCost'     => round($newRate * $hours * $coeff),
            'roleRate'     => $newRate,
            'total'        => $this->getTotal(),
        ];
        unset($svc);
        return $result;
    }

    public function updateLevel(int $serviceId, string $level): ?array
    {
        if (!isset($_SESSION[$this->cartKey][$serviceId])) return null;

        $_SESSION[$this->cartKey][$serviceId]['SERVICE_LEVEL'] = $level;

        $svc = $_SESSION[$this->cartKey][$serviceId];
        return [
            'serviceTotal' => CostCalculator::serviceCost($svc['ROLES'], $level),
            'total'        => $this->getTotal(),
        ];
    }
}
