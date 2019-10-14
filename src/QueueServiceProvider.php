<?php

namespace MichaelLedin\LaravelQueueRateLimit;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Log\LogManager;

class QueueServiceProvider extends \Illuminate\Queue\QueueServiceProvider
{
    protected function registerWorker()
    {
        $this->app->singleton('queue.worker', function () {
            $isDownForMaintenance = function () {
                return $this->app->isDownForMaintenance();
            };

            return new Worker(
                $this->app['queue'],
                $this->app['events'],
                $this->app[ExceptionHandler::class],
                $isDownForMaintenance,
                $this->app['config']->get('queue.rateLimits'),
                $this->app[RateLimiter::class],
                $this->app[LogManager::class]
            );
        });
    }
}
