<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Context;
use Bitrix\Main\SystemException;
use Ithive\Goalsbazhen\ServiceCatalog\{Repository, CartService, CostCalculator};

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/lib/autoload.php';

const CATALOG_ADMIN_GROUP_ID = 58;


try {
    $repo = new Repository();
} catch (SystemException $e) {
    ShowError($e->getMessage());
    return;
}

global $USER;
$userId = (int)$USER->GetID();
$cart   = new CartService($repo, $userId ?: null);


$isCatalogAdmin = false;
if ($userId) {
    $isCatalogAdmin = in_array(CATALOG_ADMIN_GROUP_ID, \CUser::GetUserGroup($userId));
}


$ROLES  = $repo->getRoles();
$GRADES = $repo->getGrades();



$req = Context::getCurrent()->getRequest();

if ($req->isPost() && $req['ajax'] === 'Y') {

    if (!check_bitrix_sessid()) {
        $APPLICATION->RestartBuffer();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => 0, 'error' => 'Сессия истекла. Обновите страницу и попробуйте снова.'], JSON_UNESCAPED_UNICODE);
        die();
    }
    $id       = (int)$req['serviceId'];
    $action   = $req['action'];
    $noIdActs = ['getCartTotal', 'createService'];
    $response = ['success' => 1];

    if (!$id && !in_array($action, $noIdActs, true)) {
        $APPLICATION->RestartBuffer();
        echo json_encode(['success' => 0]);
        die();
    }

    switch ($action) {

        case 'createService':
            if (!$isCatalogAdmin) {
                $response = ['success' => 0, 'error' => 'Недостаточно прав'];
                break;
            }
            $serviceName = trim($req['serviceName'] ?? '');
            $sectionId   = (int)($req['sectionId'] ?? 0);
            $minCriteria = trim($req['minCriteria'] ?? '');
            $rolesData   = $req['roles'] ?? [];

            if ($serviceName === '') { $response = ['success' => 0, 'error' => 'Название не может быть пустым']; break; }
            if (!$sectionId)         { $response = ['success' => 0, 'error' => 'Не выбран раздел']; break; }
            if (empty($rolesData))   { $response = ['success' => 0, 'error' => 'Добавьте хотя бы одну роль']; break; }

            $result = $repo->createService($serviceName, $sectionId, $minCriteria, $rolesData);
            $response = $result['success']
                ? ['success' => 1, 'serviceId' => $result['serviceId'], 'rolesAdded' => $result['rolesAdded'], 'message' => 'Услуга создана']
                : ['success' => 0, 'error' => $result['error']];
            break;

        case 'addService':
            $rootCode = $req['rootSection'] ?? '';
            if (!$cart->canAddModel($rootCode)) {
                $response = ['success' => 0, 'error' => 'В корзине уже находятся работы согласно другой модели реализации проекта'];
                break;
            }

            $level = (string)($req['level'] ?? '');
            $allowedLevels = [
                CostCalculator::LEVEL_LOW,
                CostCalculator::LEVEL_MEDIUM,
                CostCalculator::LEVEL_HIGH,
            ];
            if ($level === '' || !in_array($level, $allowedLevels, true)) {
                $level = CostCalculator::LEVEL_MEDIUM;
            }

            $cart->add($id, [
                'hours'       => $req['hours'] ?: [],
                'grades'      => $req['grades'] ?: [],
                'level'       => $level,
                'rootSection' => $rootCode,
                'sectionName' => $req['sectionName'] ?? '',
            ]);
            $response['total'] = $cart->getTotal();
            break;

        case 'removeService':
            $cart->remove($id);
            $response['total'] = $cart->getTotal();
            break;

        case 'updateHours':
            $result = $cart->updateHours($id, (int)$req['roleId'], (int)$req['hours']);
            if ($result) $response = array_merge($response, $result);
            break;

        case 'updateRoleGrade':
            $result = $cart->updateGrade($id, (int)$req['roleId'], (int)$req['gradeId']);
            if ($result) $response = array_merge($response, $result);
            break;


        case 'updateServiceLevel':
            $result = $cart->updateLevel($id, (string)$req['level']);
            if ($result) $response = array_merge($response, $result);
            break;

        case 'getCartTotal':
            $response['total'] = $cart->getTotal();
            break;
    }

    $APPLICATION->RestartBuffer();
    header('Content-Type: application/json');
    echo json_encode($response);
    die();
}



$rootSections = $repo->getRootSections();

$activeRootId = (int)($_GET['root'] ?? 0);
if (!$activeRootId || !isset($rootSections[$activeRootId])) {
    $activeRootId = (int)array_key_first($rootSections);
}


$q              = trim((string)($_GET['q'] ?? ''));
$roleFilter     = array_filter(array_map('intval', (array)($_GET['roles'] ?? [])));
$idsByRole      = $roleFilter ? $repo->getServiceIdsByRoles($roleFilter) : null;


if ($idsByRole !== null && empty($idsByRole)) {
    $arResult = [
        'STAGES'           => [],
        'MAP'              => [],
        'ROLES'            => $ROLES,
        'GRADES'           => $GRADES,
        'CURRENT_TOTAL'    => 0,
        'ROOT_SECTIONS'    => $rootSections,
        'ACTIVE_ROOT_ID'   => $activeRootId,
        'IS_CATALOG_ADMIN' => $isCatalogAdmin,
        'ALL_SECTIONS'     => $repo->getAllSectionsTree(),
    ];
    $this->includeComponentTemplate();
    return;
}


$inCart     = $cart->getAll();
$subSecs    = $repo->getSubSections($activeRootId);
$hasSubsecs = !empty($subSecs);

$arResult = [
    'STAGES'           => [],
    'MAP'              => [],
    'ROLES'            => $ROLES,
    'GRADES'           => $GRADES,
    'ROOT_SECTIONS'    => $rootSections,
    'ACTIVE_ROOT_ID'   => $activeRootId,
    'IS_CATALOG_ADMIN' => $isCatalogAdmin,
    'ALL_SECTIONS'     => $repo->getAllSectionsTree(),
];


$buildSectionItems = function (int $sectionId, string $sectionName) use (
    $repo, $q, $idsByRole, $inCart, $GRADES, $activeRootId, $rootSections
): array {
    $elements = $repo->getServiceElements($sectionId, $q ?: null, $idsByRole);
    $items    = [];

    foreach ($elements as $el) {
        $pid = $el['ID'];
        $currentLevel = $inCart[$pid]['SERVICE_LEVEL'] ?? CostCalculator::LEVEL_MEDIUM;

        [$comp, $std] = $repo->getServiceComposition($pid);

        if (isset($inCart[$pid])) {
            foreach ($comp as $rid => $r) {
                if (isset($inCart[$pid]['ROLES'][$rid])) {
                    $saved = $inCart[$pid]['ROLES'][$rid];
                    $comp[$rid]['HOURS']    = $saved['HOURS'];
                    $comp[$rid]['GRADE_ID'] = $saved['GRADE_ID'];
                    $comp[$rid]['RATE']     = $saved['RATE'];
                    $comp[$rid]['COST']     = $saved['COST'];
                }
            }
        }

        $currentCost = CostCalculator::serviceCost($comp, $currentLevel);

        $items[] = [
            'ID'                => $pid,
            'NAME'              => $el['NAME'],
            'MIN_CRITERIA'      => $el['MIN_CRITERIA'],
            'ROLES'             => $comp,
            'STD_COST'          => $std,
            'CURRENT_COST'      => $currentCost,
            'CURRENT_LEVEL'     => $currentLevel,
            'SECTION_ID'        => $sectionId,
            'SECTION_NAME'      => $sectionName,
            'ROOT_SECTION_ID'   => $activeRootId,
            'ROOT_SECTION_CODE' => $rootSections[$activeRootId]['CODE'] ?? '',
        ];
    }
    return $items;
};

if ($hasSubsecs) {
    foreach ($subSecs as $sid => $sec) {
        $arResult['STAGES'][$sid] = $sec['NAME'];
        $items = $buildSectionItems($sid, $sec['NAME']);
        $arResult['MAP'][$sid] = ['NAME' => $sec['NAME'], 'ITEMS' => $items];
    }
} else {

    $rootName = $rootSections[$activeRootId]['NAME'] ?? '';
    $items    = $buildSectionItems($activeRootId, $rootName);
    $arResult['MAP'][$activeRootId] = ['NAME' => $rootName, 'ITEMS' => $items];
}

$arResult['CURRENT_TOTAL'] = $cart->getTotal();

$this->includeComponentTemplate();
