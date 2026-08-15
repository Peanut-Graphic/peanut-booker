<?php

declare(strict_types=1);

/**
 * Enforce Peanut Booker's PHP host/development floors and eligible CI runners.
 */

$root = dirname(__DIR__);
$failures = [];

$read = static function (string $relative) use ($root, &$failures): string {
    $path = $root . '/' . $relative;
    $contents = is_file($path) ? file_get_contents($path) : false;
    if ($contents === false) {
        $failures[] = sprintf('%s is missing or unreadable', $relative);

        return '';
    }

    return $contents;
};

$composer = json_decode($read('composer.json'), true);
$lock = json_decode($read('composer.lock'), true);

if (!is_array($composer)) {
    $failures[] = 'composer.json is not valid JSON';
} else {
    if (($composer['require']['php'] ?? null) !== '>=8.0') {
        $failures[] = 'composer.json require.php must preserve the PHP 8.0 host floor';
    }
    if (($composer['config']['platform']['php'] ?? null) !== '8.1.0') {
        $failures[] = 'composer.json config.platform.php must be exact PHP 8.1.0';
    }
}

if (!is_array($lock)) {
    $failures[] = 'composer.lock is not valid JSON';
} else {
    if (($lock['platform']['php'] ?? null) !== '>=8.0') {
        $failures[] = 'composer.lock platform.php must preserve the PHP 8.0 host floor';
    }
    if (($lock['platform-overrides']['php'] ?? null) !== '8.1.0') {
        $failures[] = 'composer.lock platform-overrides.php must be exact PHP 8.1.0';
    }

    $packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);
    $versions = [];
    foreach ($packages as $package) {
        if (isset($package['name'], $package['version'])) {
            $versions[$package['name']] = $package['version'];
        }
    }
    if (($versions['doctrine/instantiator'] ?? null) !== '2.0.0') {
        $failures[] = 'composer.lock must retain the PHP 8.1 development-floor witness';
    }
}

if (!preg_match('/^\s*\* Requires PHP:\s*8\.0\s*$/m', $read('peanut-booker.php'))) {
    $failures[] = 'peanut-booker.php must declare PHP 8.0';
}

$readme = $read('README.md');
if (!preg_match('/^- PHP 8\.0\+$/m', $readme)
    || !str_contains($readme, 'PHP test tooling require PHP 8.1 or later')
    || !preg_match('/^- PHP 8\.1\+ for Composer dependencies and test tooling$/m', $readme)) {
    $failures[] = 'README.md must document PHP 8.0 hosts and PHP 8.1 development';
}

$testWorkflow = $read('.github/workflows/tests.yml');
$runtimePatterns = [
    '/^  php-runtime-minimum:\s*$/m' => 'PHP 8.0 runtime job',
    '/php-version:\s*["\']8\.0["\']/' => 'exact PHP 8.0 setup',
    '/verify-php-runtime\.php --expect-runtime=8\.0/' => 'PHP 8.0 identity assertion',
    "/git ls-files -z '\\*\.php' \\| xargs -0 -n1 php -l/" => 'tracked-tree parser gate',
    '/^  php-development-minimum:\s*$/m' => 'PHP 8.1 development job',
    '/php-version:\s*["\']8\.1["\']/' => 'exact PHP 8.1 setup',
    '/verify-php-runtime\.php --expect-development-runtime=8\.1/' => 'PHP 8.1 identity assertion',
    '/phpunit -c phpunit\.property\.xml/' => 'property suite',
    '/php-version:\s*["\']8\.3["\']/' => 'current PHP 8.3 setup',
];
foreach ($runtimePatterns as $pattern => $description) {
    if (!preg_match($pattern, $testWorkflow)) {
        $failures[] = sprintf('%s is missing from tests workflow', $description);
    }
}

foreach (['.github/workflows/tests.yml', '.github/workflows/wp-contract.yml', '.github/workflows/accessibility.yml'] as $workflowFile) {
    $workflow = $read($workflowFile);
    if (!preg_match('/runs-on:\s*ubuntu-latest/', $workflow)) {
        $failures[] = sprintf('%s must use an eligible GitHub-hosted runner', $workflowFile);
    }
    if (preg_match('/runs-on:\s*\[[^\]]*self-hosted/', $workflow)) {
        $failures[] = sprintf('%s must not target the ineligible public self-hosted runner group', $workflowFile);
    }
}

if (!preg_match('/php-version:\s*["\']8\.3["\']/', $read('.github/workflows/wp-contract.yml'))) {
    $failures[] = 'wp-contract must retain the real WordPress PHP 8.3 lane';
}

$argument = $argv[1] ?? '';
if ($argument === '--expect-runtime=8.0' && PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION !== '8.0') {
    $failures[] = sprintf('expected the PHP 8.0 host runtime, got %s', PHP_VERSION);
}
if ($argument === '--expect-development-runtime=8.1' && PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION !== '8.1') {
    $failures[] = sprintf('expected the PHP 8.1 development runtime, got %s', PHP_VERSION);
}

if ($failures !== []) {
    fwrite(STDERR, "PHP runtime declaration contract failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "PHP runtime declaration contract passed (host 8.0, development 8.1; hosted CI).\n");
