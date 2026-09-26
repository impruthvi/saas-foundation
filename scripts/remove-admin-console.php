<?php

declare(strict_types=1);

/*
 * Remove the admin console, leaving the product whole.
 *
 * Run from the project root: `php scripts/remove-admin-console.php`.
 *
 * The console owns its screens, its provider, the operator list and the two
 * commands that manage it. The product keeps everything else: the audit log,
 * impersonation (which nobody can start without operators), the webhook log,
 * replay and the read services. CI runs this on every push and then runs the
 * suite, the type check, the build and the navigation smoke test, which is
 * what "removable" means here.
 */

use Illuminate\Filesystem\Filesystem;
use Laravel\Chisel\Chisel;
use PhpParser\Error as ParserError;

require __DIR__.'/../vendor/autoload.php';

$root = dirname(__DIR__);
$chisel = Chisel::in($root);
$filesystem = new Filesystem();

foreach ([
    'app/Filament',
    'app/Providers/Filament',
    'tests/Feature/AdminConsole',
    'public/css/filament',
    'public/js/filament',
    'public/fonts/filament',
] as $directory) {
    $filesystem->deleteDirectory($root.'/'.$directory);
}

$chisel->files(
    'app/Models/Operator.php',
    'database/factories/OperatorFactory.php',
    'app/Actions/GrantOperator.php',
    'app/Actions/RevokeOperator.php',
    'app/Console/Commands/GrantOperatorCommand.php',
    'app/Console/Commands/RevokeOperatorCommand.php',
    ...array_map(
        fn (string $path): string => mb_substr($path, mb_strlen($root) + 1),
        glob($root.'/database/migrations/*_create_operators_table.php') ?: [],
    ),
)->delete();

$chisel->file('app/Models/User.php')->removeSection('admin-console');

try {
    $chisel->php('app/Models/User.php')
        ->removeInterface('FilamentUser')
        ->save();
} catch (ParserError $parserError) {
    fwrite(STDERR, "Could not rewrite app/Models/User.php: {$parserError->getMessage()}\n");

    exit(1);
}

// chisel's import removal looks only inside a file whose sole top-level node
// is its namespace, and `declare(strict_types=1)` makes a second one, so the
// imports go by their exact lines.
foreach (['use Filament\Models\Contracts\FilamentUser;', 'use Filament\Panel;', 'use App\Contracts\Operators;'] as $import) {
    $chisel->file('app/Models/User.php')->removeLinesContaining($import);
}

$chisel->file('bootstrap/providers.php')->removeLinesContaining('AdminConsoleServiceProvider');

$chisel->file('composer.json')->replace(
    "\"@php artisan package:discover --ansi\",\n            \"@php artisan filament:upgrade\"",
    '"@php artisan package:discover --ansi"',
);

$chisel->file('.gitignore')->removeLinesContaining('/filament');

passthru('composer remove filament/filament --no-interaction', $exitCode);

if ($exitCode !== 0) {
    exit($exitCode);
}

// Views compiled while Livewire was installed carry its Blade hooks, and
// anything that renders them afterwards, Wayfinder included, fails on the
// missing class. Every cache built against the old dependency set goes.
passthru('php artisan optimize:clear', $exitCode);

if ($exitCode !== 0) {
    exit($exitCode);
}

// Cutting a method out of User leaves the blank lines around it behind.
passthru('vendor/bin/pint app/Models/User.php bootstrap/providers.php', $exitCode);

exit($exitCode);
