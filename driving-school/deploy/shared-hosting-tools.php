<?php

/*
|--------------------------------------------------------------------------
| Temporary maintenance page for shared hosting
|--------------------------------------------------------------------------
|
| cPanel accounts often have no terminal, and `php artisan` has to be reached
| some other way. This is that other way, and it is deliberately small:
|
|   * it will only run the commands on the list below;
|   * the register import is only ever offered with --dry-run, and a request
|     for the real import is refused here, not merely discouraged;
|   * anything that resets, wipes, drops or seeds a database is refused
|     outright, whatever is asked for.
|
| It is meant to live in public_html for a few minutes and then be deleted.
| While it is there, anybody who guesses the token can clear your caches, so
| change the token below before uploading and delete the file afterwards.
|
| 1. Edit TOKEN.
| 2. Upload to /home1/prkmcqte/public_html/deploy-tools.php
| 3. Visit https://your-domain/deploy-tools.php?token=YOUR-TOKEN
| 4. DELETE THE FILE.
|
*/

const TOKEN = 'change-this-before-uploading';

const LARAVEL_PATH = '/home1/prkmcqte/laravel_app/driving-school-new';

/** The only commands this page will run. Nothing else is reachable. */
const ALLOWED = [
    'optimize:clear' => 'Clear every cache (config, route, view, events)',
    'config:clear' => 'Clear the config cache',
    'route:clear' => 'Clear the route cache',
    'view:clear' => 'Clear compiled Blade views',
    'config:cache' => 'Rebuild the config cache',
    'alpha-school:import --dry-run' => 'Read the register and report — writes nothing',
];

/** Refused no matter how they arrive. */
const NEVER = ['fresh', 'wipe', 'drop', 'truncate', 'db:seed', 'seed', 'rollback', 'reset'];

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

if (! hash_equals(TOKEN, $_GET['token'] ?? '')) {
    http_response_code(404);
    exit('Not found');
}

$command = $_GET['run'] ?? null;
$output = null;
$error = null;

if ($command !== null) {
    $normalised = trim((string) $command);

    foreach (NEVER as $forbidden) {
        if (stripos($normalised, $forbidden) !== false) {
            $error = "Refused: \"{$normalised}\" contains \"{$forbidden}\".";
            break;
        }
    }

    // The register import is available here in dry-run form only. The real
    // import writes to live student and payment tables and is not something
    // to set off from a web page by accident.
    if ($error === null && str_starts_with($normalised, 'alpha-school:import') && ! str_contains($normalised, '--dry-run')) {
        $error = 'Refused: the real import is not available from this page. Run it from a terminal, '
            .'or ask your developer to run it, once you have read the dry run.';
    }

    if ($error === null && ! array_key_exists($normalised, ALLOWED)) {
        $error = "Refused: \"{$normalised}\" is not on the list.";
    }

    if ($error === null) {
        $artisan = LARAVEL_PATH.DIRECTORY_SEPARATOR.'artisan';

        if (! is_file($artisan)) {
            $error = "Cannot find {$artisan}. Check LARAVEL_PATH at the top of this file.";
        } else {
            $php = PHP_BINARY ?: 'php';
            $full = escapeshellcmd($php).' '.escapeshellarg($artisan).' '.$normalised.' --no-interaction 2>&1';

            $output = shell_exec($full) ?? '(no output — shell_exec may be disabled on this host)';
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
    body { font: 15px/1.5 system-ui, sans-serif; max-width: 52rem; margin: 2rem auto; padding: 0 1rem; color: #0f172a; }
    h1 { font-size: 1.25rem; }
    .warn { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: .75rem 1rem; border-radius: .5rem; }
    ul { list-style: none; padding: 0; }
    li { margin: .4rem 0; }
    a.cmd { display: inline-block; font-family: ui-monospace, monospace; background: #f1f5f9; padding: .3rem .6rem; border-radius: .35rem; text-decoration: none; color: #1d4ed8; }
    pre { background: #0f172a; color: #e2e8f0; padding: 1rem; border-radius: .5rem; overflow-x: auto; font-size: 13px; }
    .err { color: #b91c1c; font-weight: 600; }
</style>

<h1>Alpha Driving School — maintenance</h1>

<p class="warn"><strong>Delete this file as soon as you are finished.</strong>
While it is on the server, anyone with the link can run the commands below.</p>

<p>Laravel path: <code><?= htmlspecialchars(LARAVEL_PATH) ?></code></p>

<ul>
    <?php foreach (ALLOWED as $name => $description) { ?>
        <li>
            <a class="cmd" href="<?= $self ?>?token=<?= $token ?>&amp;run=<?= urlencode($name) ?>"><?= htmlspecialchars($name) ?></a>
            — <?= htmlspecialchars($description) ?>
        </li>
    <?php } ?>
</ul>

<?php if ($error !== null) { ?>
    <p class="err"><?= htmlspecialchars($error) ?></p>
<?php } ?>

<?php if ($output !== null) { ?>
    <h2><?= htmlspecialchars((string) $command) ?></h2>
    <pre><?= htmlspecialchars($output) ?></pre>
<?php } ?>
