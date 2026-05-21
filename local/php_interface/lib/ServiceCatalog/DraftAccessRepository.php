<?php

namespace Ithive\Goalsbazhen\ServiceCatalog;

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Loader;


class DraftAccessRepository
{
    private ?int $hlId = null;
    private ?string $dataClass = null;

    public function __construct()
    {
        Loader::includeModule('highloadblock');
        $this->resolveHlId();
    }

    /**
     * Найти ID HL-блока по имени таблицы.
     */
    private function resolveHlId(): void
    {
        $row = HighloadBlockTable::getList([
            'filter' => ['TABLE_NAME' => 'draft_access_table'],
            'select' => ['ID'],
            'limit'  => 1,
        ])->fetch();

        $this->hlId = $row ? (int)$row['ID'] : null;
    }

    /**
     * Проверить, доступен ли HL-блок.
     */
    public function isAvailable(): bool
    {
        return $this->hlId !== null;
    }

    /**
     * Получить DataClass для работы с HL-блоком.
     */
    private function getDataClass(): ?string
    {
        if (!$this->hlId) return null;

        if ($this->dataClass !== null) {
            return $this->dataClass;
        }

        $hl = HighloadBlockTable::getById($this->hlId)->fetch();
        if (!$hl) return null;

        $entity = HighloadBlockTable::compileEntity($hl);
        $this->dataClass = $entity->getDataClass();

        return $this->dataClass;
    }

    /* ================================================================
       ЧТЕНИЕ
       ================================================================ */

    /**
     * Получить список ID пользователей с доступом к черновику.
     * 
     * @return int[]
     */
    public function getUsersForDraft(int $draftId): array
    {
        $cls = $this->getDataClass();
        if (!$cls) return [];

        $users = [];
        $rs = $cls::getList([
            'filter' => ['UF_DRAFT_ID' => $draftId],
            'select' => ['UF_USER_ID'],
        ]);

        while ($row = $rs->fetch()) {
            $users[] = (int)$row['UF_USER_ID'];
        }

        return $users;
    }

    public function getDraftsForUser(int $userId): array
    {
        $cls = $this->getDataClass();
        if (!$cls) return [];

        $drafts = [];
        $rs = $cls::getList([
            'filter' => ['UF_USER_ID' => $userId],
            'select' => ['UF_DRAFT_ID'],
        ]);

        while ($row = $rs->fetch()) {
            $drafts[] = (int)$row['UF_DRAFT_ID'];
        }

        return array_unique($drafts);
    }

    /**
     * Проверить, есть ли у пользователя доступ к черновику.
     */
    public function hasAccess(int $draftId, int $userId): bool
    {
        $cls = $this->getDataClass();
        if (!$cls) return false;

        $row = $cls::getList([
            'filter' => [
                'UF_DRAFT_ID' => $draftId,
                'UF_USER_ID'  => $userId,
            ],
            'select' => ['ID'],
            'limit'  => 1,
        ])->fetch();

        return (bool)$row;
    }

    public function addUser(int $draftId, int $userId): bool
    {
        if ($this->hasAccess($draftId, $userId)) {
            return true; // Уже есть
        }

        $cls = $this->getDataClass();
        if (!$cls) return false;

        $result = $cls::add([
            'UF_DRAFT_ID' => $draftId,
            'UF_USER_ID'  => $userId,
        ]);

        return $result->isSuccess();
    }

    /**
     * Удалить пользователя из черновика.
     */
    public function removeUser(int $draftId, int $userId): bool
    {
        $cls = $this->getDataClass();
        if (!$cls) return false;

        $row = $cls::getList([
            'filter' => [
                'UF_DRAFT_ID' => $draftId,
                'UF_USER_ID'  => $userId,
            ],
            'select' => ['ID'],
        ])->fetch();

        if (!$row) {
            return true; // Уже удалён
        }

        $result = $cls::delete($row['ID']);
        return $result->isSuccess();
    }

    public function setUsers(int $draftId, array $userIds): bool
    {
        // Получаем текущих
        $currentUsers = $this->getUsersForDraft($draftId);
        $newUsers     = array_unique(array_filter(array_map('intval', $userIds)));

        // Удаляем тех, кого нет в новом списке
        $toRemove = array_diff($currentUsers, $newUsers);
        foreach ($toRemove as $userId) {
            $this->removeUser($draftId, $userId);
        }

        // Добавляем новых
        $toAdd = array_diff($newUsers, $currentUsers);
        foreach ($toAdd as $userId) {
            $this->addUser($draftId, $userId);
        }

        return true;
    }


    public function clearUsers(int $draftId): bool
    {
        $cls = $this->getDataClass();
        if (!$cls) return false;

        $rs = $cls::getList([
            'filter' => ['UF_DRAFT_ID' => $draftId],
            'select' => ['ID'],
        ]);

        while ($row = $rs->fetch()) {
            $cls::delete($row['ID']);
        }

        return true;
    }

    public function deleteDraftAccess(int $draftId): bool
    {
        return $this->clearUsers($draftId);
    }

    public function deleteAllForDraft(int $draftId): bool
    {
        return $this->clearUsers($draftId);
    }


    public function getUsersInfoForDraft(int $draftId): array
    {
        $userIds = $this->getUsersForDraft($draftId);
        if (empty($userIds)) return [];

        return $this->fetchUsersInfo($userIds);
    }

    public function fetchUsersInfo(array $userIds): array
    {
        if (empty($userIds)) return [];

        $users = [];
        $rs = \CUser::GetList(
            'ID',
            'ASC',
            ['ID' => implode('|', $userIds)],
            ['SELECT' => ['ID', 'NAME', 'LAST_NAME', 'PERSONAL_PHOTO']]
        );

        while ($user = $rs->Fetch()) {
            $photo = '';
            if (!empty($user['PERSONAL_PHOTO'])) {
                $file = \CFile::GetFileArray($user['PERSONAL_PHOTO']);
                if ($file) {
                    $photo = $file['SRC'];
                }
            }

            $userId = (int)$user['ID'];
            $users[$userId] = [
                'id'    => $userId,
                'name'  => trim($user['NAME'] . ' ' . $user['LAST_NAME']) ?: 'Пользователь #' . $userId,
                'photo' => $photo,
            ];
        }

        return $users;
    }

    /**
     * Тонкий адаптер над Repository::searchBitrixUsers — сохраняет
     * исторический контракт {id, name, photo} (последнее поле — это alias
     * к новому avatar), плюс не ищет по EMAIL, как было исторически.
     */
    public function searchUsers(string $query, int $limit = 20, array $excludeIds = []): array
    {
        $users = (new Repository())->searchBitrixUsers($query, [
            'limit'       => $limit,
            'excludeIds'  => $excludeIds,
            'searchEmail' => false,
        ]);
        $legacy = [];
        foreach ($users as $u) {
            $legacy[] = [
                'id'    => $u['id'],
                'name'  => $u['name'],
                'photo' => $u['avatar'],
            ];
        }
        return $legacy;
    }
}
