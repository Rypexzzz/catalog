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
            $response = [
                'success' => 1,
                'users'   => $repo->searchBitrixUsers((string)($req['search'] ?? '')),
            ];
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

        case 'addTeamMember':
            $bitrixUserId = (int)($req['bitrixUserId'] ?? 0);
            $rate         = (int)($req['rate'] ?? 0);
            $gradeId      = ($req['gradeId'] ?? null) ? (int)$req['gradeId'] : null;
            $result = $cart->addTeamMember($bitrixUserId, $rate, $gradeId);
            $response = $result['success']
                ? ['success' => 1, 'specialist' => $result['specialist'], 'total' => $cart->getTotal()]
                : ['success' => 0, 'error' => $result['error']];
            break;

        case 'updateTeamMember':
            $specialistId = (string)($req['specialistId'] ?? '');
            $data = [];
            $raw = $req->toArray();
            if (array_key_exists('rate', $raw))    $data['rate']    = (int)$raw['rate'];
            if (array_key_exists('gradeId', $raw)) $data['gradeId'] = $raw['gradeId'];
            $updated = $cart->updateTeamMember($specialistId, $data);
            $response = $updated
                ? ['success' => 1, 'specialist' => $updated, 'total' => $cart->getTotal()]
                : ['success' => 0, 'error' => 'Специалист не найден'];
            break;

        case 'removeTeamMember':
            $specialistId = (string)($req['specialistId'] ?? '');
            $info = $cart->removeTeamMember($specialistId);
            $response = $info['removed']
                ? ['success' => 1] + $info + ['total' => $cart->getTotal()]
                : ['success' => 0, 'error' => 'Специалист не найден'];
            break;

        case 'addAssignment':
            $roleId       = (int)($req['roleId'] ?? 0);
            $specialistId = ($req['specialistId'] ?? null) !== null && $req['specialistId'] !== ''
                ? (string)$req['specialistId'] : null;
            $hours        = ($req['hours'] ?? null) !== null && $req['hours'] !== ''
                ? (float)$req['hours'] : null;
            $assignment = $cart->addAssignment($sid, $roleId, $specialistId, $hours);
            $response = $assignment
                ? ['success' => 1, 'assignment' => $assignment, 'total' => $cart->getTotal()]
                : ['success' => 0, 'error' => 'Не удалось добавить назначение'];
            break;

        case 'updateAssignment':
            $roleId       = (int)($req['roleId'] ?? 0);
            $assignmentId = (string)($req['assignmentId'] ?? '');
            $data = [];
            $raw  = $req->toArray();
            if (array_key_exists('specialistId', $raw)) {
                $data['specialistId'] = $raw['specialistId'] === '' ? null : (string)$raw['specialistId'];
            }
            if (array_key_exists('hours', $raw)) {
                $data['hours'] = (float)$raw['hours'];
            }
            $updated = $cart->updateAssignment($sid, $roleId, $assignmentId, $data);
            $response = $updated
                ? ['success' => 1, 'assignment' => $updated, 'total' => $cart->getTotal()]
                : ['success' => 0, 'error' => 'Не удалось обновить назначение'];
            break;

        case 'removeAssignment':
            $roleId       = (int)($req['roleId'] ?? 0);
            $assignmentId = (string)($req['assignmentId'] ?? '');
            $ok = $cart->removeAssignment($sid, $roleId, $assignmentId);
            $response = $ok
                ? ['success' => 1, 'total' => $cart->getTotal()]
                : ['success' => 0, 'error' => 'Назначение не найдено'];
            break;

        case 'updateServiceLevel':
            $result = $cart->updateServiceLevel($sid, (string)$req['level']);
            $response = $result
                ? array_merge(['success' => 1], $result)
                : ['success' => 0, 'error' => 'Не удалось обновить уровень'];
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


// Команда проекта — подтягиваем имена/фото для рендера
$teamRaw  = array_values($cart->getTeam());
$teamView = [];
$bxIds    = array_filter(array_map(fn($m) => (int)($m['bitrixUserId'] ?? 0), $teamRaw));
$uInfo    = [];
if (!empty($bxIds)) {
    $rsU = \CUser::GetList(
        'ID', 'ASC',
        ['ID' => implode('|', array_unique($bxIds))],
        ['SELECT' => ['ID', 'NAME', 'LAST_NAME', 'PERSONAL_PHOTO']]
    );
    while ($u = $rsU->Fetch()) {
        $photo = '';
        if ($u['PERSONAL_PHOTO']) {
            $f = \CFile::GetFileArray($u['PERSONAL_PHOTO']);
            if ($f) $photo = $f['SRC'];
        }
        $uInfo[(int)$u['ID']] = [
            'name'  => trim($u['NAME'] . ' ' . $u['LAST_NAME']) ?: $u['LOGIN'],
            'photo' => $photo,
        ];
    }
}
foreach ($teamRaw as $m) {
    $info = $uInfo[(int)$m['bitrixUserId']] ?? ['name' => 'Пользователь #' . $m['bitrixUserId'], 'photo' => ''];
    $gradeId   = $m['gradeId'] ?? null;
    $gradeName = $gradeId && isset($gradesById[$gradeId]) ? $gradesById[$gradeId]['NAME'] : null;
    $teamView[] = [
        'id'           => $m['id'],
        'bitrixUserId' => (int)$m['bitrixUserId'],
        'rate'         => (int)$m['rate'],
        'gradeId'      => $gradeId,
        'gradeName'    => $gradeName,
        'name'         => $info['name'],
        'photo'        => $info['photo'],
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
    'TEAM'           => $teamView,
    'CART_RAW'       => $cart->getRaw(),
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

    // Резолвим имена специалистов команды для отображения в assignments.
    $team       = $cart->getTeam();
    $specNames  = [];
    $bxIds      = array_filter(array_map(fn($m) => (int)($m['bitrixUserId'] ?? 0), $team));
    if (!empty($bxIds)) {
        $rsU = \CUser::GetList(
            'ID', 'ASC',
            ['ID' => implode('|', array_unique($bxIds))],
            ['SELECT' => ['ID', 'NAME', 'LAST_NAME', 'LOGIN']]
        );
        $byUserId = [];
        while ($u = $rsU->Fetch()) {
            $byUserId[(int)$u['ID']] = trim($u['NAME'] . ' ' . $u['LAST_NAME']) ?: $u['LOGIN'];
        }
        foreach ($team as $specId => $m) {
            $name = $byUserId[(int)($m['bitrixUserId'] ?? 0)] ?? '—';
            $specNames[$specId] = $name;
        }
    }

    foreach ($cartData as $sid => &$svc) {
        $level = $svc['SERVICE_LEVEL'] ?? CostCalculator::LEVEL_MEDIUM;
        $base  = 0;
        foreach ($svc['ROLES'] as &$role) {
            $role['ROLE_NAME']  = $rolesById[(int)($role['ROLE_ID'] ?? 0)]['NAME'] ?? '';
            $role['GRADE_NAME'] = $gradesById[$role['GRADE_ID'] ?? 0]['NAME'] ?? '';
            foreach ($role['ASSIGNMENTS'] ?? [] as &$a) {
                $a['SPEC_NAME']  = $a['SPEC_ID'] ? ($specNames[$a['SPEC_ID']] ?? '—') : '—';
                $a['GRADE_NAME'] = $gradesById[$a['GRADE_ID'] ?? 0]['NAME'] ?? '';
            }
            unset($a);
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