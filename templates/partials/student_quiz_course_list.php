<?php

declare(strict_types=1);

/** @var callable $h */
/** @var string $quizUrlBase */
/** @var string $dashboardUrl */
/** @var int $userId */
/** @var list<array<string,mixed>> $coursesWithQuizzes */

?>
<section id="section-quizzes" class="scroll-mt-20">
    <h2 class="mb-3 text-sm font-bold dark:text-zinc-100">Courses &amp; quizzes</h2>
    <?php if (!$coursesWithQuizzes): ?>
        <p class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm text-slate-500 dark:border-zinc-600 dark:bg-zinc-900/60 dark:text-zinc-400">No courses match your level and program yet.</p>
    <?php else: ?>
        <div class="space-y-2">
            <?php foreach ($coursesWithQuizzes as $course): ?>
                <?php foreach ($course['quizzes'] as $qz): ?>
                    <?php
                    $qid = (int) ($qz['id'] ?? 0);
                    $qtitle = (string) ($qz['title'] ?? '');
                    $qc = (int) ($qz['question_count'] ?? 0);
                    $stRaw = isset($qz['quiz_starts_at']) ? trim((string) $qz['quiz_starts_at']) : '';
                    $enRaw = isset($qz['quiz_ends_at']) ? trim((string) $qz['quiz_ends_at']) : '';
                    $stTs = $stRaw !== '' ? strtotime($stRaw) : false;
                    $enTs = $enRaw !== '' ? strtotime($enRaw) : false;
                    $stSec = ($stTs !== false && $stTs > 0) ? (int) $stTs : '';
                    $enSec = ($enTs !== false && $enTs > 0) ? (int) $enTs : '';
                    $phase = trytest_quiz_schedule_phase(
                        $stRaw !== '' ? $stRaw : null,
                        $enRaw !== '' ? $enRaw : null
                    );
                    $canPlay = ($phase === 'open' || $phase === 'unset');
                    $courseLabel = trim((string) ($course['code'] ?? '') . ' · ' . (string) ($course['title'] ?? ''));
                    $quizCardBg = ['bg-slate-50', 'bg-blue-50', 'bg-emerald-50', 'bg-amber-50'][($qid > 0 ? $qid : 0) % 4];
                    ?>
                    <article
                        class="trytest-quiz-card rounded-md border border-slate-200 px-2.5 py-2 shadow-sm <?php echo $h($quizCardBg); ?> dark:border-zinc-700 dark:bg-zinc-900"
                        data-quiz-id="<?php echo $qid; ?>"
                        data-user-id="<?php echo (int) $userId; ?>"
                        data-user-has-attempt="<?php echo !empty($qz['user_has_attempt']) ? '1' : '0'; ?>"
                        data-quiz-href="<?php echo $h($quizUrlBase); ?>?quiz_id=<?php echo $qid; ?>"
                        data-quiz-start="<?php echo $stSec !== '' ? (string) $stSec : ''; ?>"
                        data-quiz-end="<?php echo $enSec !== '' ? (string) $enSec : ''; ?>"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[9px] font-semibold uppercase tracking-wide text-[#2C6A7D] dark:text-[#7eb8b8]"><?php echo $h($courseLabel); ?></p>
                                <a href="<?php echo $h($quizUrlBase); ?>?quiz_id=<?php echo $qid; ?>" class="trytest-quiz-title block truncate text-[13px] font-semibold leading-snug <?php echo $canPlay ? 'text-slate-900 hover:text-[#2C6A7D] dark:text-zinc-100 dark:hover:text-[#7eb8b8]' : 'pointer-events-none cursor-default text-slate-600 dark:text-zinc-500'; ?>">
                                    <?php echo $h($qtitle); ?>
                                </a>
                                <p class="mt-0.5 text-[9px] text-slate-500 dark:text-zinc-400"><?php echo $qc < 1 ? 'No questions yet.' : ((string) $qc . ' questions'); ?></p>
                                <p class="trytest-quiz-progress mt-0.5 text-[9px] font-medium text-[#2C6A7D] dark:text-[#7eb8b8]" data-total="<?php echo $qc; ?>">
                                    <?php echo !empty($qz['user_has_attempt']) ? 'Completed before' : 'Not started'; ?>
                                </p>
                            </div>
                            <?php if ($canPlay): ?>
                                <span class="trytest-quiz-badge shrink-0 rounded-full bg-emerald-50 px-1.5 py-0.5 text-[8px] font-bold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">Open</span>
                            <?php elseif ($phase === 'before'): ?>
                                <span class="trytest-quiz-badge shrink-0 rounded-full bg-amber-50 px-1.5 py-0.5 text-[8px] font-bold text-amber-800 dark:bg-amber-950/50 dark:text-amber-200">Soon</span>
                            <?php else: ?>
                                <span class="trytest-quiz-badge shrink-0 rounded-full bg-slate-100 px-1.5 py-0.5 text-[8px] font-bold text-slate-500 dark:bg-zinc-800 dark:text-zinc-400">Closed</span>
                            <?php endif; ?>
                        </div>
                        <div class="trytest-quiz-countdown mt-1 hidden min-h-[1.6rem] w-full rounded-md border border-transparent px-1.5 py-1 text-center text-[10px] font-black tabular-nums leading-tight tracking-tight sm:text-[11px]" role="status" aria-live="polite"></div>
                    </article>
                <?php endforeach; ?>
                <?php if (empty($course['quizzes'])): ?>
                    <p class="rounded-lg border border-dashed border-slate-200 px-3 py-2 text-center text-[11px] text-slate-500 dark:border-zinc-700 dark:text-zinc-400">
                        <?php echo $h((string) ($course['code'] ?? '')); ?> — nothing left to start here.
                        <a href="<?php echo $h(rtrim($dashboardUrl, '/') . '?tab=results'); ?>" class="font-semibold text-[#2C6A7D] underline dark:text-[#7eb8b8]">My results</a>
                        has finished quizzes and <strong>Try again</strong>.
                    </p>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
