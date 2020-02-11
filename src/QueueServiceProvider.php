<?php

namespace MichaelLedin\LaravelQueueRateLimit;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Psr\Log\LoggerInterface;

class QueueServiceProvider extends \Illuminate\Queue\QueueServiceProvider
{
    public function register()
    {
        $this->registerLogger();
        parent::register();
    }

    protected function registerWorker()
    {
        $this->app->singleton('queue.worker', function () {
            return new Worker(
                $this->app['queue'],
                $this->app['events'],
                $this->app[ExceptionHandler::class],
                $this->app['config']->get('queue.rateLimits'),
                $this->app[RateLimiter::class],
                $this->app['queue.logger']
            );
        });
    }

    protected function registerLogger() {
        $this->app->singleton('queue.logger', function () {
            return $this->app[LoggerInterface::class];
        });
    }
}
