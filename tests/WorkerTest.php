<?php

namespace MichaelLedin\LaravelQueueRateLimit\Tests;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\QueueManager;
use MichaelLedin\LaravelQueueRateLimit\Worker;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WorkerTest extends TestCase
{
    public function testPopsUnratedQueueWithoutHittingRateLimiter()
    {
        $job = new FakeJob('default-job');
        $connection = new FakeQueue(['default' => [$job]]);
        $rateLimiter = new FakeRateLimiter();
        $worker = $this->worker([], $rateLimiter);

        $this->assertSame($job, $worker->nextJob($connection, 'default'));
        $this->assertSame(['default'], $connection->popCalls);
        $this->assertSame([], $rateLimiter->hits);
    }

    public function testRatedQueueHitsRateLimiterAfterPoppingJob()
    {
        $job = new FakeJob('mail-job');
        $connection = new FakeQueue(['mail' => [$job]]);
        $rateLimiter = new FakeRateLimiter(['mail' => [false]]);
        $worker = $this->worker(['mail' => ['allows' => 1, 'every' => 5]], $rateLimiter);

        $this->assertSame($job, $worker->nextJob($connection, 'mail'));
        $this->assertSame([['mail', 1]], $rateLimiter->tooManyAttemptsCalls);
        $this->assertSame([['mail', 5]], $rateLimiter->hits);
    }

    public function testRateLimitedEmptyQueueStillReturnsNull()
    {
        $connection = new FakeQueue(['mail' => []]);
        $rateLimiter = new FakeRateLimiter(['mail' => [true]], ['mail' => 5]);
        $worker = $this->worker(['mail' => ['allows' => 1, 'every' => 5]], $rateLimiter);

        $this->assertNull($worker->nextJob($connection, 'mail'));
        $this->assertSame([], $connection->popCalls);
        $this->assertSame([], $worker->sleepCalls);
    }

    public function testRateLimitedQueueWithPendingJobsWaitsAndRetries()
    {
        $job = new FakeJob('mail-job');
        $connection = new FakeQueue(['mail' => [$job]]);
        $rateLimiter = new FakeRateLimiter(['mail' => [true, false]], ['mail' => 5]);
        $worker = $this->worker(['mail' => ['allows' => 1, 'every' => 5]], $rateLimiter);

        $this->assertSame($job, $worker->nextJob($connection, 'mail'));
        $this->assertSame([5], $worker->sleepCalls);
        $this->assertSame(['mail'], $connection->popCalls);
        $this->assertSame([['mail', 5]], $rateLimiter->hits);
    }

    public function testLaterQueuesAreCheckedBeforeWaitingForRateLimitedQueue()
    {
        $mailJob = new FakeJob('mail-job');
        $defaultJob = new FakeJob('default-job');
        $connection = new FakeQueue(['mail' => [$mailJob], 'default' => [$defaultJob]]);
        $rateLimiter = new FakeRateLimiter(['mail' => [true]], ['mail' => 5]);
        $worker = $this->worker(['mail' => ['allows' => 1, 'every' => 5]], $rateLimiter);

        $this->assertSame($defaultJob, $worker->nextJob($connection, 'mail,default'));
        $this->assertSame([], $worker->sleepCalls);
        $this->assertSame(['default'], $connection->popCalls);
    }

    public function testShortestRateLimitDelayIsUsedWhenAllPendingQueuesAreLimited()
    {
        $smsJob = new FakeJob('sms-job');
        $connection = new FakeQueue(['mail' => [new FakeJob('mail-job')], 'sms' => [$smsJob]]);
        $rateLimiter = new FakeRateLimiter(
            ['mail' => [true, true], 'sms' => [true, false]],
            ['mail' => 5, 'sms' => 2]
        );
        $worker = $this->worker([
            'mail' => ['allows' => 1, 'every' => 5],
            'sms' => ['allows' => 1, 'every' => 3],
        ], $rateLimiter);

        $this->assertSame($smsJob, $worker->nextJob($connection, 'mail,sms'));
        $this->assertSame([2], $worker->sleepCalls);
        $this->assertSame(['sms'], $connection->popCalls);
        $this->assertSame([['sms', 3]], $rateLimiter->hits);
    }

    private function worker(array $rateLimits, FakeRateLimiter $rateLimiter)
    {
        return new TestWorker(
            $this->createMock(QueueManager::class),
            $this->createMock(Dispatcher::class),
            $this->createMock(ExceptionHandler::class),
            function () {
                return false;
            },
            $rateLimits,
            $rateLimiter,
            $this->createMock(LoggerInterface::class)
        );
    }
}

class TestWorker extends Worker
{
    public $sleepCalls = [];

    public function nextJob($connection, $queue)
    {
        return $this->getNextJob($connection, $queue);
    }

    public function sleep($seconds)
    {
        $this->sleepCalls[] = $seconds;
    }
}

class FakeRateLimiter extends RateLimiter
{
    public $hits = [];
    public $tooManyAttemptsCalls = [];

    private $tooManyAttempts;
    private $availableIn;

    public function __construct(array $tooManyAttempts = [], array $availableIn = [])
    {
        $this->tooManyAttempts = $tooManyAttempts;
        $this->availableIn = $availableIn;
    }

    public function tooManyAttempts($key, $maxAttempts)
    {
        $this->tooManyAttemptsCalls[] = [$key, $maxAttempts];

        if (! array_key_exists($key, $this->tooManyAttempts)) {
            return false;
        }

        if (count($this->tooManyAttempts[$key]) > 1) {
            return array_shift($this->tooManyAttempts[$key]);
        }

        return $this->tooManyAttempts[$key][0];
    }

    public function availableIn($key)
    {
        return $this->availableIn[$key] ?? 0;
    }

    public function hit($key, $decaySeconds = 60)
    {
        $this->hits[] = [$key, $decaySeconds];

        return count($this->hits);
    }
}

class FakeQueue implements Queue
{
    public $popCalls = [];

    private $jobs;
    private $connectionName = 'testing';

    public function __construct(array $jobs = [])
    {
        $this->jobs = $jobs;
    }

    public function size($queue = null)
    {
        return count($this->jobs[$queue] ?? []);
    }

    public function pop($queue = null)
    {
        $this->popCalls[] = $queue;

        if (empty($this->jobs[$queue])) {
            return null;
        }

        return array_shift($this->jobs[$queue]);
    }

    public function push($job, $data = '', $queue = null)
    {
        return null;
    }

    public function pushOn($queue, $job, $data = '')
    {
        return null;
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        return null;
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        return null;
    }

    public function laterOn($queue, $delay, $job, $data = '')
    {
        return null;
    }

    public function bulk($jobs, $data = '', $queue = null)
    {
        return null;
    }

    public function getConnectionName()
    {
        return $this->connectionName;
    }

    public function setConnectionName($name)
    {
        $this->connectionName = $name;

        return $this;
    }
}

class FakeJob implements Job
{
    private $id;

    public function __construct($id)
    {
        $this->id = $id;
    }

    public function uuid()
    {
        return null;
    }

    public function getJobId()
    {
        return $this->id;
    }

    public function payload()
    {
        return [];
    }

    public function fire()
    {
    }

    public function release($delay = 0)
    {
    }

    public function isReleased()
    {
        return false;
    }

    public function delete()
    {
    }

    public function isDeleted()
    {
        return false;
    }

    public function isDeletedOrReleased()
    {
        return false;
    }

    public function attempts()
    {
        return 1;
    }

    public function hasFailed()
    {
        return false;
    }

    public function markAsFailed()
    {
    }

    public function fail($e = null)
    {
    }

    public function maxTries()
    {
        return null;
    }

    public function maxExceptions()
    {
        return null;
    }

    public function timeout()
    {
        return null;
    }

    public function timeoutAt()
    {
        return null;
    }

    public function retryUntil()
    {
        return null;
    }

    public function getName()
    {
        return self::class;
    }

    public function resolveName()
    {
        return self::class;
    }

    public function resolveQueuedJobClass()
    {
        return self::class;
    }

    public function getConnectionName()
    {
        return 'testing';
    }

    public function getQueue()
    {
        return 'default';
    }

    public function getRawBody()
    {
        return '';
    }
}
