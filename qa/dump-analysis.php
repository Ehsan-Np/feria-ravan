<?php
declare(strict_types=1);
$pdo = new PDO('mysql:host=localhost;dbname=feria_test', 'feria', 'feria_test_pass_2026', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET NAMES utf8mb4');
$cases = [
  ['gardner-mi', 'LING', 'high', 'full'],
  ['eq-shutte', 'E_M', 'high', 'full'],
  ['gardner-mi', null, 'overall', 'full'],
  ['eq-shutte', null, 'overall', 'full'],
  ['gardner-mi', 'BODY', 'any', 'free'],
];
foreach ($cases as [$slug, $sc, $band, $lvl]) {
    $tid = (int)$pdo->query("SELECT id FROM fr_tests WHERE slug='$slug'")->fetchColumn();
    $sql = "SELECT title, body FROM fr_analysis_templates WHERE test_id=$tid AND band='$band' AND level='$lvl'"
         . ($sc ? " AND scale_code='$sc'" : ' AND (scale_code IS NULL OR scale_code=\'\')')
         . ' LIMIT 1';
    $r = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    if (!$r) { echo "!! none for $slug/$sc/$band/$lvl\n\n"; continue; }
    echo "═══ $slug | " . ($sc ?: 'overall') . " | $band | $lvl\n《{$r['title']}》\n";
    $plain = strip_tags(str_replace(['<p>', '</p>', '<li>', '</li>'], ['', "\n", '• ', "\n"], $r['body']));
    echo mb_substr($plain, 0, 600), "\n\n";
}
