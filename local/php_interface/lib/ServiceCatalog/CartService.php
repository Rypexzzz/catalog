<?php

namespace Ithive\Goalsbazhen\ServiceCatalog;

/**
 * Корзина — это {team, services}.
 *
 * team — список специалистов проекта (битриксовые пользователи + ставка + опц. грейд).
 * services — выбранные пользователем услуги; внутри каждой услуги массив ролей,
 * у каждой роли массив assignments (специалист + часы).
 *
 * Старый шаблон/Excel ожидают «плоский» формат с готовыми RATE/COST на каждой
 * роли. До переезда UI на новую модель это даёт getLegacyView().
 */
class CartService
{
    private string $cartKey;
    private Repository $repo;

    public function __construct(Repository $repo, ?int $userId = null)
    {
        $this->repo    = $repo;
        $suffix         = $userId ?: session_id();
        $this->cartKey  = 'SERVICE_CART_' . $suffix;

        if (!isset($_SESSION[$this->cartKey]) || !is_array($_SESSION[$this->cartKey])) {
            $_SESSION[$this->cartKey] = self::emptyCart();
        } else {
            // Гарантируем структуру для существующих сессий.
            $_SESSION[$this->cartKey] += self::emptyCart();
            if (!isset($_SESSION[$this->cartKey]['team']) || !is_array($_SESSION[$this->cartKey]['team'])) {
                $_SESSION[$this->cartKey]['team'] = [];
            }
            if (!isset($_SESSION[$this->cartKey]['services']) || !is_array($_SESSION[$this->cartKey]['services'])) {
                $_SESSION[$this->cartKey]['services'] = [];
            }
        }
    }

    private static function emptyCart(): array
    {
        return [
            'version'  => 2,
            'team'     => [],
            'services' => [],
        ];
    }

    public function getCartKey(): string
    {
        return $this->cartKey;
    }

    /* ================================================================
       РАЗРАБОТАННЫЙ API
       ================================================================ */

    /** Полное состояние корзины: ['version', 'team', 'services']. */
    public function getRaw(): array
    {
        return $_SESSION[$this->cartKey];
    }

    /** @return array<int, array> indexed by serviceId */
    public function getServices(): array
    {
        return $_SESSION[$this->cartKey]['services'] ?? [];
    }

    /** @return array<int, array> indexed by specialistId */
    public function getTeam(): array
    {
        $team = [];
        foreach ($_SESSION[$this->cartKey]['team'] ?? [] as $member) {
            $team[$member['id']] = $member;
        }
        return $team;
    }

    public function getService(int $id): ?array
    {
        return $_SESSION[$this->cartKey]['services'][$id] ?? null;
    }

    public function hasService(int $id): bool
    {
        return isset($_SESSION[$this->cartKey]['services'][$id]);
    }

    public function isEmpty(): bool
    {
        return empty($_SESSION[$this->cartKey]['services']);
    }

    public function getTotal(): float
    {
        return CostCalculator::cartTotal($this->getRaw());
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
        foreach ($this->getServices() as $svc) {
            if (!empty($svc['rootSectionCode'])) {
                $models[] = $svc['rootSectionCode'];
            }
        }
        return array_unique($models);
    }

    /* ================================================================
       КОМАНДА ПРОЕКТА
       ================================================================ */

    /**
     * Добавить специалиста в команду.
     * Один и тот же bitrixUserId не может встречаться дважды.
     *
     * @return array ['success' => bool, 'specialist' => array|null, 'error' => string|null]
     */
    public function addTeamMember(int $bitrixUserId, int $rate, ?int $gradeId = null): array
    {
        if ($bitrixUserId <= 0) {
            return ['success' => false, 'error' => 'Не указан пользователь'];
        }
        if ($rate < 0) {
            return ['success' => false, 'error' => 'Ставка не может быть отрицательной'];
        }

        foreach ($_SESSION[$this->cartKey]['team'] as $member) {
            if ((int)$member['bitrixUserId'] === $bitrixUserId) {
                return ['success' => false, 'error' => 'Этот пользователь уже в команде'];
            }
        }

        $specialist = [
            'id'            => self::generateId('sp_'),
            'bitrixUserId'  => $bitrixUserId,
            'rate'          => $rate,
            'gradeId'       => $gradeId ?: null,
        ];
        $_SESSION[$this->cartKey]['team'][] = $specialist;

        return ['success' => true, 'specialist' => $specialist];
    }

    public function updateTeamMember(string $specialistId, array $data): ?array
    {
        foreach ($_SESSION[$this->cartKey]['team'] as &$member) {
            if ($member['id'] === $specialistId) {
                if (array_key_exists('rate', $data)) {
                    $member['rate'] = max(0, (int)$data['rate']);
                }
                if (array_key_exists('gradeId', $data)) {
                    $member['gradeId'] = $data['gradeId'] ? (int)$data['gradeId'] : null;
                }
                return $member;
            }
        }
        return null;
    }

    /**
     * Удалить специалиста и каскадно снять все его назначения.
     *
     * @return array ['removed' => bool, 'affectedAssignments' => int, 'affectedServices' => int]
     */
    public function removeTeamMember(string $specialistId): array
    {
        $removed = false;
        $newTeam = [];
        foreach ($_SESSION[$this->cartKey]['team'] as $member) {
            if ($member['id'] === $specialistId) {
                $removed = true;
                continue;
            }
            $newTeam[] = $member;
        }
        $_SESSION[$this->cartKey]['team'] = $newTeam;

        $assignmentsRemoved = 0;
        $servicesAffected   = [];
        foreach ($_SESSION[$this->cartKey]['services'] as $serviceId => &$service) {
            foreach ($service['roles'] as &$role) {
                $before = count($role['assignments'] ?? []);
                $role['assignments'] = array_values(array_filter(
                    $role['assignments'] ?? [],
                    fn($a) => ($a['specialistId'] ?? null) !== $specialistId
                ));
                $diff = $before - count($role['assignments']);
                if ($diff > 0) {
                    $assignmentsRemoved += $diff;
                    $servicesAffected[$serviceId] = true;
                }
            }
            unset($role);
        }
        unset($service);

        return [
            'removed'              => $removed,
            'affectedAssignments'  => $assignmentsRemoved,
            'affectedServices'     => count($servicesAffected),
        ];
    }

    /**
     * Сколько назначений у специалиста (для подтверждающего диалога).
     */
    public function countAssignmentsForSpecialist(string $specialistId): array
    {
        $assignments = 0;
        $services    = [];
        foreach ($_SESSION[$this->cartKey]['services'] as $serviceId => $service) {
            foreach ($service['roles'] as $role) {
                foreach ($role['assignments'] ?? [] as $a) {
                    if (($a['specialistId'] ?? null) === $specialistId) {
                        $assignments++;
                        $services[$serviceId] = true;
                    }
                }
            }
        }
        return ['assignments' => $assignments, 'services' => count($services)];
    }

    /* ================================================================
       УСЛУГИ
       ================================================================ */

    /**
     * Добавить услугу в корзину. Каждой роли создаётся одно пустое назначение
     * (specialistId=null, hours=stdHours).
     */
    public function addService(int $serviceId, array $options = []): bool
    {
        if ($this->hasService($serviceId)) {
            return false;
        }

        $el = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $this->repo->getServicesIblockId(), 'ID' => $serviceId],
            false, false, ['ID', 'NAME']
        )->Fetch();

        if (!$el) {
            return false;
        }

        [$composition] = $this->repo->getServiceComposition($serviceId);

        $level = $options['level'] ?? CostCalculator::LEVEL_MEDIUM;
        if (!array_key_exists($level, CostCalculator::LEVEL_LABELS)) {
            $level = CostCalculator::LEVEL_MEDIUM;
        }

        $roles = [];
        foreach ($composition as $roleId => $role) {
            $roles[$roleId] = [
                'roleId'     => (int)$roleId,
                'roleName'   => $role['ROLE_NAME'] ?? '',
                'stdHours'   => (float)($role['STD_HOURS'] ?? $role['HOURS'] ?? 0),
                'result'     => $role['RESULT'] ?? '',
                'assignments' => [[
                    'id'           => self::generateId('as_'),
                    'specialistId' => null,
                    'hours'        => (float)($role['STD_HOURS'] ?? $role['HOURS'] ?? 0),
                ]],
            ];
        }

        $_SESSION[$this->cartKey]['services'][$serviceId] = [
            'name'             => $el['NAME'],
            'serviceLevel'     => $level,
            'rootSectionCode'  => $options['rootSection'] ?? '',
            'sectionName'      => $options['sectionName'] ?? '',
            'roles'            => $roles,
        ];

        return true;
    }

    public function removeService(int $id): void
    {
        unset($_SESSION[$this->cartKey]['services'][$id]);
    }

    public function clear(): void
    {
        $_SESSION[$this->cartKey] = self::emptyCart();
    }

    public function replace(array $data): void
    {
        $version = (int)($data['version'] ?? 0);
        if ($version !== 2 || !isset($data['team'], $data['services'])) {
            $_SESSION[$this->cartKey] = self::emptyCart();
            return;
        }
        $_SESSION[$this->cartKey] = [
            'version'  => 2,
            'team'     => array_values($data['team']),
            'services' => $data['services'],
        ];
    }

    public function updateServiceLevel(int $serviceId, string $level): ?array
    {
        if (!isset($_SESSION[$this->cartKey]['services'][$serviceId])) {
            return null;
        }
        if (!array_key_exists($level, CostCalculator::LEVEL_LABELS)) {
            return null;
        }
        $_SESSION[$this->cartKey]['services'][$serviceId]['serviceLevel'] = $level;

        $service = $_SESSION[$this->cartKey]['services'][$serviceId];
        $team    = $this->getTeam();
        return [
            'serviceTotal' => CostCalculator::serviceCost($service, $team),
            'total'        => $this->getTotal(),
        ];
    }

    /* ================================================================
       НАЗНАЧЕНИЯ
       ================================================================ */

    /**
     * Добавить новое назначение к роли услуги.
     */
    public function addAssignment(int $serviceId, int $roleId, ?string $specialistId = null, ?float $hours = null): ?array
    {
        if (!isset($_SESSION[$this->cartKey]['services'][$serviceId]['roles'][$roleId])) {
            return null;
        }
        $role = &$_SESSION[$this->cartKey]['services'][$serviceId]['roles'][$roleId];

        if ($specialistId !== null) {
            foreach ($role['assignments'] as $a) {
                if (($a['specialistId'] ?? null) === $specialistId) {
                    return null;
                }
            }
        }

        $assignment = [
            'id'           => self::generateId('as_'),
            'specialistId' => $specialistId,
            'hours'        => $hours !== null ? max(0, $hours) : (float)($role['stdHours'] ?? 0),
        ];
        $role['assignments'][] = $assignment;
        unset($role);

        return $assignment;
    }

    public function updateAssignment(int $serviceId, int $roleId, string $assignmentId, array $data): ?array
    {
        if (!isset($_SESSION[$this->cartKey]['services'][$serviceId]['roles'][$roleId])) {
            return null;
        }
        $role = &$_SESSION[$this->cartKey]['services'][$serviceId]['roles'][$roleId];

        foreach ($role['assignments'] as &$a) {
            if ($a['id'] === $assignmentId) {
                if (array_key_exists('specialistId', $data)) {
                    $newSpecId = $data['specialistId'] !== null ? (string)$data['specialistId'] : null;
                    if ($newSpecId !== null && $newSpecId !== $a['specialistId']) {
                        foreach ($role['assignments'] as $other) {
                            if ($other['id'] !== $assignmentId && ($other['specialistId'] ?? null) === $newSpecId) {
                                return null;
                            }
                        }
                    }
                    $a['specialistId'] = $newSpecId;
                }
                if (array_key_exists('hours', $data)) {
                    $a['hours'] = max(0, (float)$data['hours']);
                }
                return $a;
            }
        }
        return null;
    }

    public function removeAssignment(int $serviceId, int $roleId, string $assignmentId): bool
    {
        if (!isset($_SESSION[$this->cartKey]['services'][$serviceId]['roles'][$roleId])) {
            return false;
        }
        $role = &$_SESSION[$this->cartKey]['services'][$serviceId]['roles'][$roleId];
        $before = count($role['assignments']);
        $role['assignments'] = array_values(array_filter(
            $role['assignments'],
            fn($a) => $a['id'] !== $assignmentId
        ));
        return count($role['assignments']) < $before;
    }

    /* ================================================================
       LEGACY VIEW — для шаблонов и Excel до их переезда
       ================================================================ */

    /**
     * Старый формат: [serviceId => ['NAME', 'ROLES' => [roleId => ['ROLE_ID', 'ROLE_NAME', 'HOURS', 'RATE', 'COST', 'GRADE_ID', 'STD_HOURS', 'RESULT']], 'SERVICE_LEVEL', 'ROOT_SECTION_CODE', 'SECTION_NAME']]
     *
     * Для каждой роли «склеиваем» назначения: HOURS = сумма, RATE = средневзвешенная,
     * COST = сумма стоимостей, GRADE_ID = от первого назначенного специалиста.
     * Это бридж: после переезда UI на новую модель уходит.
     */
    public function getAll(): array
    {
        $team   = $this->getTeam();
        $legacy = [];

        foreach ($this->getServices() as $serviceId => $service) {
            $rolesLegacy = [];
            foreach ($service['roles'] as $roleId => $role) {
                $rolesLegacy[(int)$roleId] = $this->roleToLegacy($role, $team);
            }
            $legacy[(int)$serviceId] = [
                'NAME'              => $service['name'] ?? '',
                'ROLES'             => $rolesLegacy,
                'SERVICE_LEVEL'     => $service['serviceLevel'] ?? CostCalculator::LEVEL_MEDIUM,
                'ROOT_SECTION_CODE' => $service['rootSectionCode'] ?? '',
                'SECTION_NAME'      => $service['sectionName'] ?? '',
            ];
        }
        return $legacy;
    }

    private function roleToLegacy(array $role, array $team): array
    {
        $totalHours = 0.0;
        $totalCost  = 0.0;
        $firstSpec  = null;

        foreach ($role['assignments'] ?? [] as $a) {
            $hours = (float)($a['hours'] ?? 0);
            $specId = $a['specialistId'] ?? null;
            $rate  = ($specId !== null && isset($team[$specId])) ? (float)$team[$specId]['rate'] : 0.0;

            $totalHours += $hours;
            $totalCost  += $rate * $hours;

            if ($firstSpec === null && $specId !== null && isset($team[$specId])) {
                $firstSpec = $team[$specId];
            }
        }

        $effectiveRate = $totalHours > 0 ? round($totalCost / $totalHours, 2) : 0.0;

        return [
            'ROLE_ID'   => (int)($role['roleId'] ?? 0),
            'ROLE_NAME' => $role['roleName'] ?? '',
            'RESULT'    => $role['result'] ?? '',
            'HOURS'     => $totalHours,
            'STD_HOURS' => (float)($role['stdHours'] ?? 0),
            'GRADE_ID'  => $firstSpec ? (int)($firstSpec['gradeId'] ?? 0) : 0,
            'RATE'      => $effectiveRate,
            'COST'      => round($totalCost),
        ];
    }

    /* ================================================================
       УТИЛИТЫ
       ================================================================ */

    private static function generateId(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(4));
    }
}
