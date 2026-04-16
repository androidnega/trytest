<?php

declare(strict_types=1);

require __DIR__ . '/config/db.php';

$count = (int) $db->query('SELECT COUNT(*) FROM quizzes')->fetchColumn();
if ($count === 0) {
    $courseStmt = $db->prepare('INSERT INTO courses (code, title, level) VALUES (?, ?, ?)');
    $courseStmt->execute(['ITS201', 'Introduction to Information Systems', '200']);
    $courseId = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO quizzes (title) VALUES (?)')->execute(['Sample quiz']);
    $quizId = (int) $db->lastInsertId();
    $db->prepare('UPDATE quizzes SET level = ?, course_id = ? WHERE id = ?')->execute(['200', $courseId, $quizId]);
    $db->prepare('INSERT OR IGNORE INTO quiz_courses (quiz_id, course_id) VALUES (?, ?)')->execute([$quizId, $courseId]);

    $samples = [
        ['mcq', 'What is 2 + 2?', '3', '4', '5', '22', '4'],
        ['mcq', 'Capital of France?', 'Berlin', 'Madrid', 'Paris', 'Rome', 'Paris'],
        ['fill', 'Type the word for a canine pet:', null, null, null, null, 'dog'],
        ['mcq', 'Largest ocean?', 'Atlantic', 'Indian', 'Arctic', 'Pacific', 'Pacific'],
        ['fill', '10 minus 3 equals:', null, null, null, null, '7'],
    ];

    $ins = $db->prepare(
        'INSERT INTO questions (quiz_id, question_type, question, option_a, option_b, option_c, option_d, correct_answer)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($samples as $row) {
        $ins->execute([$quizId, $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6]]);
    }
}

header('Content-Type: text/plain; charset=utf-8');
echo "OK — database ready.\n";
echo '- Students: open ' . trytest_home_url() . "\n";
echo '- Admin: open ' . trytest_home_with_query(['mode' => 'admin']) . " — on first visit, create the administrator account (no default password).\n";
echo 'URLs use no .php suffix. base_path auto: /trytest only on localhost/LAN/.local; production hosts always root. SetEnv TRYTEST_WEB_BASE to override. Legacy /trytest/... on production 301s to /... . mod_rewrite + AllowOverride required for extensionless paths.' . "\n";
