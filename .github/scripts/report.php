<?php

declare(strict_types=1);

/*
 * Convertit les rapports de la CI en badges (format endpoint shields.io) et en résumés Markdown.
 *
 * Usage : php .github/scripts/report.php <tests|coverage|phpstan> <rapport> <dossier-de-sortie>
 *
 * Écrit <dossier>/<type>.json (badge) et <dossier>/<type>.md (résumé pour la PR et le job).
 * Pour phpstan, affiche aussi les erreurs en annotations GitHub et sort en erreur s'il y en a.
 */

[, $type, $input, $outputDir] = $_SERVER['argv'] + [null, null, null, null];

if (!in_array($type, ['tests', 'coverage', 'phpstan'], true) || $input === null || $outputDir === null) {
    fwrite(STDERR, "Usage : php report.php <tests|coverage|phpstan> <rapport> <dossier-de-sortie>\n");
    exit(2);
}

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0o777, true);
}

/**
 * @return array{string, string, int}
 */
function tests(string $input): array
{
    if (!is_file($input)) {
        return [badge('tests', 'aucun rapport', 'lightgrey'), "### Tests\n\nAucun rapport JUnit produit.\n", 1];
    }

    $suite = simplexml_load_file($input)->testsuite;
    $total = (int) $suite['tests'];
    $failed = (int) $suite['failures'] + (int) $suite['errors'];
    $skipped = (int) $suite['skipped'];
    $passed = $total - $failed - $skipped;

    $message = $failed > 0 ? "$failed en échec / $total" : "$passed réussis";
    $markdown = "### Tests\n\n| Réussis | Échecs | Ignorés | Total |\n|---|---|---|---|\n"
        . "| $passed | $failed | $skipped | $total |\n";

    return [badge('tests', $message, $failed > 0 ? 'red' : 'brightgreen'), $markdown, 0];
}

/**
 * @return array{string, string, int}
 */
function coverage(string $input): array
{
    if (!is_file($input)) {
        return [badge('couverture', 'aucun rapport', 'lightgrey'), "### Couverture\n\nAucun rapport produit.\n", 0];
    }

    $metrics = simplexml_load_file($input)->project->metrics;
    $rows = [
        'Lignes' => [(int) $metrics['coveredstatements'], (int) $metrics['statements']],
        'Méthodes' => [(int) $metrics['coveredmethods'], (int) $metrics['methods']],
    ];

    $markdown = "### Couverture\n\n| | Couvert | Total | % |\n|---|---|---|---|\n";
    foreach ($rows as $label => [$covered, $total]) {
        $markdown .= sprintf("| %s | %d | %d | %s |\n", $label, $covered, $total, percent($covered, $total));
    }

    [$covered, $total] = $rows['Lignes'];
    $rate = $total > 0 ? 100 * $covered / $total : 0;
    $color = match (true) {
        $rate >= 80 => 'brightgreen',
        $rate >= 60 => 'yellow',
        default => 'red',
    };

    return [badge('couverture', percent($covered, $total), $color), $markdown, 0];
}

/**
 * @return array{string, string, int}
 */
function phpstan(string $input): array
{
    $report = is_file($input) ? json_decode((string) file_get_contents($input), true) : null;
    if (!is_array($report)) {
        return [badge('phpstan', 'échec de l\'analyse', 'red'), "### PHPStan\n\nL'analyse n'a pas pu s'exécuter.\n", 1];
    }

    $workspace = rtrim((string) getenv('GITHUB_WORKSPACE'), '/') . '/';
    $lines = [];
    foreach ($report['files'] as $file => $result) {
        $file = str_replace($workspace, '', $file);
        foreach ($result['messages'] as $error) {
            $line = (int) ($error['line'] ?? 1);
            echo "::error file=$file,line=$line::" . str_replace(["\r", "\n"], ' ', $error['message']) . "\n";
            $lines[] = "- `$file:$line` — {$error['message']}";
        }
    }
    foreach ($report['errors'] as $error) {
        echo "::error::$error\n";
        $lines[] = "- $error";
    }

    $count = count($lines);
    $markdown = "### PHPStan (niveau 6)\n\n" . ($count === 0
        ? "Aucune erreur.\n"
        : "$count erreur(s) :\n\n" . implode("\n", array_slice($lines, 0, 30))
            . ($count > 30 ? "\n- … et " . ($count - 30) . " autre(s)" : '') . "\n");

    $badge = badge('phpstan', $count === 0 ? 'niveau 6' : "niveau 6 · $count erreur(s)", $count === 0 ? 'brightgreen' : 'red');

    return [$badge, $markdown, $count === 0 ? 0 : 1];
}

function badge(string $label, string $message, string $color): string
{
    return json_encode(
        ['schemaVersion' => 1, 'label' => $label, 'message' => $message, 'color' => $color],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
}

function percent(int $covered, int $total): string
{
    return $total > 0 ? number_format(100 * $covered / $total, 1, ',', '') . ' %' : '—';
}

[$badge, $markdown, $exitCode] = $type($input);

file_put_contents("$outputDir/$type.json", $badge . "\n");
file_put_contents("$outputDir/$type.md", $markdown);

exit($exitCode);
