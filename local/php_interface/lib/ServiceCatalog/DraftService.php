<?php

namespace Ithive\Goalsbazhen\ServiceCatalog;

use Bitrix\Main\Type\DateTime;


class DraftService
{
    public const TYPE_PRIVATE = 'private';
    public const TYPE_SHARED  = 'shared';
    public const TYPE_PUBLIC  = 'public';

    public const TYPE_LABELS = [
        self::TYPE_PRIVATE => 'Личный',
        self::TYPE_SHARED  => 'Общий',
        self::TYPE_PUBLIC  => 'Публичный',
    ];

    private Repository $repo;
    private ?DraftAccessRepository $accessRepo = null;
    private ?DraftLockService $lockService = null;

    public function __construct(Repository $repo)
    {
        $this->repo = $repo;
    }

    public function isAvailable(): bool
    {
        return $this->repo->getDraftsHlId() > 0;
    }

    private function getDataClass(): ?string
    {
        $hlId = $this->repo->getDraftsHlId();
        return $hlId ? $this->repo->getHlDataClass($hlId) : null;
    }

    /**
     * Получить репозиторий доступов (lazy init).
     */
    public function getAccessRepo(): DraftAccessRepository
    {
        if ($this->accessRepo === null) {
            $this->accessRepo = new DraftAccessRepository();
        }
        return $this->accessRepo;
    }

    /**
     * Получить сервис блокировок (lazy init).
     */
    public function getLockService(): DraftLockService
    {
        if ($this->lockService === null) {
            $this->lockService = new DraftLockService($this->repo);
        }
        return $this->lockService;
    }

    public function getById(int $draftId, int $userId): ?array
    {
        $cls = $this->getDataClass();
        if (!$cls) {
            return null;
        }

        $draft = $cls::getById($draftId)->fetch();
        if (!$draft) {
            return null;
        }

        // Проверяем доступ
        if (!$this->canAccess($draft, $userId)) {
            return null;
        }

        return $this->enrichDraft($draft, $userId);
    }

    /**
     * Получить личные черновики пользователя.
     */
    public function getUserPrivateDrafts(int $userId): array
    {
        $cls = $this->getDataClass();
        if (!$cls) {
            return [];
        }

        $drafts = [];
        $rs = $cls::getList([
            'filter' => [
                'UF_USER_ID' => $userId,
                'UF_TYPE'    => self::TYPE_PRIVATE,
            ],
            'order' => ['UF_DATE_CREATE' => 'DESC'],
        ]);

        while ($draft = $rs->fetch()) {
            $drafts[] = $this->enrichDraft($draft, $userId);
        }

        return $drafts;
    }

    /**
     * Получить черновики, к которым пользователю дали доступ (shared).
     */
    public function getSharedWithUserDrafts(int $userId): array
    {
        $cls = $this->getDataClass();
        if (!$cls) {
            return [];
        }

        // Получаем ID черновиков, к которым есть доступ
        $accessRepo = $this->getAccessRepo();
        $accessibleIds = $accessRepo->getDraftsForUser($userId);

        if (empty($accessibleIds)) {
            return [];
        }

        $drafts = [];
        $rs = $cls::getList([
            'filter' => [
                'ID'      => $accessibleIds,
                'UF_TYPE' => self::TYPE_SHARED,
                // Исключаем свои черновики (их показываем в "Мои")
                '!UF_USER_ID' => $userId,
            ],
            'order' => ['UF_DATE_CREATE' => 'DESC'],
        ]);

        while ($draft = $rs->fetch()) {
            $drafts[] = $this->enrichDraft($draft, $userId);
        }

        return $drafts;
    }

    /**
     * Получить черновики, созданные пользователем с типом shared.
     * (Показываем в "Мои" вместе с private)
     */
    public function getUserSharedDrafts(int $userId): array
    {
        $cls = $this->getDataClass();
        if (!$cls) {
            return [];
        }

        $drafts = [];
        $rs = $cls::getList([
            'filter' => [
                'UF_USER_ID' => $userId,
                'UF_TYPE'    => self::TYPE_SHARED,
            ],
            'order' => ['UF_DATE_CREATE' => 'DESC'],
        ]);

        while ($draft = $rs->fetch()) {
            $drafts[] = $this->enrichDraft($draft, $userId);
        }

        return $drafts;
    }

    /**
     * Получить все черновики пользователя (private + его shared).
     */
    public function getUserOwnDrafts(int $userId): array
    {
        $cls = $this->getDataClass();
        if (!$cls) {
            return [];
        }

        $drafts = [];
        $rs = $cls::getList([
            'filter' => [
                'UF_USER_ID' => $userId,
                [
                    'LOGIC' => 'OR',
                    ['UF_TYPE' => self::TYPE_PRIVATE],
                    ['UF_TYPE' => self::TYPE_SHARED],
                    // Также включаем пустой тип (старые черновики)
                    ['UF_TYPE' => ''],
                    ['UF_TYPE' => false],
                ],
            ],
            'order' => ['UF_DATE_CREATE' => 'DESC'],
        ]);

        while ($draft = $rs->fetch()) {
            $drafts[] = $this->enrichDraft($draft, $userId);
        }

        return $drafts;
    }

    /**
     * Получить публичные черновики.
     */
    public function getPublicDrafts(int $currentUserId): array
    {
        $cls = $this->getDataClass();
        if (!$cls) {
            return [];
        }

        $drafts = [];
        $rs = $cls::getList([
            'filter' => [
                'UF_TYPE' => self::TYPE_PUBLIC,
            ],
            'order' => ['UF_DATE_CREATE' => 'DESC'],
        ]);

        while ($draft = $rs->fetch()) {
            $drafts[] = $this->enrichDraft($draft, $currentUserId);
        }

        return $drafts;
    }

    /**
     * Получить все черновики, сгруппированные по типу.
     * @return array{own: array, shared: array, public: array}
     */
    public function getAllDraftsGrouped(int $userId): array
    {
        return [
            'own'    => $this->getUserOwnDrafts($userId),
            'shared' => $this->getSharedWithUserDrafts($userId),
            'public' => $this->getPublicDrafts($userId),
        ];
    }

    /**
     * Обогатить данные черновика дополнительной информацией.
     */
    private function enrichDraft(array $draft, int $currentUserId): array
    {
        $draftId   = (int)$draft['ID'];
        $ownerId   = (int)$draft['UF_USER_ID'];
        $type      = $draft['UF_TYPE'] ?? self::TYPE_PRIVATE;
        
        // Старые черновики без типа считаем private
        if (empty($type)) {
            $type = self::TYPE_PRIVATE;
        }
        
        $isOwner   = ($ownerId === $currentUserId);

        // Информация о блокировке
        $lockService = $this->getLockService();
        $lockInfo    = $lockService->getLockInfo($draftId);

        // Информация о владельце
        $ownerInfo = $this->getUserInfo($ownerId);

        // Информация о пользователях с доступом (для shared)
        $accessUsers = [];
        if ($type === self::TYPE_SHARED) {
            $accessUsers = $this->getAccessRepo()->getUsersInfoForDraft($draftId);
        }

        // Форматируем дату
        $dateCreate = $draft['UF_DATE_CREATE'];
        $dateFormatted = '';
        if ($dateCreate instanceof DateTime) {
            $dateFormatted = $dateCreate->format('d.m.Y H:i');
        } elseif ($dateCreate) {
            $dateFormatted = date('d.m.Y H:i', strtotime($dateCreate));
        }

        return [
            'ID'             => $draftId,
            'NAME'           => $draft['UF_NAME'] ?? '',
            'TYPE'           => $type,
            'TYPE_LABEL'     => self::TYPE_LABELS[$type] ?? self::TYPE_LABELS[self::TYPE_PRIVATE],
            'OWNER_ID'       => $ownerId,
            'OWNER_NAME'     => $ownerInfo['NAME'] ?? '',
            'OWNER_PHOTO'    => $ownerInfo['PHOTO'] ?? '',
            'IS_OWNER'       => $isOwner,
            'DATE_CREATE'    => $dateFormatted,
            'IS_LOCKED'      => $lockInfo !== null,
            'LOCKED_BY'      => $lockInfo['locked_by'] ?? null,
            'LOCKED_BY_NAME' => $lockInfo['user_name'] ?? '',
            'LOCKED_BY_ME'   => $lockInfo && $lockInfo['locked_by'] === $currentUserId,
            'CAN_EDIT'       => $this->canEdit($draft, $currentUserId),
            'CAN_DELETE'     => $isOwner,
            'CAN_MANAGE_ACCESS' => $isOwner && $type === self::TYPE_SHARED,
            'ACCESS_USERS'   => $accessUsers,
        ];
    }

    /**
     * Получить информацию о пользователе.
     */
    private function getUserInfo(int $userId): array
    {
        $user = \CUser::GetByID($userId)->Fetch();
        if (!$user) {
            return ['NAME' => 'Неизвестный', 'PHOTO' => ''];
        }

        $photo = '';
        if ($user['PERSONAL_PHOTO']) {
            $file = \CFile::GetFileArray($user['PERSONAL_PHOTO']);
            if ($file) {
                $photo = $file['SRC'];
            }
        }

        return [
            'NAME'  => trim($user['NAME'] . ' ' . $user['LAST_NAME']) ?: $user['LOGIN'],
            'PHOTO' => $photo,
        ];
    }

    public function canAccess(array $draft, int $userId): bool
    {
        $type    = $draft['UF_TYPE'] ?? self::TYPE_PRIVATE;
        $ownerId = (int)$draft['UF_USER_ID'];

        // Владелец всегда имеет доступ
        if ($ownerId === $userId) {
            return true;
        }

        // Публичные доступны всем
        if ($type === self::TYPE_PUBLIC) {
            return true;
        }

        // Shared — проверяем список доступа
        if ($type === self::TYPE_SHARED) {
            return $this->getAccessRepo()->hasAccess((int)$draft['ID'], $userId);
        }

        // Private — только владелец
        return false;
    }

    /**
     * Может ли пользователь редактировать черновик.
     */
    public function canEdit(array $draft, int $userId): bool
    {
        // Сначала проверяем базовый доступ
        if (!$this->canAccess($draft, $userId)) {
            return false;
        }

        $type = $draft['UF_TYPE'] ?? self::TYPE_PRIVATE;

        // Private — только владелец
        if ($type === self::TYPE_PRIVATE || empty($type)) {
            return (int)$draft['UF_USER_ID'] === $userId;
        }

        // Shared и Public — все с доступом могут редактировать
        return true;
    }

    public function create(
        int    $userId,
        string $name,
        array  $cartData,
        string $type = self::TYPE_PRIVATE,
        array  $accessUserIds = []
    ): array {
        $cls = $this->getDataClass();
        if (!$cls) {
            return ['success' => false, 'error' => 'Черновики недоступны'];
        }

        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'error' => 'Название черновика не может быть пустым'];
        }

        if (!$this->isValidCartData($cartData)) {
            return ['success' => false, 'error' => 'Нельзя сохранить пустой черновик'];
        }

        if (!in_array($type, [self::TYPE_PRIVATE, self::TYPE_SHARED, self::TYPE_PUBLIC], true)) {
            $type = self::TYPE_PRIVATE;
        }

        // Создаём черновик
        $result = $cls::add([
            'UF_NAME'        => $name,
            'UF_USER_ID'     => $userId,
            'UF_DATA'        => json_encode($cartData, JSON_UNESCAPED_UNICODE),
            'UF_DATE_CREATE' => new DateTime(),
            'UF_TYPE'        => $type,
            'UF_LOCKED_BY'   => null,
            'UF_LOCKED_AT'   => null,
        ]);

        if (!$result->isSuccess()) {
            return ['success' => false, 'error' => 'Ошибка сохранения'];
        }

        $draftId = $result->getId();

        // Для shared — добавляем доступы
        if ($type === self::TYPE_SHARED && !empty($accessUserIds)) {
            $this->getAccessRepo()->setUsers($draftId, $accessUserIds);
        }

        return ['success' => true, 'draft_id' => $draftId];
    }

    /**
     * Обновить данные черновика (сохранить изменения корзины).
     */
    public function updateData(int $draftId, int $userId, array $cartData): array
    {
        $cls = $this->getDataClass();
        if (!$cls) {
            return ['success' => false, 'error' => 'Черновики недоступны'];
        }

        if (!$this->isValidCartData($cartData)) {
            return ['success' => false, 'error' => 'Нельзя сохранить пустой черновик'];
        }

        $draft = $cls::getById($draftId)->fetch();
        if (!$draft) {
            return ['success' => false, 'error' => 'Черновик не найден'];
        }

        if (!$this->canEdit($draft, $userId)) {
            return ['success' => false, 'error' => 'Нет прав на редактирование'];
        }

        $lockService = $this->getLockService();
        if ($lockService->isLockedByOther($draftId, $userId)) {
            $lockInfo = $lockService->getLockInfo($draftId);
            return [
                'success' => false,
                'error'   => 'Черновик редактирует ' . ($lockInfo['user_name'] ?? 'другой пользователь'),
            ];
        }

        $result = $cls::update($draftId, [
            'UF_DATA' => json_encode($cartData, JSON_UNESCAPED_UNICODE),
        ]);

        return $result->isSuccess()
            ? ['success' => true]
            : ['success' => false, 'error' => 'Ошибка сохранения'];
    }

    /**
     * В черновике должна быть хотя бы команда или хотя бы одна услуга.
     * Чистый {team: [], services: []} — пустой, сохранять не даём.
     */
    private function isValidCartData(array $cartData): bool
    {
        if ((int)($cartData['version'] ?? 0) !== 2) {
            return false;
        }
        $team     = $cartData['team']     ?? [];
        $services = $cartData['services'] ?? [];
        return !empty($team) || !empty($services);
    }

    /**
     * Переименовать черновик.
     */
    public function rename(int $draftId, int $userId, string $newName): array
    {
        $cls = $this->getDataClass();
        if (!$cls) {
            return ['success' => false, 'error' => 'Черновики недоступны'];
        }

        $newName = trim($newName);
        if ($newName === '') {
            return ['success' => false, 'error' => 'Название не может быть пустым'];
        }

        $draft = $cls::getById($draftId)->fetch();
        if (!$draft) {
            return ['success' => false, 'error' => 'Черновик не найден'];
        }

        // Переименовывать может только владелец
        if ((int)$draft['UF_USER_ID'] !== $userId) {
            return ['success' => false, 'error' => 'Только владелец может переименовать черновик'];
        }

        $result = $cls::update($draftId, ['UF_NAME' => $newName]);
        return $result->isSuccess()
            ? ['success' => true]
            : ['success' => false, 'error' => 'Ошибка переименования'];
    }

    /**
     * Изменить тип черновика.
     */
    public function changeType(int $draftId, int $userId, string $newType, array $accessUserIds = []): array
    {
        $cls = $this->getDataClass();
        if (!$cls) {
            return ['success' => false, 'error' => 'Черновики недоступны'];
        }

        if (!in_array($newType, [self::TYPE_PRIVATE, self::TYPE_SHARED, self::TYPE_PUBLIC], true)) {
            return ['success' => false, 'error' => 'Неверный тип черновика'];
        }

        $draft = $cls::getById($draftId)->fetch();
        if (!$draft) {
            return ['success' => false, 'error' => 'Черновик не найден'];
        }

        // Менять тип может только владелец
        if ((int)$draft['UF_USER_ID'] !== $userId) {
            return ['success' => false, 'error' => 'Только владелец может изменить тип'];
        }

        $result = $cls::update($draftId, ['UF_TYPE' => $newType]);
        if (!$result->isSuccess()) {
            return ['success' => false, 'error' => 'Ошибка изменения типа'];
        }

        // Обновляем доступы
        $accessRepo = $this->getAccessRepo();
        if ($newType === self::TYPE_SHARED) {
            $accessRepo->setUsers($draftId, $accessUserIds);
        } else {
            // Для private и public очищаем доступы
            $accessRepo->clearUsers($draftId);
        }

        return ['success' => true];
    }

    /**
     * Обновить список пользователей с доступом (для shared).
     */
    public function updateAccessUsers(int $draftId, int $userId, array $accessUserIds): array
    {
        $cls = $this->getDataClass();
        if (!$cls) {
            return ['success' => false, 'error' => 'Черновики недоступны'];
        }

        $draft = $cls::getById($draftId)->fetch();
        if (!$draft) {
            return ['success' => false, 'error' => 'Черновик не найден'];
        }

        // Управлять доступами может только владелец
        if ((int)$draft['UF_USER_ID'] !== $userId) {
            return ['success' => false, 'error' => 'Только владелец может управлять доступами'];
        }

        // Только для shared
        if (($draft['UF_TYPE'] ?? self::TYPE_PRIVATE) !== self::TYPE_SHARED) {
            return ['success' => false, 'error' => 'Доступы можно настраивать только для общих черновиков'];
        }

        $this->getAccessRepo()->setUsers($draftId, $accessUserIds);
        return ['success' => true];
    }

    /* ================================================================
       ЗАГРУЗКА / УДАЛЕНИЕ
       ================================================================ */

    /**
     * Загрузить данные черновика (для применения к корзине).
     * Поддерживается только формат v2: {version: 2, team, services}.
     * Черновики со старым форматом (без version или version<2) считаются
     * несуществующими — должны быть удалены при миграции (TRUNCATE drafts_table).
     *
     * @return array|null Данные корзины формата v2 или null
     */
    public function load(int $draftId, int $userId): ?array
    {
        $cls = $this->getDataClass();
        if (!$cls) {
            return null;
        }

        $draft = $cls::getById($draftId)->fetch();
        if (!$draft) {
            return null;
        }

        if (!$this->canAccess($draft, $userId)) {
            return null;
        }

        $data = json_decode($draft['UF_DATA'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        if (!is_array($data) || (int)($data['version'] ?? 0) !== 2) {
            return null;
        }
        if (!isset($data['team'], $data['services']) || !is_array($data['team']) || !is_array($data['services'])) {
            return null;
        }
        return $data;
    }

    /**
     * Удалить черновик.
     */
    public function delete(int $draftId, int $userId): array
    {
        $cls = $this->getDataClass();
        if (!$cls) {
            return ['success' => false, 'error' => 'Черновики недоступны'];
        }

        $draft = $cls::getById($draftId)->fetch();
        if (!$draft) {
            return ['success' => false, 'error' => 'Черновик не найден'];
        }

        // Удалять может только владелец
        if ((int)$draft['UF_USER_ID'] !== $userId) {
            return ['success' => false, 'error' => 'Только владелец может удалить черновик'];
        }

        // Удаляем доступы
        $this->getAccessRepo()->deleteAllForDraft($draftId);

        // Удаляем черновик
        $result = $cls::delete($draftId);
        return $result->isSuccess()
            ? ['success' => true]
            : ['success' => false, 'error' => 'Ошибка удаления'];
    }

    /* ================================================================
       БЛОКИРОВКА (делегируем в LockService)
       ================================================================ */

    /**
     * Заблокировать черновик для редактирования.
     */
    public function lock(int $draftId, int $userId): array
    {
        $cls = $this->getDataClass();
        if (!$cls) {
            return ['success' => false, 'error' => 'Черновики недоступны'];
        }

        $draft = $cls::getById($draftId)->fetch();
        if (!$draft) {
            return ['success' => false, 'error' => 'Черновик не найден'];
        }

        // Проверяем права
        if (!$this->canEdit($draft, $userId)) {
            return ['success' => false, 'error' => 'Нет прав на редактирование'];
        }

        return $this->getLockService()->lock($draftId, $userId);
    }

    /**
     * Продлить блокировку (heartbeat).
     */
    public function refreshLock(int $draftId, int $userId): array
    {
        return $this->getLockService()->refreshLock($draftId, $userId);
    }

    /**
     * Снять блокировку и сохранить данные.
     */
    public function unlockAndSave(int $draftId, int $userId, array $cartData): array
    {
        // Сначала сохраняем
        $saveResult = $this->updateData($draftId, $userId, $cartData);
        if (!$saveResult['success']) {
            return $saveResult;
        }

        // Потом разблокируем
        return $this->getLockService()->unlock($draftId, $userId);
    }

    /**
     * Снять блокировку без сохранения.
     */
    public function unlock(int $draftId, int $userId): array
    {
        return $this->getLockService()->unlock($draftId, $userId);
    }

    public function save(int $userId, string $name, array $cartData): array
    {
        return $this->create($userId, $name, $cartData, self::TYPE_PRIVATE);
    }

    public function getUserDrafts(int $userId): array
    {
        return $this->getUserOwnDrafts($userId);
    }
}
