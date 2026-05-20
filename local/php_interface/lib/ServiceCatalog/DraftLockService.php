<?php

namespace Ithive\Goalsbazhen\ServiceCatalog;

use Bitrix\Main\Type\DateTime;


class DraftLockService
{
    public const LOCK_CONFIRM_INTERVAL = 600; // 10 минут

    public const LOCK_CONFIRM_TIMEOUT = 120; // 2 минуты

    public const LOCK_MAX_TIME = 720; 

    private Repository $repo;

    public function __construct(Repository $repo)
    {
        $this->repo = $repo;
    }

    public function getLockInfo(int $draftId): ?array
    {
        $cls = $this->getDraftsDataClass();
        if (!$cls) return null;

        $draft = $cls::getById($draftId)->fetch();
        if (!$draft) return null;

        $lockedBy = (int)($draft['UF_LOCKED_BY'] ?? 0);
        $lockedAt = $draft['UF_LOCKED_AT'] ?? null;

        if (!$lockedBy) {
            return null;
        }

        // Проверяем, не истекла ли блокировка
        if ($this->isLockExpired($lockedAt)) {
            // Автоматически снимаем просроченную блокировку
            $this->forceUnlockInternal($draftId);
            return null;
        }

        $userName = $this->getUserName($lockedBy);

        return [
            'locked_by'   => $lockedBy,
            'locked_by_id'   => $lockedBy,
            'user_name' => $userName,
            'locked_by_name' => $userName,
            'locked_at'      => $lockedAt instanceof DateTime ? $lockedAt->toString() : $lockedAt,
        ];
    }

    /**
     * Проверить, заблокирован ли черновик другим пользователем.
     */
    public function isLockedByOther(int $draftId, int $currentUserId): bool
    {
        $lock = $this->getLockInfo($draftId);
        if (!$lock) return false;
        return $lock['locked_by'] !== $currentUserId;
    }

    public function lock(int $draftId, int $userId): array
    {
        $cls = $this->getDraftsDataClass();
        if (!$cls) {
            return ['success' => false, 'error' => 'Черновики недоступны'];
        }

        $draft = $cls::getById($draftId)->fetch();
        if (!$draft) {
            return ['success' => false, 'error' => 'Черновик не найден'];
        }

        $lockedBy = (int)($draft['UF_LOCKED_BY'] ?? 0);
        $lockedAt = $draft['UF_LOCKED_AT'] ?? null;

        // Уже заблокирован этим пользователем — продлеваем
        if ($lockedBy === $userId) {
            return $this->renewLock($draftId, $userId);
        }

        // Заблокирован другим и блокировка не истекла
        if ($lockedBy && !$this->isLockExpired($lockedAt)) {
            $userName = $this->getUserName($lockedBy);
            return [
                'success'   => false,
                'error'     => "Черновик редактирует {$userName}",
                'lock_info' => [
                    'locked_by'      => $lockedBy,
                    'locked_by_id'   => $lockedBy,
                    'user_name'      => $userName,
                    'locked_by_name' => $userName,
                ],
            ];
        }

        // Блокируем
        $result = $cls::update($draftId, [
            'UF_LOCKED_BY' => $userId,
            'UF_LOCKED_AT' => new DateTime(),
        ]);

        if ($result->isSuccess()) {
            return ['success' => true];
        }

        return ['success' => false, 'error' => 'Ошибка блокировки'];
    }

    /**
     * Продлить блокировку (heartbeat).
     */
    public function renewLock(int $draftId, int $userId): array
    {
        $cls = $this->getDraftsDataClass();
        if (!$cls) {
            return ['success' => false, 'error' => 'Черновики недоступны'];
        }

        $draft = $cls::getById($draftId)->fetch();
        if (!$draft) {
            return ['success' => false, 'error' => 'Черновик не найден'];
        }

        $lockedBy = (int)($draft['UF_LOCKED_BY'] ?? 0);

        // Можно продлить только свою блокировку
        if ($lockedBy !== $userId) {
            // Если не заблокирован никем — блокируем
            if (!$lockedBy) {
                return $this->lock($draftId, $userId);
            }
            return ['success' => false, 'error' => 'Вы не владеете блокировкой'];
        }

        $result = $cls::update($draftId, [
            'UF_LOCKED_AT' => new DateTime(),
        ]);

        if ($result->isSuccess()) {
            return ['success' => true];
        }

        return ['success' => false, 'error' => 'Ошибка продления блокировки'];
    }

    /**
     * Алиас для renewLock.
     */
    public function refreshLock(int $draftId, int $userId): array
    {
        return $this->renewLock($draftId, $userId);
    }

    /**
     * Снять блокировку.
     */
    public function unlock(int $draftId, int $userId): array
    {
        $cls = $this->getDraftsDataClass();
        if (!$cls) {
            return ['success' => false, 'error' => 'Черновики недоступны'];
        }

        $draft = $cls::getById($draftId)->fetch();
        if (!$draft) {
            return ['success' => false, 'error' => 'Черновик не найден'];
        }

        $lockedBy = (int)($draft['UF_LOCKED_BY'] ?? 0);

        // Можно снять только свою блокировку (или просроченную)
        if ($lockedBy && $lockedBy !== $userId && !$this->isLockExpired($draft['UF_LOCKED_AT'])) {
            return ['success' => false, 'error' => 'Вы не владеете блокировкой'];
        }

        $result = $cls::update($draftId, [
            'UF_LOCKED_BY' => null,
            'UF_LOCKED_AT' => null,
        ]);

        if ($result->isSuccess()) {
            return ['success' => true];
        }

        return ['success' => false, 'error' => 'Ошибка снятия блокировки'];
    }

    /**
     * Принудительное снятие блокировки (внутреннее, без проверок).
     */
    private function forceUnlockInternal(int $draftId): void
    {
        $cls = $this->getDraftsDataClass();
        if (!$cls) return;

        $cls::update($draftId, [
            'UF_LOCKED_BY' => null,
            'UF_LOCKED_AT' => null,
        ]);
    }

    /**
     * Проверить, истекла ли блокировка.
     */
    private function isLockExpired($lockedAt): bool
    {
        if (!$lockedAt) return true;

        $lockTime = $lockedAt instanceof DateTime
            ? $lockedAt->getTimestamp()
            : strtotime($lockedAt);

        return (time() - $lockTime) > self::LOCK_MAX_TIME;
    }

    /**
     * Получить имя пользователя по ID.
     */
    private function getUserName(int $userId): string
    {
        $user = \CUser::GetByID($userId)->Fetch();
        if (!$user) return "Пользователь #{$userId}";

        $name = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
        return $name ?: ($user['LOGIN'] ?? "Пользователь #{$userId}");
    }

    /**
     * Получить DataClass для HL-блока черновиков.
     */
    private function getDraftsDataClass(): ?string
    {
        $hlId = $this->repo->getDraftsHlId();
        return $hlId ? $this->repo->getHlDataClass($hlId) : null;
    }

    /**
     * Получить константы для JS.
     */
    public static function getJsConfig(): array
    {
        return [
            'confirmInterval' => self::LOCK_CONFIRM_INTERVAL,
            'confirmTimeout'  => self::LOCK_CONFIRM_TIMEOUT,
            'maxTime'         => self::LOCK_MAX_TIME,
        ];
    }

    /**
     * Получить интервал heartbeat в секундах.
     */
    public static function getHeartbeatIntervalSeconds(): int
    {
        return self::LOCK_CONFIRM_INTERVAL;
    }

    /**
     * Получить таймаут подтверждения в секундах.
     */
    public static function getConfirmTimeoutSeconds(): int
    {
        return self::LOCK_CONFIRM_TIMEOUT;
    }
}
