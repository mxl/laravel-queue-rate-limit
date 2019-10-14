<?php

namespace MichaelLedin\LaravelQueueRateLimit;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\LogManager;
use Illuminate\Queue\QueueManager;

class Worker extends \Illuminate\Queue\Worker
{
    /**
     * @var array
     */
    private $rateLimits;

    /**
     * @var RateLimiter
     */
    private $rateLimiter;

    /**
     * @var LogManager
     */
    private $logger;

    /**
     * @inheritDoc
     * @param array|null $rateLimits
     * @param RateLimiter $rateLimiter
     * @param LogManager $logger
     */
    public function __construct(QueueManager $manager,
                                Dispatcher $events,
                                ExceptionHandler $exceptions,
                                callable $isDownForMaintenance,
                                $rateLimits,
                                $rateLimiter,
                                $logger)
    {
        parent::__construct($manager, $events, $isDownForMaintenance);

        $this->rateLimits = $rateLimits ?? [];
        $this->rateLimiter = $rateLimiter;
        $this->logger = $logger;
    }

    protected function getNextJob($connection, $queue)
    {
        $job = null;
        foreach (explode(',', $queue) as $queue) {
            $rateLimit = $this->rateLimits[$queue] ?? null;
            if ($rateLimit) {
                if (!isset($rateLimit['allows']) || !isset($rateLimit['every'])) {
                    throw new \RuntimeException('Set "allows" and "every" fields for "' . $queue . '" rate limit.');
                }
                $this->log('Rate limit is set for queue ' . $queue);
                if ($this->rateLimiter->tooManyAttempts($queue, $rateLimit['allows'])) {
                    $availableIn = $this->rateLimiter->availableIn($queue);
                    $this->log('Rate limit is reached for queue ' . $queue . '. Next job will be started in ' . $availableIn . ' seconds');
                    continue;
                } else {
                    $this->log('Rate limit check is passed for queue ' . $queue);
                }
            } else {
                $this->log('No rate limit is set for queue ' . $queue . '.');
            }

            $job = parent::getNextJob($connection, $queue);
            if ($job) {
                if ($rateLimit) {
                    $this->rateLimiter->hit($queue, $rateLimit['every']);
                }
                $this->log('Running job ' . $job->getJobId() . ' on queue ' . $queue);
                break;
            } else {
                $this->log('No available jobs on queue ' . $queue);
            }
        }
        return $job;
    }


    private function log(string $message)
    {
        if ($this->logger) {
            $this->logger->debug($message);
        }
    }
}
