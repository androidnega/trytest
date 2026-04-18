<?php

declare(strict_types=1);

/**
 * @return array{keywords: list<string>, accept: list<string>}
 */
function trytest_theory_rubric_decode(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return ['keywords' => [], 'accept' => []];
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return ['keywords' => [], 'accept' => []];
    }
    $kw = $data['keywords'] ?? [];
    $ac = $data['accept'] ?? [];
    if (!is_array($kw)) {
        $kw = [];
    }
    if (!is_array($ac)) {
        $ac = [];
    }
    $kwOut = [];
    foreach ($kw as $w) {
        $t = trim((string) $w);
        if ($t !== '') {
            $kwOut[] = $t;
        }
    }
    $acOut = [];
    foreach ($ac as $a) {
        $t = trim((string) $a);
        if ($t !== '') {
            $acOut[] = $t;
        }
    }

    return ['keywords' => array_values(array_unique($kwOut)), 'accept' => array_values(array_unique($acOut))];
}

/**
 * @param list<string> $keywords
 * @param list<string> $accept
 */
function trytest_theory_rubric_encode(array $keywords, array $accept): ?string
{
    $keywords = array_values(array_unique(array_filter(array_map('trim', $keywords))));
    $accept = array_values(array_unique(array_filter(array_map('trim', $accept))));
    if ($keywords === [] && $accept === []) {
        return null;
    }

    return json_encode(['keywords' => $keywords, 'accept' => $accept], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
