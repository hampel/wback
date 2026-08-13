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

it('shows a setting that is deliberately turned off', function () {
    useSites(<<<'TOML'
        [zabbix]
        domain = 'zabbix.example.com'
        files = ''
        TOML);

    $this->artisan('app:sites', ['site' => 'zabbix'])
        ->expectsOutputToContain('files: (none)')
        ->assertSuccessful();
});

it('does not list a key the site leaves out', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        TOML);

    $this->artisan('app:sites', ['site' => 'example'])
        ->doesntExpectOutputToContain('files')
        ->assertSuccessful();
});

it('shows booleans as written rather than as 1 and nothing', function () {
    useSites(<<<'TOML'
        [legacy]
        domain = 'legacy.example.com'
        single_transaction = false

        [modern]
        domain = 'modern.example.com'
        single_transaction = true
        TOML);

    $this->artisan('app:sites')
        ->expectsOutputToContain('single_transaction: false')
        ->expectsOutputToContain('single_transaction: true')
        ->assertSuccessful();
});

it('shows an empty list as empty', function () {
    useSites(<<<'TOML'
        [example]
        domain = 'example.com'
        exclude = []
        TOML);

    $this->artisan('app:sites', ['site' => 'example'])
        ->expectsOutputToContain('exclude: (none)')
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
