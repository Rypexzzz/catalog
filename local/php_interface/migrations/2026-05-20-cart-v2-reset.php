<?php

/**
 * Миграция выкатки модели корзины v2 (команда + назначения).
 *
 * Что делает:
 *  1. Чистит HL-блок `drafts_table` — старые v1-черновики хранят сериализованный
 *     плоский cart и при загрузке в v2 окажутся пустыми; их проще удалить,
 *     чем пытаться сконвертировать (грейд → ставка → специалист нельзя
 *     восстановить однозначно).
 *  2. Чистит HL-блок `draft_access_table` — он ссылается на удалённые черновики.
 *  3. На session.save_path удаляет файлы битриксовых сессий (`bx_sess_*`), у
 *     которых внутри лежит v1-корзина. Чтобы не дропать **все** сессии,
 *     грепаем содержимое по `SERVICE_CART_` без `i:2;` рядом с `version`.
 *     Это эвристика, она ничего не делает, если файлы недоступны.
 *
 * CartService.php сам разбирается с v1-сессиями (см. looksLikeV1) и резетит
 * их «на лету», так что пункт 3 — это в основном уборка диска.
 *
 * Запуск:
 *   php local/php_interface/migrations/2026-05-20-cart-v2-reset.php
 *
 * Запускать ОДИН РАЗ, после деплоя кода. Идемпотентно — повторный запуск
 * безопасен, просто почистит черновики ещё раз.
 */

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);

require_once __DIR__ . '/../../../bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Highloadblock\HighloadBlockTable as HLBT;

Loader::includeModule('highloadblock');

function migrate_truncate_hl(string $tableName): int
{
    $row = HLBT::getList(['filter' => ['TABLE_NAME' => $tableName]])->fetch();
    if (!$row) {
        echo "  ! HL-блок '{$tableName}' не найден, пропускаем\n";
        return 0;
    }

    $entity = HLBT::compileEntity($row);
    $class  = $entity->getDataClass();
    $rs     = $class::getList(['select' => ['ID']]);
    $count  = 0;
    while ($r = $rs->fetch()) {
        $class::delete((int)$r['ID']);
        $count++;
    }
    return $count;
}

function migrate_clean_sessions(): int
{
    $savePath = session_save_path() ?: sys_get_temp_dir();
    if (!is_dir($savePath) || !is_readable($savePath)) {
        echo "  ! Каталог сессий '{$savePath}' недоступен, пропускаем\n";
        return 0;
    }

    $count = 0;
    foreach (glob($savePath . '/bx_sess_*') ?: [] as $file) {
        if (!is_writable($file)) continue;
        $content = @file_get_contents($file, false, null, 0, 65536);
        if ($content === false) continue;
        if (!str_contains($content, 'SERVICE_CART_')) continue;
        // version|i:2 — маркер v2. Если его нет, считаем сессию v1 и удаляем.
        if (str_contains($content, 's:7:"version";i:2')) continue;
        if (@unlink($file)) $count++;
    }
    return $count;
}

echo "=== Cart v2 migration ===\n";

echo "1. Чистка черновиков (drafts_table)…\n";
$dropped = migrate_truncate_hl('drafts_table');
echo "   удалено: {$dropped}\n";

echo "2. Чистка прав доступа к черновикам (draft_access_table)…\n";
$dropped = migrate_truncate_hl('draft_access_table');
echo "   удалено: {$dropped}\n";

echo "3. Чистка файлов v1-сессий…\n";
$dropped = migrate_clean_sessions();
echo "   удалено: {$dropped}\n";

echo "\n✓ Миграция завершена. CartService подхватит новые сессии автоматически.\n";
