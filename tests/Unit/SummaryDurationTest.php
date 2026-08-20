<?php

use App\Support\SlackSummary;
use Hampel\SlackMessage\SlackWebhook;
use Psr\Http\Client\ClientInterface;

/**
 * How long the run took, as a person reads it.
 *
 * Instantiated directly - formatting a number of seconds needs no application, and no
 * request is ever made, so the client is a stub that would fail loudly if one were.
 */
function duration(float $seconds): string
{
    $summary = new class (new SlackWebhook(new class implements ClientInterface
    {
        public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            throw new \RuntimeException('the formatter should not be sending anything');
        }
    })) extends SlackSummary
    {
        public function readable(float $seconds): string
        {
            return $this->duration($seconds);
        }
    };

    return $summary->readable($seconds);
}

it('says a run that took no time at all took less than a second', function () {
    // "0s" reads as a duration nobody measured, rather than one that was very short
    expect(duration(0.0))->toBe('<1s')
        ->and(duration(0.4))->toBe('<1s')
        ->and(duration(0.999))->toBe('<1s');
});

it('counts seconds up to a minute', function () {
    expect(duration(1.0))->toBe('1s')
        ->and(duration(1.4))->toBe('1s')
        ->and(duration(59.4))->toBe('59s');
});

it('counts minutes and seconds up to an hour', function () {
    expect(duration(60))->toBe('1m 0s')
        ->and(duration(2832))->toBe('47m 12s')
        ->and(duration(3599))->toBe('59m 59s');
});

it('counts hours and minutes beyond that', function () {
    expect(duration(3600))->toBe('1h 0m')
        ->and(duration(19800))->toBe('5h 30m');
});
