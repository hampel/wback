<?php namespace App\Subscribers;

use Log;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;

class LoggingCacheSubscriber
{
	use SubscribesToEvents;

    /**
     * Register the listeners for the subscriber.
     *
     * @param  \Illuminate\Events\Dispatcher  $events
     */
    public function subscribe($events)
    {
        $events->listen(
            CacheHit::class,
            $this->method('cacheHit')
        );

        $events->listen(
            CacheMissed::class,
            $this->method('cacheMissed')
        );

        $events->listen(
            KeyForgotten::class,
            $this->method('cacheKeyForgotten')
        );

        $events->listen(
            KeyWritten::class,
            $this->method('cacheKeyWritten')
        );
    }

	public function cacheHit(CacheHit $event)
    {
    	$key = $event->key;
    	$tags = $event->tags;
		Log::debug('cache hit', compact('key', 'tags'));
    }

	public function cacheMissed(CacheMissed $event)
	{
    	$key = $event->key;
    	$tags = $event->tags;
		Log::debug('cache miss', compact('key', 'tags'));
	}

	public function cacheKeyForgotten(KeyForgotten $event)
	{
    	$key = $event->key;
    	$tags = $event->tags;
		Log::debug('cache key forgotten', compact('key', 'tags'));
	}

	public function cacheKeyWritten(KeyWritten $event)
	{
    	$key = $event->key;
    	$seconds = $event->seconds;
    	$tags = $event->tags;
		Log::debug('cache key written', compact('key', 'seconds', 'tags'));
	}
}
