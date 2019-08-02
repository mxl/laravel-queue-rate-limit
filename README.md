# laravel-queue-rate-limit
Simple Laravel queue rate limiting

## Installation
```bash
$ composer require mxl/laravel-queue-rate-limit:^1.0
```

Laravel 5.5+ will use the auto-discovery function.

If using Laravel 5.4 (or if you don't use auto-discovery) you will need to include the service provider in `config/app.php`:

```php
'providers' => [
    MichaelLedin\LaravelQueueRateLimit\QueueServiceProvider::class,
];
```

Add rate limits to `config/queue.php`:

```php
'rateLimits' => [
     'mail' => [ // queue name
        'allows' => 1, // 1 job
        'every' => 5 // per 5 seconds
     ]
]
```

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

## Maintainers

- [@mxl](https://github.com/mxl)

## License

See the [LICENSE](https://github.com/mxl/laravel-queue-rate-limit/blob/master/LICENSE) file for details.


