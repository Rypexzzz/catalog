<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/lib/autoload.php';

use Bitrix\Main\Context;
use Bitrix\Main\SystemException;
use Ithive\Goalsbazhen\ServiceCatalog\{
    Repository,
    CartService,
    CostCalculator,
    DraftService,
    DraftLockService,
    ExcelExporter
};

try {
    $repo = new Repository();
} catch (SystemException $e) {
    ShowError($e->getMessage());
    return;
}

global $USER;
$userId = (int)$USER->GetID();
$cart   = new CartService($repo, $userId ?: null);
$drafts = new DraftService($repo);

$req = Context::getCurrent()->getRequest();

if ($req->get('load_draft') && $drafts->isAvailable() && $userId) {
    $draftId = (int)$req->get('load_draft');
    $data = $drafts->load($draftId, $userId);
    if ($data !== null) {
        $cart->replace($data);
        LocalRedirect($APPLICATION->GetCurPageParam('', ['load_draft']));
    } else {
        ShowError('Черновик не найден или несовместим с текущей версией каталога');
    }
}

if ($req->isPost() && $req['ajax'] === 'Y') {
    // P0: CSRF protection
    if (!check_bitrix_sessid()) {
        $APPLICATION->RestartBuffer();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => 0, 'error' => 'Сессия истекла. Обновите страницу и попробуйте снова.'], JSON_UNESCAPED_UNICODE);
        die();
    }
    $sid      = (int)$req['serviceId'];
    $response = ['success' => 1];

    switch ($req['action']) {
        case 'removeService':
            $cart->removeService($sid);
            break;

        case 'clearCart':
            $cart->clear();
            break;

        case 'getDraftsList':
            if (!$userId) {
                $response = ['success' => 0, 'error' => 'Необходима авторизация'];
                break;
            }
            $grouped = $drafts->getAllDraftsGrouped($userId);
            $response = [
                'success' => 1,
                'drafts'  => $grouped,
                'counts'  => [
                    'own'    => count($grouped['own']),
                    'shared' => count($grouped['shared']),
                    'public' => count($grouped['public']),
                ],
            ];
            break;

        case 'getDraft':
            if (!$userId) {
                $response = ['success' => 0, 'error' => 'Необходима авторизация'];
                break;
            }
            $draftId = (int)$req['draft_id'];
            $draft = $drafts->getById($draftId, $userId);
            if ($draft) {
                $response = ['success' => 1, 'draft' => $draft];
            } else {
                $response = ['success' => 0, 'error' => 'Черновик не найден'];
            }
            break;

        case 'createDraft':
            if (!$userId) {
                $response = ['success' => 0, 'error' => 'Необходима авторизация'];
                break;
            }
            $name        = trim($req['draft_name'] ?? '');
            $type        = $req['draft_type'] ?? DraftService::TYPE_PRIVATE;
            $accessUsers = $req['access_users'] ?? [];

            if (is_string($accessUsers)) {
                $accessUsers = json_decode($accessUsers, true) ?: [];
            }
            $accessUsers = array_map('intval', (array)$accessUsers);

            $cartData = $cart->getRaw();
            $response = $drafts->create($userId, $name, $cartData, $type, $accessUsers);
            break;

        case 'updateDraftData':
            if (!$userId) {
                $response = ['success' => 0, 'error' => 'Необходима авторизация'];
                break;
            }
            $draftId  = (int)$req['draft_id'];
            $cartData = $cart->getRaw();
            $response = $drafts->updateData($draftId, $userId, $cartData);
            break;

        case 'deleteDraft':
            if (!$userId) {
                $response = ['success' => 0, 'error' => 'Необходима авторизация'];
                break;
            }
            $response = $drafts->delete((int)$req['draft_id'], $userId);
            break;

        case 'renameDraft':
            if (!$userId) {
                $response = ['success' => 0, 'error' => 'Необходима авторизация'];
                break;
            }
            $response = $drafts->rename(
                (int)$req['draft_id'],
                $userId,
                trim($req['new_name'] ?? '')
            );
            break;

        case 'changeDraftType':
            if (!$userId) {
                $response = ['success' => 0, 'error' => 'Необходима авторизация'];
                break;
            }
            $accessUsers = $req['access_users'] ?? [];
            if (is_string($accessUsers)) {
                $accessUsers = json_decode($accessUsers, true) ?: [];
            }
            $accessUsers = array_map('intval', (array)$accessUsers);
            
            $response = $drafts->changeType(
                (int)$req['draft_id'],
                $userId,
                $req['new_type'] ?? DraftService::TYPE_PRIVATE,
                $accessUsers
            );
            break;

        case 'updateDraftAccess':
            if (!$userId) {
                $response = ['success' => 0, 'error' => 'Необходима авторизация'];
                break;
            }
            $accessUsers = $req['access_users'] ?? [];
            if (is_string($accessUsers)) {
                $accessUsers = json_decode($accessUsers, true) ?: [];
            }
            $accessUsers = array_map('intval', (array)$accessUsers);
            
            $response = $drafts->updateAccessUsers(
                (int)$req['draft_id'],
                $userId,
                $accessUsers
            );
            break;

        case 'lockDraft':
            if (!$userId) {
                $response = ['success' => 0, 'error' => 'Необходима авторизация'];
                break;
            }
            $response = $drafts->lock((int)$req['draft_id'], $userId);
            break;

        case 'heartbeat':
            if (!$userId) {
                $response = ['success' => 0, 'error' => 'Необходима авторизация'];
                break;
            }
            $response = $drafts->refreshLock((int)$req['draft_id'], $userId);
            break;

        case 'unlockDraft':
            if (!$userId) {
                $response = ['success' => 0, 'error' => 'Необходима авторизация'];
                break;
            }
            $response = $drafts->unlock((int)$req['draft_id'], $userId);
            break;

        case 'unlockAndSaveDraft':
            if (!$userId) {
                $response = ['success' => 0, 'error' => 'Необходима авторизация'];
                break;
            }
            $cartData = $cart->getRaw();
            $response = $drafts->unlockAndSave((int)$req['draft_id'], $userId, $cartData);
            break;

        case 'searchUsers':
            if (!$userId) {
                $response = ['success' => 0, 'error' => 'Необходима авторизация'];
                break;
            }
            $search = trim($req['search'] ?? '');
            $users  = [];
            
            if (mb_strlen($search) >= 2) {
                $rs = \Bitrix\Main\UserTable::getList([
                    'select' => ['ID', 'NAME', 'LAST_NAME', 'PERSONAL_PHOTO'],
                    'filter' => [
                        '=ACTIVE' => 'Y',
                        [
                            'LOGIC' => 'OR',
                            '%NAME'      => $search,
                            '%LAST_NAME' => $search,
                            '%LOGIN'     => $search,
                            '%EMAIL'     => $search,
                        ],
                    ],
                    'order' => ['LAST_NAME' => 'ASC'],
                    'limit' => 20,
                ]);
                
                while ($user = $rs->fetch()) {
                    $photo = '';
                    if ($user['PERSONAL_PHOTO']) {
                        $file = \CFile::GetFileArray($user['PERSONAL_PHOTO']);
                        if ($file) {
                            $photo = $file['SRC'];
                        }
                    }
                    $users[] = [
                        'id'     => (int)$user['ID'],
                        'name'   => trim($user['NAME'] . ' ' . $user['LAST_NAME']),
                        'avatar' => $photo,
                    ];
                }
            }
            
            $response = ['success' => 1, 'users' => $users];
            break;

        case 'getUsersInfo':
            if (!$userId) {
                $response = ['success' => 0, 'error' => 'Необходима авторизация'];
                break;
            }
            $userIds = $req['user_ids'] ?? [];
            if (is_string($userIds)) {
                $userIds = json_decode($userIds, true) ?: [];
            }
            $userIds = array_filter(array_map('intval', (array)$userIds));
            
            $users = [];
            if (!empty($userIds)) {
                $rs = \CUser::GetList(
                    'ID', 'ASC',
                    ['ID' => implode('|', $userIds)],
                    ['SELECT' => ['ID', 'NAME', 'LAST_NAME', 'PERSONAL_PHOTO']]
                );
                
                while ($user = $rs->Fetch()) {
                    $photo = '';
                    if ($user['PERSONAL_PHOTO']) {
                        $file = \CFile::GetFileArray($user['PERSONAL_PHOTO']);
                        if ($file) {
                            $photo = $file['SRC'];
                        }
                    }
                    $users[] = [
                        'id'     => (int)$user['ID'],
                        'name'   => trim($user['NAME'] . ' ' . $user['LAST_NAME']),
                        'avatar' => $photo,
                    ];
                }
            }
            
            $response = ['success' => 1, 'users' => $users];
            break;

        case 'saveDraft':
            if (!$userId) {
                $response = ['success' => 0, 'error' => 'Необходима авторизация'];
                break;
            }
            $response = $drafts->save($userId, trim($req['draft_name'] ?? ''), $cart->getRaw());
            break;
    }

    $APPLICATION->RestartBuffer();
    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    die();
}

$hasModelConflict = $cart->hasModelConflict();

if ($req->get('action') === 'exportExcel') {
    if ($cart->isEmpty()) {
        echo '<script>alert("Нельзя выгрузить пустую корзину"); window.history.back();</script>';
        exit();
    }
    if ($hasModelConflict) {
        echo '<script>alert("Невозможно выгрузить: конфликт моделей"); window.history.back();</script>';
        exit();
    }

    /* Подготавливаем данные для экспорта (с GRADE_NAME) */
    $byRoot = self_buildGroupedData($cart, $repo);
    ExcelExporter::export($byRoot, trim($req->get('project_name') ?? '') ?: 'Проект');
    exit();
}


$rolesById  = $repo->getRoles();
$gradesById = $repo->getGrades();
$cartData   = $cart->getAll();

$grand = 0;
foreach ($cartData as $svcId => &$svc) {
    $level      = $svc['SERVICE_LEVEL'] ?? CostCalculator::LEVEL_MEDIUM;
    $levelCoeff = CostCalculator::getLevelCoefficient($level);
    $baseSum    = 0;

    foreach ($svc['ROLES'] as $roleId => &$role) {
        $rid = (int)$roleId;
        $role['ROLE_NAME']  = $rolesById[$rid]['NAME'] ?? 'Неизвестная роль';
        $role['STD_HOURS']  = $role['HOURS'];

        $gradeId = $role['GRADE_ID'] ?? 0;
        $role['GRADE_NAME'] = $gradesById[$gradeId]['NAME'] ?? '—';

        $baseSum += ($role['RATE'] ?? 0) * ($role['HOURS'] ?? 0);
    }
    unset($role);

    $svc['SUM'] = round($baseSum * $levelCoeff);
    $grand += $svc['SUM'];
}
unset($svc);

$criteria = $repo->getServiceCriteria(array_keys($cartData));
foreach ($criteria as $id => $val) {
    if (isset($cartData[$id])) {
        $cartData[$id]['MIN_CRITERIA'] = $val;
    }
}

$rootSections = $repo->getRootSections();
$byRoot       = [];

foreach ($cartData as $sid => $svc) {
    $rootId   = 0;
    $rootName = 'Без раздела';
    $rootCode = $svc['ROOT_SECTION_CODE'] ?? '';

    if ($rootCode) {
        foreach ($rootSections as $section) {
            if ($section['CODE'] === $rootCode) {
                $rootId   = (int)$section['ID'];
                $rootName = $section['NAME'];
                break;
            }
        }
    } else {
        $res = \CIBlockElement::GetElementGroups($sid, true, ['ID', 'IBLOCK_SECTION_ID', 'NAME']);
        if ($section = $res->Fetch()) {
            $nav = \CIBlockSection::GetNavChain($repo->getServicesIblockId(), (int)$section['ID'], ['ID', 'NAME', 'CODE'], true);
            if (!empty($nav)) {
                $rootId   = (int)$nav[0]['ID'];
                $rootName = $nav[0]['NAME'];
                $rootCode = $nav[0]['CODE'] ?? '';
            }
        }
    }

    $isServiceStage = ($rootCode === 'service');
    $svc['IS_SERVICE_STAGE'] = $isServiceStage;
    $svc['SECTION_NAME']     = $svc['SECTION_NAME'] ?? $rootName;

    $byRoot[$rootId]['NAME']    = $rootName;
    $byRoot[$rootId]['CODE']    = $rootCode;
    $byRoot[$rootId]['ITEMS'][] = $svc + ['ID' => $sid];
}

uasort($byRoot, fn($a, $b) => strcmp($a['NAME'], $b['NAME']));

$userDraftsGrouped = [];
$draftCounts       = ['own' => 0, 'shared' => 0, 'public' => 0];
if ($drafts->isAvailable() && $userId) {
    $userDraftsGrouped = $drafts->getAllDraftsGrouped($userId);
    $draftCounts = [
        'own'    => count($userDraftsGrouped['own']),
        'shared' => count($userDraftsGrouped['shared']),
        'public' => count($userDraftsGrouped['public']),
    ];
}


$arResult = [
    'ROOTS'          => $byRoot,
    'GRAND_TOTAL'    => round($grand),
    'HAS_DRAFTS'     => $drafts->isAvailable(),
    'MODEL_CONFLICT' => $hasModelConflict,
    'GRADES'         => $gradesById,
    'DRAFTS_GROUPED' => $userDraftsGrouped,
    'DRAFT_COUNTS'   => $draftCounts,
    'DRAFT_TYPES'    => DraftService::TYPE_LABELS,
    'LOCK_CONFIG'    => [
        'heartbeat_interval' => DraftLockService::getHeartbeatIntervalSeconds(),
        'confirm_timeout'    => DraftLockService::getConfirmTimeoutSeconds(),
    ],
];

$this->includeComponentTemplate();

function self_buildGroupedData(CartService $cart, Repository $repo): array
{
    $rolesById  = $repo->getRoles();
    $gradesById = $repo->getGrades();
    $rootSecs   = $repo->getRootSections();
    $cartData   = $cart->getAll();
    $byRoot     = [];

    foreach ($cartData as $sid => &$svc) {
        $level = $svc['SERVICE_LEVEL'] ?? CostCalculator::LEVEL_MEDIUM;
        $base  = 0;
        foreach ($svc['ROLES'] as &$role) {
            $role['ROLE_NAME']  = $rolesById[(int)($role['ROLE_ID'] ?? 0)]['NAME'] ?? '';
            $role['GRADE_NAME'] = $gradesById[$role['GRADE_ID'] ?? 0]['NAME'] ?? '';
            $base += ($role['RATE'] ?? 0) * ($role['HOURS'] ?? 0);
        }
        unset($role);
        $svc['SUM'] = round($base * CostCalculator::getLevelCoefficient($level));

        $rootCode = $svc['ROOT_SECTION_CODE'] ?? '';
        $rootId   = 0;
        $rootName = 'Без раздела';
        foreach ($rootSecs as $sec) {
            if ($sec['CODE'] === $rootCode) { $rootId = (int)$sec['ID']; $rootName = $sec['NAME']; break; }
        }

        $svc['IS_SERVICE_STAGE'] = ($rootCode === 'service');
        $svc['SECTION_NAME']     = $svc['SECTION_NAME'] ?? $rootName;
        $byRoot[$rootId]['NAME']    = $rootName;
        $byRoot[$rootId]['CODE']    = $rootCode;
        $byRoot[$rootId]['ITEMS'][] = $svc + ['ID' => $sid];
    }
    unset($svc);
    return $byRoot;
}