<?php

/*
|--------------------------------------------------------------------------
| The suite must not send anything
|--------------------------------------------------------------------------
|
| tests/Pest.php pins two settings so a developer whose .env carries a real
| webhook does not have the suite post to it: the default log channel, and the
| run summary webhook. Both pins carry a comment, and a comment is read by
| whoever opens the file, which is nobody. These tests are read by whoever
| deletes the pin, which is the person who needs telling.
|
| They fail on exactly the machine where it matters - one with a live webhook in
| .env - because that is where the leak would be real. A machine with no webhook
| configured has nothing to leak and will stay green either way, which is a limit
| worth knowing rather than a reason not to have them.
|
| A sibling tool found its own suite posting 41 real messages per run this way. Three
| reasonable decisions lined up: the project .env loads during tests, the sender
| is a container singleton built from config, and the transport under it is a
| real PSR-18 client rather than the Http facade. Nothing throws, so nothing said.
|
*/

it('pins the run summary webhook, so a developer .env cannot make the suite post', function () {
    expect(config('backup.summary.slack_webhook'))->toBe('')
        ->and(app(App\Support\SlackSummary::class)->isConfigured())->toBeFalse();
});

it('pins the default log channel to null', function () {
    expect(config('logging.default'))->toBe('null');
});

/*
 * Monolog builds its own curl for the slack driver rather than using the PSR-18
 * client in the container, so fakeSlack() is not in that path and cannot stand in
 * for a real webhook. A log channel is the one place a live-looking URL is not
 * caught by anything, which is why the rule is about the URL and not the guard.
 */
it('never points a log channel at a slack domain that resolves', function () {
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('tests')));

    foreach ($files as $file)
    {
        if ($file->getExtension() !== 'php')
        {
            continue;
        }

        foreach (file($file->getPathname()) as $number => $line)
        {
            // a slack URL on a LOGGING setting - the summary webhook is a different
            // path, and fakeSlack() does cover that one
            if (preg_match('#logging\.channels\.[a-z]+\.url#i', $line)
                && preg_match('#hooks\.slack\.(?!test)[a-z]+#i', $line))
            {
                $offenders[] = basename($file->getPathname()) . ':' . ($number + 1);
            }
        }
    }

    expect($offenders)->toBe([],
        'A log channel is pointed at a resolvable Slack domain: ' . implode(', ', $offenders)
        . ' - use hooks.slack.test, which cannot resolve. Monolog runs its own curl here,'
        . ' so fakeSlack() will not save you and the request goes to the real internet.');
});

/*
 * The first layer, of which the pins above are the second: the suite never loads the
 * developer's .env at all. Unlike the pin guards, which only fail on a machine whose
 * .env holds a live webhook, this fails on every machine - CI included - the moment
 * it stops being true.
 */
it('boots from the test environment file, never the developer\'s .env', function () {
    $loaded = app('wback.env.loaded');

    expect($loaded)->not->toBeNull()
        ->and(realpath((string) $loaded))->toBe(realpath(base_path('tests/testing.env')));
});

