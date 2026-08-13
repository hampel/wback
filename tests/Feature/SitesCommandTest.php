<?php

it('lists every configured site', function () {
    useSites(<<<'TOML'
        [first]
        domain = 'first.example.com'

        [second]
        domain = 'second.example.com'
        TOML);

    $this->artisan('app:sites')
        ->expectsOutput('first')
        ->expectsOutput('second')
        ->expectsOutputToContain('domain: first.example.com')
        ->expectsOutputToContain('domain: second.example.com')
        ->assertSuccessful();
});

it('lists a single site', function () {
    useSites(<<<'TOML'
        [first]
        domain = 'first.example.com'

        [second]
        domain = 'second.example.com'
        TOML);

    $this->artisan('app:sites', ['site' => 'first'])
        ->expectsOutputToContain('domain: first.example.com')
        ->doesntExpectOutputToContain('second.example.com')
        ->assertSuccessful();
});

it('lists array values one per line', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        exclude = ['data/tmp/*', 'internal_data/cache/*']
        TOML);

    $this->artisan('app:sites', ['site' => 'example'])
        ->expectsOutputToContain('exclude:')
        ->expectsOutputToContain('data/tmp/*')
        ->expectsOutputToContain('internal_data/cache/*')
        ->assertSuccessful();
});

it('fails when the requested site is not configured', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('app:sites', ['site' => 'missing'])
        ->expectsOutputToContain('Could not find definition for site: missing')
        ->assertFailed();
});

it('fails when there are no sites configured', function () {
    useSites('');

    $this->artisan('app:sites')
        ->expectsOutputToContain('No sites found at:')
        ->assertFailed();
});

it('fails when the sites file cannot be parsed', function () {
    useSites("[example\ndomain = ");

    $this->artisan('app:sites')->assertFailed();
});
