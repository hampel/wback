<?php namespace App\Subscribers;

trait SubscribesToEvents
{
    /**
     * Register the listeners for the subscriber.
     *
     * @param  \Illuminate\Events\Dispatcher  $events
     */
    abstract public function subscribe($events);

	protected function method($method)
    {
    	return self::class . '@' . $method;
    }
}
