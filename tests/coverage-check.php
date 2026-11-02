<?php

/**
 * @file
 * Fails unless the coverage shows 100 % of lines and branches.
 *
 * Reads the PHP report of php-code-coverage (build/coverage.cov), which keeps
 * the branches measured by Xdebug, and lists each line or branch not covered.
 *
 * Usage, from the module directory:
 *   php tests/coverage-check.php build/coverage.cov
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../../vendor/autoload.php';

$file = $argv[1] ?? 'build/coverage.cov';
if (!is_file($file)) {
  fwrite(STDERR, "Rapport de couverture introuvable : $file\n");
  exit(1);
}
$coverage = include $file;
$data = $coverage->getData();
$root = realpath(__DIR__ . '/..') . '/';
$name = fn (string $path) => str_replace($root, '', $path);

$lines = $linesHit = $branches = $branchesHit = 0;
$missing = [];
foreach ($data->lineCoverage() as $path => $byLine) {
  foreach ($byLine as $line => $tests) {
    if ($tests === NULL) {
      continue;
    }
    $lines++;
    if ($tests) {
      $linesHit++;
    }
    else {
      $missing[] = sprintf('%s:%d (ligne)', $name($path), $line);
    }
  }
}
foreach ($data->functionCoverage() as $path => $functions) {
  foreach ($functions as $function => $info) {
    // The body of a file, outside functions: php-code-coverage leaves it out.
    if ($function === '{main}') {
      continue;
    }
    foreach ($info['branches'] ?? [] as $id => $branch) {
      $branches++;
      if (!empty($branch['hit'])) {
        $branchesHit++;
      }
      else {
        $missing[] = sprintf('%s:%d-%d %s, branche %d', $name($path), $branch['line_start'], $branch['line_end'], $function, $id);
      }
    }
  }
}

printf("Couverture : lignes %d/%d, branches %d/%d\n", $linesHit, $lines, $branchesHit, $branches);
foreach ($missing as $m) {
  echo "  non couvert : $m\n";
}
if ($branches === 0) {
  fwrite(STDERR, "Aucune branche mesurée : lancez PHPUnit avec Xdebug (XDEBUG_MODE=coverage).\n");
  exit(1);
}
if ($missing) {
  fwrite(STDERR, "La couverture doit être de 100 % des lignes et des branches.\n");
  exit(1);
}
echo "100 % des lignes et des branches.\n";
