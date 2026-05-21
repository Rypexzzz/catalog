<?php

namespace Ithive\Goalsbazhen\ServiceCatalog;

use Bitrix\Highloadblock\HighloadBlockTable as HLBT;
use Bitrix\Iblock\IblockTable;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Main\UserTable;


class Repository
{
    /* === ID сущностей === */
    private int $ibServicesId;
    private int $hlDeptsId;
    private int $hlRolesId;
    private int $hlSostavId;
    private int $hlGradesId;
    private int $hlRoleDefaultGradesId;
    private int $hlDraftsId = 0;
    private int $propMinCriteriaId;

    /* === Кэши справочников === */
    private ?array $departments = null;
    private ?array $roles = null;
    private ?array $grades = null;
    private ?array $roleDefaultGrades = null;
    private array $compositionCache = [];
    private array $hlDataCache = [];
    private array $hlClassCache = [];

    public function __construct()
    {
        Loader::includeModule('iblock');
        Loader::includeModule('highloadblock');
        $this->resolveIds();
    }


    private function resolveIds(): void
    {
        $this->ibServicesId           = $this->resolveIblockId('services');
        $this->hlDeptsId              = $this->resolveHlId('depts_table');
        $this->hlRolesId              = $this->resolveHlId('roles_table');
        $this->hlSostavId             = $this->resolveHlId('sostav_table');
        $this->hlGradesId             = $this->resolveHlId('grades_table');
        $this->hlRoleDefaultGradesId  = $this->resolveHlId('role_default_grades');
        $this->propMinCriteriaId      = $this->resolvePropertyId($this->ibServicesId, 'MIN_CRITERIA');

        try {
            $this->hlDraftsId = $this->resolveHlId('drafts_table');
        } catch (SystemException) {
            $this->hlDraftsId = 0;
        }
    }

    private function resolveIblockId(string $code): int
    {
        $row = IblockTable::getList([
            'filter' => ['CODE' => $code],
            'select' => ['ID'],
        ])->fetch();

        if (!$row) {
            throw new SystemException("Инфоблок не найден: {$code}");
        }
        return (int)$row['ID'];
    }

    private function resolveHlId(string $tableName): int
    {
        $row = HLBT::getList([
            'filter' => ['TABLE_NAME' => $tableName],
        ])->fetch();

        if (!$row) {
            throw new SystemException("HL-блок не найден: {$tableName}");
        }
        return (int)$row['ID'];
    }

    private function resolvePropertyId(int $iblockId, string $code): int
    {
        $row = \CIBlockProperty::GetList([], [
            'IBLOCK_ID' => $iblockId,
            'CODE'      => $code,
        ])->Fetch();

        if (!$row) {
            throw new SystemException("Свойство не найдено: {$code} (IB {$iblockId})");
        }
        return (int)$row['ID'];
    }

    /* ================================================================
       ГЕТТЕРЫ ID
       ================================================================ */

    public function getServicesIblockId(): int  { return $this->ibServicesId; }
    public function getSostavHlId(): int        { return $this->hlSostavId; }
    public function getDraftsHlId(): int        { return $this->hlDraftsId; }
    public function getMinCriteriaPropId(): int  { return $this->propMinCriteriaId; }


    public function getHlDataClass(int $hlId): ?string
    {
        if (isset($this->hlClassCache[$hlId])) {
            return $this->hlClassCache[$hlId];
        }
        $hl = HLBT::getById($hlId)->fetch();
        if (!$hl) return null;

        $cls = HLBT::compileEntity($hl)->getDataClass();
        $this->hlClassCache[$hlId] = $cls;
        return $cls;
    }

    /**
     * Выбрать все записи из HL-блока (с кэшем).
     */
    private function fetchHlData(int $hlId, array $select = ['*']): array
    {
        $key = $hlId . ':' . implode(',', $select);
        if (isset($this->hlDataCache[$key])) {
            return $this->hlDataCache[$key];
        }

        $cls = $this->getHlDataClass($hlId);
        if (!$cls) return [];

        $result = $cls::getList(['select' => $select])->fetchAll();
        $this->hlDataCache[$key] = $result;
        return $result;
    }

    /* ================================================================
       СПРАВОЧНИКИ
       ================================================================ */

    /** @return array<int, string> ID => NAME */
    public function getDepartments(): array
    {
        if ($this->departments !== null) return $this->departments;

        $this->departments = [];
        foreach ($this->fetchHlData($this->hlDeptsId, ['ID', 'UF_NAME']) as $d) {
            $this->departments[(int)$d['ID']] = $d['UF_NAME'];
        }
        return $this->departments;
    }

    public function getRoles(): array
    {
        if ($this->roles !== null) return $this->roles;

        $this->roles = [];
        foreach ($this->fetchHlData($this->hlRolesId, ['ID', 'UF_NAME', 'UF_DEPARTMENT']) as $r) {
            $this->roles[(int)$r['ID']] = [
                'NAME'    => $r['UF_NAME'],
                'DEPT_ID' => (int)$r['UF_DEPARTMENT'],
            ];
        }
        return $this->roles;
    }

    public function getGrades(): array
    {
        if ($this->grades !== null) return $this->grades;

        $this->grades = [];
        foreach ($this->fetchHlData($this->hlGradesId, ['ID', 'UF_NAME', 'UF_RATE']) as $g) {
            $this->grades[(int)$g['ID']] = [
                'NAME' => $g['UF_NAME'],
                'RATE' => (float)$g['UF_RATE'],
            ];
        }
        return $this->grades;
    }

    /** @return array<int, int> roleId => gradeId */
    public function getRoleDefaultGrades(): array
    {
        if ($this->roleDefaultGrades !== null) return $this->roleDefaultGrades;

        $this->roleDefaultGrades = [];
        foreach ($this->fetchHlData($this->hlRoleDefaultGradesId, ['UF_ROLE', 'UF_GRADE']) as $rdg) {
            $this->roleDefaultGrades[(int)$rdg['UF_ROLE']] = (int)$rdg['UF_GRADE'];
        }
        return $this->roleDefaultGrades;
    }

    public function getDefaultGradeForRole(int $roleId): int
    {
        $defaults = $this->getRoleDefaultGrades();
        if (isset($defaults[$roleId])) {
            return $defaults[$roleId];
        }
        $grades = $this->getGrades();
        return $grades ? (int)array_key_first($grades) : 0;
    }

    /**
     * Состав услуги: только структурные поля (роль + нормативные часы + результат).
     * Стоимости в каталоге больше нет — она появляется в корзине через ставки команды.
     *
     * @return array{0: array<int, array{ROLE_ID:int, ROLE_NAME:string, RESULT:string, HOURS:float, STD_HOURS:float}>, 1: float}
     */
    public function getServiceComposition(int $serviceId): array
    {
        if (isset($this->compositionCache[$serviceId])) {
            return $this->compositionCache[$serviceId];
        }

        $roles = $this->getRoles();
        $cls   = $this->getHlDataClass($this->hlSostavId);

        $rs = $cls::getList([
            'filter' => ['UF_SERVICE' => $serviceId],
            'select' => ['UF_ROLE', 'UF_HOURS', 'UF_RESULT'],
        ]);

        $composition = [];
        $stdHours    = 0.0;

        while ($row = $rs->fetch()) {
            $roleId = (int)$row['UF_ROLE'];
            $hours  = (float)$row['UF_HOURS'];

            $composition[$roleId] = [
                'ROLE_ID'   => $roleId,
                'ROLE_NAME' => $roles[$roleId]['NAME'] ?? '',
                'RESULT'    => $row['UF_RESULT'] ?? '',
                'HOURS'     => $hours,
                'STD_HOURS' => $hours,
            ];
            $stdHours += $hours;
        }

        $this->compositionCache[$serviceId] = [$composition, $stdHours];
        return [$composition, $stdHours];
    }

    /* ================================================================
       РАЗДЕЛЫ
       ================================================================ */

    /** Корневые разделы инфоблока услуг. */
    public function getRootSections(): array
    {
        $sections = [];
        $rs = \CIBlockSection::GetList(
            ['SORT' => 'ASC'],
            ['IBLOCK_ID' => $this->ibServicesId, 'SECTION_ID' => 0, 'ACTIVE' => 'Y'],
            false,
            ['ID', 'NAME', 'CODE', 'DESCRIPTION']
        );
        while ($sec = $rs->Fetch()) {
            $sections[(int)$sec['ID']] = $sec;
        }
        return $sections;
    }

    /** Подразделы (второй уровень). */
    public function getSubSections(int $parentId): array
    {
        $sections = [];
        $rs = \CIBlockSection::GetList(
            ['SORT' => 'ASC'],
            ['IBLOCK_ID' => $this->ibServicesId, 'SECTION_ID' => $parentId, 'ACTIVE' => 'Y'],
            false,
            ['ID', 'NAME', 'CODE', 'DESCRIPTION']
        );
        while ($sec = $rs->Fetch()) {
            $sections[(int)$sec['ID']] = $sec;
        }
        return $sections;
    }

    /** Полное дерево: корневые → подразделы. */
    public function getAllSectionsTree(): array
    {
        $tree = [];
        foreach ($this->getRootSections() as $rootId => $root) {
            $children = [];
            foreach ($this->getSubSections($rootId) as $sub) {
                $children[] = [
                    'ID'   => (int)$sub['ID'],
                    'NAME' => $sub['NAME'],
                    'CODE' => $sub['CODE'] ?? '',
                ];
            }
            $tree[] = [
                'ID'       => $rootId,
                'NAME'     => $root['NAME'],
                'CODE'     => $root['CODE'] ?? '',
                'CHILDREN' => $children,
            ];
        }
        return $tree;
    }

    /* ================================================================
       ЭЛЕМЕНТЫ (УСЛУГИ)
       ================================================================ */

    /**
     * Элементы инфоблока из указанного раздела с фильтрацией.
     */
    public function getServiceElements(int $sectionId, ?string $search = null, ?array $filterIds = null): array
    {
        $filter = [
            'IBLOCK_ID'  => $this->ibServicesId,
            'SECTION_ID' => $sectionId,
            'ACTIVE'     => 'Y',
        ];
        if ($search)              $filter['%NAME'] = $search;
        if ($filterIds !== null)  $filter['ID']    = $filterIds;

        $propField = 'PROPERTY_' . $this->propMinCriteriaId;
        $items     = [];

        $rs = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            $filter,
            false,
            false,
            ['ID', 'NAME', $propField]
        );

        while ($el = $rs->Fetch()) {
            $criteria = $el[$propField . '_VALUE'] ?? '';
            if (is_array($criteria)) {
                $criteria = implode("\n", array_filter($criteria, 'strlen'));
            }
            $items[] = [
                'ID'           => (int)$el['ID'],
                'NAME'         => $el['NAME'],
                'MIN_CRITERIA' => $criteria,
            ];
        }
        return $items;
    }

    /**
     * Критерии обслуживания для массива услуг.
     */
    public function getServiceCriteria(array $serviceIds): array
    {
        if (empty($serviceIds)) return [];

        $propField = 'PROPERTY_' . $this->propMinCriteriaId;
        $map       = [];

        $rs = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $this->ibServicesId, 'ID' => $serviceIds],
            false,
            false,
            ['ID', $propField]
        );

        while ($el = $rs->Fetch()) {
            $val = $el[$propField . '_VALUE'] ?? '';
            $map[(int)$el['ID']] = is_array($val) ? implode("\n", $val) : $val;
        }
        return $map;
    }

    /* ================================================================
       ФИЛЬТРАЦИЯ ПО РОЛЯМ
       ================================================================ */

    /** ID услуг, в состав которых входят указанные роли. */
    public function getServiceIdsByRoles(array $roleIds): ?array
    {
        if (empty($roleIds)) return null;

        $ids = [];
        foreach ($this->fetchHlData($this->hlSostavId, ['UF_SERVICE', 'UF_ROLE']) as $r) {
            if (in_array((int)$r['UF_ROLE'], $roleIds, true)) {
                $ids[(int)$r['UF_SERVICE']] = true;
            }
        }
        return array_keys($ids) ?: [];
    }

    /* ================================================================
       АДМИН: СОЗДАНИЕ УСЛУГИ
       ================================================================ */

    public function createService(string $name, int $sectionId, string $minCriteria, array $rolesData): array
    {
        $sec = \CIBlockSection::GetByID($sectionId)->Fetch();
        if (!$sec || (int)$sec['IBLOCK_ID'] !== $this->ibServicesId) {
            return ['success' => false, 'error' => 'Указанный раздел не найден'];
        }


// Валидация состава: запрещаем дубли ролей
$rolesDict = $this->getRoles();
$seen = [];
$normalized = [];

foreach ($rolesData as $item) {
    $roleId = (int)($item['roleId'] ?? 0);
    if (!$roleId) continue;

    if (!isset($rolesDict[$roleId])) {
        return ['success' => false, 'error' => 'Неизвестная роль (ID: ' . $roleId . ')'];
    }
    if (isset($seen[$roleId])) {
        return ['success' => false, 'error' => 'Роль "' . ($rolesDict[$roleId]['NAME'] ?? $roleId) . '" добавлена дважды'];
    }
    $seen[$roleId] = true;
    $normalized[] = $item;
}

$rolesData = $normalized;
if (empty($rolesData)) {
    return ['success' => false, 'error' => 'Добавьте хотя бы одну роль'];
}


        $el     = new \CIBlockElement();
        $fields = [
            'IBLOCK_ID'          => $this->ibServicesId,
            'IBLOCK_SECTION_ID'  => $sectionId,
            'NAME'               => $name,
            'ACTIVE'             => 'Y',
            'PROPERTY_VALUES'    => [],
        ];

        if ($minCriteria !== '') {
            $fields['PROPERTY_VALUES']['MIN_CRITERIA'] = $minCriteria;
        }

        $newId = $el->Add($fields);
        if (!$newId) {
            return ['success' => false, 'error' => 'Ошибка создания: ' . $el->LAST_ERROR];
        }

        $cls        = $this->getHlDataClass($this->hlSostavId);
        $rolesAdded = 0;

        foreach ($rolesData as $item) {
            $roleId = (int)($item['roleId'] ?? 0);
            if (!$roleId) continue;

            $res = $cls::add([
                'UF_SERVICE' => $newId,
                'UF_ROLE'    => $roleId,
                'UF_RESULT'  => trim($item['result'] ?? ''),
                'UF_HOURS'   => (float)($item['hours'] ?? 0),
            ]);
            if ($res->isSuccess()) $rolesAdded++;
        }

        return ['success' => true, 'serviceId' => $newId, 'rolesAdded' => $rolesAdded];
    }

    /* ================================================================
       ПОИСК БИТРИКС-ПОЛЬЗОВАТЕЛЕЙ
       ================================================================ */

    /**
     * Единый поиск активных Bitrix-пользователей с фото — для подбора
     * членов команды, выдачи доступов к черновикам и т.п.
     *
     * @param string $query    Подстрока (по NAME / LAST_NAME / LOGIN, опц. EMAIL).
     * @param array  $opts     {
     *   @var int   $limit       Сколько вернуть, по умолчанию 20.
     *   @var int   $minLength   Минимальная длина запроса, по умолчанию 2.
     *   @var int[] $excludeIds  Исключить эти ID из результатов.
     *   @var bool  $searchEmail Искать ли по EMAIL (по умолчанию да).
     * }
     * @return array<int, array{id:int, name:string, firstName:string, lastName:string, login:string, avatar:string}>
     */
    public function searchBitrixUsers(string $query, array $opts = []): array
    {
        $limit       = (int)($opts['limit']      ?? 20);
        $minLength   = (int)($opts['minLength']  ?? 2);
        $excludeIds  = array_map('intval', (array)($opts['excludeIds'] ?? []));
        $searchEmail = (bool)($opts['searchEmail'] ?? true);

        $query = trim($query);
        if ($query === '' || mb_strlen($query) < $minLength) {
            return [];
        }

        $or = [
            'LOGIC'      => 'OR',
            '%NAME'      => $query,
            '%LAST_NAME' => $query,
            '%LOGIN'     => $query,
        ];
        if ($searchEmail) {
            $or['%EMAIL'] = $query;
        }

        $filter = [
            '=ACTIVE' => 'Y',
            $or,
        ];
        if (!empty($excludeIds)) {
            $filter['!=ID'] = $excludeIds;
        }

        $rs = UserTable::getList([
            'select' => ['ID', 'NAME', 'LAST_NAME', 'LOGIN', 'PERSONAL_PHOTO'],
            'filter' => $filter,
            'order'  => ['LAST_NAME' => 'ASC', 'NAME' => 'ASC'],
            'limit'  => $limit,
        ]);

        $users = [];
        while ($u = $rs->fetch()) {
            $photo = '';
            if (!empty($u['PERSONAL_PHOTO'])) {
                $file = \CFile::GetFileArray($u['PERSONAL_PHOTO']);
                if ($file) {
                    $photo = (string)$file['SRC'];
                }
            }
            $fullName = trim(($u['NAME'] ?? '') . ' ' . ($u['LAST_NAME'] ?? ''));
            $users[] = [
                'id'        => (int)$u['ID'],
                'name'      => $fullName !== '' ? $fullName : (string)($u['LOGIN'] ?? ''),
                'firstName' => (string)($u['NAME'] ?? ''),
                'lastName'  => (string)($u['LAST_NAME'] ?? ''),
                'login'     => (string)($u['LOGIN'] ?? ''),
                'avatar'    => $photo,
            ];
        }
        return $users;
    }
}
