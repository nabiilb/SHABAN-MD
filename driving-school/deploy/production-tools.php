<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/*
|--------------------------------------------------------------------------
| Maintenance page for shared hosting
|--------------------------------------------------------------------------
|
| cPanel accounts often have no terminal, so `php artisan` has to be reached
| some other way. This is that other way, and it is deliberately small: it
| runs only the commands on the list below, and anything that resets, wipes,
| drops or seeds a database is refused outright, whatever is asked for.
|
| It boots Laravel in-process and calls the console kernel, which is why a
| few rules matter here more than they would at a terminal:
|
|   * PHP defines no STDIN under a web SAPI, so a command that asks a
|     question dies on `Undefined constant "STDIN"` part way through the
|     request, with no output and no exit code. Every command reachable from
|     here takes its confirmation as a flag instead.
|   * Illuminate\Console\Application::call() turns exception catching OFF for
|     the duration, so anything the command throws lands here. It is caught
|     and printed rather than left to a blank page.
|
| It is meant to live in public_html for a few minutes and then be deleted.
| While it is there, anybody who guesses the token can run these, so change
| the token below before uploading and delete the file afterwards.
|
| 1. Edit TOKEN.
| 2. Upload to /home1/prkmcqte/public_html/deploy-production.php
| 3. Visit https://your-domain/deploy-production.php?token=YOUR-TOKEN
| 4. DELETE THE FILE.
|
*/

const TOKEN = 'change-this-before-uploading';

const LARAVEL_PATH = '/home1/prkmcqte/laravel_app/driving-school-new';

/**
 * The only commands this page will run.
 *
 * Each is [command, options, description, writes?]. Options are passed to
 * the console kernel as an array, exactly as Artisan::call takes them.
 */
const ALLOWED = [
    'cache-clear' => ['optimize:clear', [], 'Clear every cache (config, route, view, events)', false],
    'config-cache' => ['config:cache', [], 'Rebuild the config cache', false],

    'workbook-diagnostic' => ['alpha-school:diagnose-workbook', [],
        'Which workbook is being read, and what is in column I — reads only', false],

    'student-import-dry-run' => ['alpha-school:import', ['--dry-run' => true],
        'Register import — report only, writes nothing', false],

    'alpha-expenses-import-dry-run' => ['alpha:import-expenses', ['--dry-run' => true, '--preview' => 0],
        'Expense ledger — report only, writes nothing', false],
    'alpha-expenses-import' => ['alpha:import-expenses', ['--confirm' => true, '--preview' => 0],
        'Expense ledger — REAL. This may write finance expense records only.', 'YES_IMPORT_ALPHA_EXPENSES'],

    'progress-repair-dry-run' => ['alpha-school:repair-training-progress', ['--dry-run' => true],
        'Required vs remaining days — report only, writes nothing', false],
    'progress-repair' => ['alpha-school:repair-training-progress', ['--confirm' => true],
        'Required vs remaining days — REAL, corrects four student columns', true],
];

/** The fixed source each command reads, relative to LARAVEL_PATH. */
const SOURCE_FILES = [
    'workbook-diagnostic' => 'storage/app/imports/ALPHA SCHOOL.xlsx',
    'student-import-dry-run' => 'storage/app/imports/ALPHA SCHOOL.xlsx',
    'progress-repair-dry-run' => 'storage/app/imports/ALPHA SCHOOL.xlsx',
    'progress-repair' => 'storage/app/imports/ALPHA SCHOOL.xlsx',
    'alpha-expenses-import-dry-run' => 'storage/app/imports/DEYNTA BISHI AAN ISTICMAALNAY ALPHA DRIVING SCHOOL.docx',
    'alpha-expenses-import' => 'storage/app/imports/DEYNTA BISHI AAN ISTICMAALNAY ALPHA DRIVING SCHOOL.docx',
];

/** Refused no matter how they arrive. */
const NEVER = ['fresh', 'wipe', 'drop', 'truncate', 'db:seed', 'seed', 'rollback', 'reset'];

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

if (! hash_equals(TOKEN, $_GET['token'] ?? '')) {
    http_response_code(404);
    exit('Not found');
}

$key = $_GET['run'] ?? null;
$log = [];
$error = null;

if ($key !== null) {
    if (! array_key_exists($key, ALLOWED)) {
        $error = 'Refused: that is not on the list.';
    }

    [$command, $options, , $writes] = $error === null ? ALLOWED[$key] : [null, null, null, null];

    foreach (NEVER as $forbidden) {
        if ($error === null && stripos((string) $command, $forbidden) !== false) {
            $error = "Refused: \"{$command}\" contains \"{$forbidden}\".";
        }
    }

    // A real run needs its own word in the URL. One click cannot write, and
    // the word differs per command, so a link cannot be reused by accident on
    // a different one.
    if ($error === null && $writes) {
        $expected = $writes === true ? 'yes' : $writes;
        $parameter = $writes === true ? 'i-have-read-the-dry-run' : 'confirm';

        if (($_GET[$parameter] ?? '') !== $expected) {
            $error = sprintf('Refused: read the dry run first, then add &%s=%s to the link.', $parameter, $expected);
        }
    }

    if ($error === null) {
        $log[] = 'Laravel path: '.LARAVEL_PATH;
        $log[] = 'Command:      '.$command.' '.json_encode($options);
        $log[] = 'PHP:          '.PHP_VERSION.' ('.PHP_SAPI.')';

        if (isset(SOURCE_FILES[$key])) {
            $file = LARAVEL_PATH.'/'.SOURCE_FILES[$key];
            $log[] = 'Source file:  '.SOURCE_FILES[$key];
            $log[] = '  present:    '.(is_readable($file)
                ? 'yes, '.number_format(filesize($file)).' bytes, sha256 '.substr(hash_file('sha256', $file), 0, 16).'…'
                : 'NO — the command will report this and do nothing');
        }

        $log[] = $writes
            ? 'MODE:         REAL. This may write finance expense records only.'
            : 'MODE:         DRY RUN. Nothing is written.';
        $log[] = '';

        // Long enough for a register of a few hundred rows on a slow shared
        // host, and no memory ceiling of our own on top of the host's.
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        try {
            require LARAVEL_PATH.'/vendor/autoload.php';

            /** @var Application $app */
            $app = require LARAVEL_PATH.'/bootstrap/app.php';

            $kernel = $app->make(Kernel::class);
            $kernel->bootstrap();

            $log[] = 'Starting '.$command.' …';

            $exitCode = $kernel->call($command, $options);

            $log[] = 'Command returned.';
            $log[] = '';
            $log[] = $kernel->output();
            $log[] = '';
            $log[] = 'Exit code: '.$exitCode;
            $log[] = $exitCode === 0 ? 'FINISHED' : 'FINISHED WITH ERRORS';
        } catch (Throwable $e) {
            // Console\Application::call() disables exception catching while it
            // runs, so whatever the command threw arrives here intact. Without
            // this the page would simply stop, which is indistinguishable from
            // a command that quietly did nothing.
            $log[] = '';
            $log[] = 'FAILED: '.$e::class;
            $log[] = 'Message: '.$e->getMessage();
            $log[] = 'Where:   '.$e->getFile().':'.$e->getLine();
            $log[] = '';
            $log[] = $e->getTraceAsString();

            http_response_code(500);
        }
    }
}

$self = htmlspecialchars(strtok($_SERVER['REQUEST_URI'], '?'), ENT_QUOTES);
$token = htmlspecialchars($_GET['token'], ENT_QUOTES);
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Maintenance</title>
<style>
    body { font: 15px/1.5 system-ui, sans-serif; max-width: 56rem; margin: 2rem auto; padding: 0 1rem; color: #0f172a; }
    h1 { font-size: 1.25rem; }
    .warn { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: .75rem 1rem; border-radius: .5rem; }
    ul { list-style: none; padding: 0; }
    li { margin: .5rem 0; }
    a.cmd { display: inline-block; font-family: ui-monospace, monospace; background: #f1f5f9; padding: .3rem .6rem; border-radius: .35rem; text-decoration: none; color: #1d4ed8; }
    a.real { background: #fef3c7; color: #92400e; }
    pre { background: #0f172a; color: #e2e8f0; padding: 1rem; border-radius: .5rem; overflow-x: auto; font-size: 13px; }
    .err { color: #b91c1c; font-weight: 600; }
</style>

<h1>Alpha Driving School — maintenance</h1>

<p class="warn"><strong>Delete this file as soon as you are finished.</strong>
While it is on the server, anyone with the link can run the commands below.</p>

<p>Laravel path: <code><?= htmlspecialchars(LARAVEL_PATH) ?></code><br>
Database: <code><?= htmlspecialchars(getenv('DB_DATABASE') ?: 'read from .env when the command boots') ?></code></p>

<p class="warn">Links shown in amber WRITE to the live database. Read the matching
dry run first — every one of them has one. The expense import writes finance
expense records only.</p>

<ul>
    <?php foreach (ALLOWED as $name => [$command, $options, $description, $writes]) { ?>
        <li>
            <a class="cmd <?= $writes ? 'real' : '' ?>"
               href="<?= $self ?>?token=<?= $token ?>&amp;run=<?= urlencode($name) ?><?= $writes === true ? '&amp;i-have-read-the-dry-run=yes' : ($writes ? '&amp;confirm='.$writes : '') ?>">
                <?= htmlspecialchars($name) ?>
            </a>
            — <?= htmlspecialchars($description) ?>
        </li>
    <?php } ?>
</ul>

<?php if ($error !== null) { ?>
    <p class="err"><?= htmlspecialchars($error) ?></p>
<?php } ?>

<?php if ($log !== []) { ?>
    <h2><?= htmlspecialchars((string) $key) ?></h2>
    <pre><?= htmlspecialchars(implode("\n", $log)) ?></pre>
<?php } ?>
