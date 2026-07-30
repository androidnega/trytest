<?php

declare(strict_types=1);

/**
 * Keep sqlite AUTOINCREMENT in sync with MAX(quizzes.id) so new quizzes
 * do not jump to huge IDs after deletes.
 */
function trytest_admin_sync_quiz_id_sequence(PDO $db, ?int $maxId = null): void
{
    if ($maxId === null) {
        $maxId = (int) ($db->query('SELECT COALESCE(MAX(id), 0) FROM quizzes')->fetchColumn() ?: 0);
    }
    try {
        if ($maxId < 1) {
            $db->exec("DELETE FROM sqlite_sequence WHERE name = 'quizzes'");

            return;
        }
        $exists = $db->query("SELECT 1 FROM sqlite_sequence WHERE name = 'quizzes' LIMIT 1")->fetchColumn();
        if ($exists) {
            $db->prepare("UPDATE sqlite_sequence SET seq = ? WHERE name = 'quizzes'")->execute([$maxId]);
        } else {
            $db->prepare("INSERT INTO sqlite_sequence(name, seq) VALUES ('quizzes', ?)")->execute([$maxId]);
        }
    } catch (Throwable $e) {
        // sqlite_sequence may be missing on some builds
    }
}

/**
 * Renumber quiz IDs to 1..N and rewrite all quiz_id foreign keys.
 * Call after quiz deletes so IDs stay compact.
 */
function trytest_admin_repack_quiz_ids(PDO $db): void
{
    $ids = $db->query('SELECT id FROM quizzes ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
    $count = count($ids);
    if ($count === 0) {
        trytest_admin_sync_quiz_id_sequence($db, 0);

        return;
    }

    $needsRepack = false;
    foreach ($ids as $i => $id) {
        if ((int) $id !== ($i + 1)) {
            $needsRepack = true;
            break;
        }
    }
    if (!$needsRepack) {
        trytest_admin_sync_quiz_id_sequence($db, $count);

        return;
    }

    $childTables = [
        'questions',
        'scores',
        'score_attempts',
        'quiz_courses',
        'quiz_presence_ping',
    ];

    $db->exec('PRAGMA foreign_keys = OFF');
    $db->beginTransaction();
    try {
        $offset = 1_000_000;
        $updQuiz = $db->prepare('UPDATE quizzes SET id = ? WHERE id = ?');
        foreach ($ids as $i => $oldId) {
            $oldId = (int) $oldId;
            $tmp = $offset + $i + 1;
            foreach ($childTables as $table) {
                try {
                    $db->prepare('UPDATE ' . $table . ' SET quiz_id = ? WHERE quiz_id = ?')->execute([$tmp, $oldId]);
                } catch (Throwable $e) {
                    // table may not exist on older installs
                }
            }
            $updQuiz->execute([$tmp, $oldId]);
        }
        foreach ($ids as $i => $_old) {
            $tmp = $offset + $i + 1;
            $new = $i + 1;
            foreach ($childTables as $table) {
                try {
                    $db->prepare('UPDATE ' . $table . ' SET quiz_id = ? WHERE quiz_id = ?')->execute([$new, $tmp]);
                } catch (Throwable $e) {
                }
            }
            $updQuiz->execute([$new, $tmp]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        try {
            $db->exec('PRAGMA foreign_keys = ON');
        } catch (Throwable $e2) {
        }
        throw $e;
    }
    try {
        $db->exec('PRAGMA foreign_keys = ON');
    } catch (Throwable $e) {
    }
    trytest_admin_sync_quiz_id_sequence($db, $count);
}
