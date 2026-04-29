# laravel-queue-rate-limit
[![Current version](https://img.shields.io/packagist/v/mxl/laravel-queue-rate-limit.svg?logo=composer)](https://packagist.org/packages/mxl/laravel-queue-rate-limit)
[![Monthly Downloads](https://img.shields.io/packagist/dm/mxl/laravel-queue-rate-limit.svg)](https://packagist.org/packages/mxl/laravel-queue-rate-limit/stats)
[![Total Downloads](https://img.shields.io/packagist/dt/mxl/laravel-queue-rate-limit.svg)](https://packagist.org/packages/mxl/laravel-queue-rate-limit/stats)
[![Build Status](https://travis-ci.org/mxl/laravel-queue-rate-limit.svg?branch=master)](https://travis-ci.org/mxl/laravel-queue-rate-limit)

Simple Laravel queue rate limiting

## Installation

3.* versions are compatible only with Laravel 7+.

```bash
$ composer require mxl/laravel-queue-rate-limit
```

For Laravel 6 use 2.* versions:

```bash
$ composer require mxl/laravel-queue-rate-limit "^2.0"
```

For Laravel 5 use 1.* versions:

```bash
$ composer require mxl/laravel-queue-rate-limit "^1.0"
```

Laravel 5.5+ will use the [auto-discovery](https://medium.com/@taylorotwell/package-auto-discovery-in-laravel-5-5-ea9e3ab20518) feature to add `MichaelLedin\LaravelQueueRateLimit\QueueServiceProvider::class` to providers.

This package is not compatible with older Laravel versions.

Add rate limits to `config/queue.php`:

```php
'rateLimits' => [
    'mail' => [ // queue name, not connection name
        'allows' => 1, // 1 job
        'every' => 5 // per 5 seconds
    ]
]
```

The `rateLimits` array keys must match the queue names your worker processes. For example, if your database connection uses `queue.connections.database.queue` set to `payments`, configure `rateLimits.payments` and run the worker for that queue:

```php
// config/queue.php
'connections' => [
    'database' => [
        'driver' => 'database',
        'queue' => 'payments',
        // ...
    ],
],

'rateLimits' => [
    'payments' => [
        'allows' => 1,
        'every' => 60,
    ],
],
```

```bash
$ php artisan queue:work database --queue payments
```

If you dispatch jobs to a non-default queue, make sure the job uses the same queue name:

```php
PaymentJob::dispatch($details)->onQueue('payments');
```

This package rate-limits when workers pop jobs from queues. It does not delay jobs when they are dispatched and does not change `available_at` in the `jobs` database table. For database queues, seeing `available_at` equal to `created_at` is expected unless you dispatch the job with Laravel's own delay API.

## Usage

Make sure that you don't use `sync` connection when queueing jobs. See `default` property in `config/queue.php`.

Run queue worker:

```bash
$ php artisan queue:work --queue default,mail
```

Then push several jobs to `default` and `mail` queues:

```php
Mail::queue(..., 'mail');
Mail::queue(..., 'mail');
Mail::queue(..., 'mail');
Mail::queue(..., 'default');
Mail::queue(..., 'default');
```

You'll see that only `mail` queue jobs will be rate limited while `default` queue jobs will run normally.

## Disable logging

Extend `QueueServiceProvider`:

```php
<?php

namespace App\Providers;

class QueueServiceProvider extends \MichaelLedin\LaravelQueueRateLimit\QueueServiceProvider
{
    protected function registerLogger()
    {
        $this->app->singleton('queue.logger', function () {
            return null;
        });
    }
}
```

Add it to `providers` array in `config/app.php`:

```php
<?php

return [
    // ...
    'providers' => [
        // Laravel Framework Service Providers
        // ...
        // Application Service Providers
        // ...
        App\Providers\QueueServiceProvider::class,
        // ...
    ]
];
```

## Maintainers

- [@mxl](https://github.com/mxl)

## Other useful Laravel packages from the author

- [mxl/laravel-api-key](https://github.com/mxl/laravel-api-key) - API Key Authorization for Laravel with replay attack prevention;
- [mxl/laravel-job](https://github.com/mxl/laravel-job) - dispatch a job from command line and more;

## License

See the [LICENSE](https://github.com/mxl/laravel-queue-rate-limit/blob/master/LICENSE) file for details.
