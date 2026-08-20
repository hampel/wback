<?php

namespace App\Support;

use Hampel\SlackMessage\SlackAttachment;
use Hampel\SlackMessage\SlackMessage;
use Hampel\SlackMessage\SlackWebhook;
use Illuminate\Support\Carbon;

/**
 * The one message a night that says whether the backup worked
 *
 * This is deliberately not a log channel. Logging to Slack posts one message per record
 * at or above a level, which means a run where five sites fail is five walls of
 * exception text, and a run where nothing fails is silence indistinguishable from a
 * cron entry that was never installed. A summary is the other shape: raised once, by
 * the run, carrying what the run did.
 *
 * The log channel stays as the backstop for anything that goes wrong somewhere nobody
 * thought to summarise. The two are complementary, not alternatives.
 */
class SlackSummary
{
    use FormatsSizes;
    use ReadsCommandOutput;

    /**
     * Failures listed in full before the rest are counted - past this, the message is
     * telling you to go and read the log, and should say so rather than reprinting it
     */
    protected const MAX_FAILURES = 10;

    /**
     * @var int longest failure message to quote, before it stops being a summary
     */
    protected const MAX_MESSAGE = 300;

    public function __construct(protected SlackWebhook $slack)
    {
    }

    /**
     * @return bool whether this run is one the operator asked to hear about
     */
    public function shouldSend(RunSummary $summary) : bool
    {
        if (empty($this->webhook()))
        {
            return false;
        }

        // "failure" is for an installation that would rather have silence than a nightly
        // all-clear - at the cost of not being able to tell working from uninstalled
        return config('backup.summary.notify') === 'failure' ? $summary->failed() : true;
    }

    /**
     * @return bool whether there is anywhere to send a summary
     */
    public function isConfigured() : bool
    {
        return !empty($this->webhook());
    }

    /**
     * Post the summary
     *
     * @throws \RuntimeException if Slack would not take it
     */
    public function send(RunSummary $summary) : void
    {
        $this->post($this->build($summary));
    }

    /**
     * Post a message that proves the webhook works
     *
     * A mistyped or revoked webhook is invisible until the night it matters, because
     * nothing about a summary that was never delivered reaches the machine that sent
     * it. Actually posting is the only check worth having.
     *
     * @throws \RuntimeException if Slack would not take it
     */
    public function sendTest() : void
    {
        $this->post($this->slack->message(function (SlackMessage $message) {
            $message
                ->success()
                ->content('Test message from app:validate on ' . $this->host())
                ->attachment(function (SlackAttachment $attachment) {
                    $attachment
                        ->fallback('Test message from app:validate on ' . $this->host())
                        ->content('The run summary is configured and this webhook works.')
                        ->footer(config('app.name') . ' ' . app()->version() . ' on ' . $this->host())
                        ->timestamp(Carbon::now());
                });
        }));
    }

    /**
     * @throws \RuntimeException if Slack would not take it
     */
    protected function post(SlackMessage $message) : void
    {
        $response = $this->slack->send($this->webhook(), $message);

        if (!$this->slack->accepted($response))
        {
            throw new \RuntimeException('Slack refused the message: ' . $this->slack->error($response));
        }
    }

    public function build(RunSummary $summary) : SlackMessage
    {
        return $this->slack->message(function (SlackMessage $message) use ($summary) {
            $summary->failed() ? $message->error() : $message->success();

            $message->content($this->headline($summary));

            $message->attachment(function (SlackAttachment $attachment) use ($summary) {
                $attachment
                    ->fallback($this->headline($summary))
                    ->fields($this->fields($summary))
                    ->footer(config('app.name') . ' ' . app()->version() . ' on ' . $this->host())
                    ->timestamp(Carbon::now());

                $body = $this->body($summary);

                if ($body !== '')
                {
                    $attachment->content($body);
                }
            });
        });
    }

    /**
     * @return string the line that has to be readable from a phone notification
     */
    protected function headline(RunSummary $summary) : string
    {
        $prefix = $summary->isDryRun() ? '[Dry run] ' : '';

        $outcome = match (true) {
            $summary->blockedBy() !== null => 'did not run',
            $summary->failed() => 'failed',
            default => 'completed',
        };

        return "{$prefix}Backup {$outcome} on " . $this->host();
    }

    /**
     * @return array<string, string> the run in numbers, as Slack's two column fields
     */
    protected function fields(RunSummary $summary) : array
    {
        // a run that never started has nothing to count, and zeroes would read as a run
        // that did start and found nothing to do
        if ($summary->blockedBy() !== null)
        {
            return [];
        }

        $fields = [
            'Sites' => (string) $summary->siteCount(),
            'Backups' => (string) $summary->backupCount(),
            'Written' => $this->human_filesize($summary->bytes()),
            'Duration' => $this->duration($summary->seconds()),
        ];

        $ran = $summary->stagesRun();
        $fields['Stages'] = $ran === [] ? 'none' : implode(', ', $ran);

        $skipped = $summary->stagesSkipped();

        if ($skipped !== [])
        {
            $fields['Skipped'] = implode(', ', $skipped);
        }

        if ($summary->failures() !== [])
        {
            $fields['Failures'] = (string) count($summary->failures());
        }

        return $fields;
    }

    /**
     * @return string what went wrong, named by site and stage so it can be acted on
     *                without opening the log first
     */
    protected function body(RunSummary $summary) : string
    {
        if ($summary->blockedBy() !== null)
        {
            return $summary->blockedBy();
        }

        $failures = $summary->failures();

        if ($failures === [])
        {
            // a stage can fail without any one site failing - an inventory that will not
            // parse, a stage nobody configured - and a red message with nothing in it
            // saying what went wrong is worse than no message
            $stages = $summary->stagesFailed();

            return $stages === []
                ? ''
                : 'Failed during ' . implode(', ', $stages) . ' - see the log';
        }

        $lines = [];

        foreach (array_slice($failures, 0, self::MAX_FAILURES) as $failure)
        {
            $lines[] = sprintf(
                '%s (%s): %s',
                $failure['site'],
                $failure['stage'],
                $this->reason($failure['message'])
            );
        }

        $remaining = count($failures) - self::MAX_FAILURES;

        if ($remaining > 0)
        {
            $lines[] = "... and {$remaining} more - see the log";
        }

        return implode("\n", $lines);
    }

    /**
     * A failure is often several lines of a command's own output, and all of it is in
     * the log already - here it only has to be enough to recognise
     *
     * @param string $message what went wrong
     * @return string
     */
    protected function reason(string $message) : string
    {
        $message = $this->firstLine($message);

        return mb_strlen($message) > self::MAX_MESSAGE
            ? mb_substr($message, 0, self::MAX_MESSAGE) . '...'
            : $message;
    }

    protected function duration(float $seconds) : string
    {
        // a run that took a fraction of a second is a real answer, and "0s" reads as a
        // duration nobody measured
        if ($seconds < 1)
        {
            return '<1s';
        }

        $seconds = (int) round($seconds);

        if ($seconds < 60)
        {
            return "{$seconds}s";
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60)
        {
            return sprintf('%dm %ds', $minutes, $seconds % 60);
        }

        return sprintf('%dh %dm', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * @return string the machine this ran on, which is the whole point of a fleet
     *                reporting to one webhook
     */
    protected function host() : string
    {
        return (string) (config('logging.hostname') ?: gethostname());
    }

    protected function webhook() : string
    {
        return (string) config('backup.summary.slack_webhook');
    }
}
