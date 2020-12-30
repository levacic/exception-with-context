# Exception with context

![Packagist PHP Version Support](https://img.shields.io/packagist/php-v/levacic/exception-with-context)
![Packagist Version](https://img.shields.io/packagist/v/levacic/exception-with-context)
![Packagist Downloads](https://img.shields.io/packagist/dt/levacic/exception-with-context)
![Packagist License](https://img.shields.io/packagist/l/levacic/exception-with-context)

This minimal package provides a single `ExceptionWithContext` interface which can be implemented in client-side code so your exception objects can carry their own context.

For more information on exactly how and why you would want to do this, read the "Usage" section.

## Requirements

- PHP >= 7.0

## Installation

```sh
composer require levacic/exception-with-context
```

## Usage

### Why?

The "why" obviously depends on how you use exceptions in general, but this is specifically useful when logging exceptions, in order to have some additional information on why the exception was thrown. In general, if you're logging in PHP in 2020, you're more than likely using a logger compatible with PSR-3 - and these loggers allow you to log additional context data, along with the actual log message.

This package, along with some manual or automated wiring (as described further below) allows exceptions to carry their own context, so your logging logic can be cleaner while being more informative at the same time.

### Example implementation

When using this package, you would usually implement an exception class something like this:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use Levacic\Exceptions\ExceptionWithContext;
use RuntimeException;
use Throwable;

class UserNotActivated extends RuntimeException implements ExceptionWithContext
{
    /**
     * The ID of the non-activated user.
     */
    private int $userId;

    /**
     * @param int            $userId   The ID of the non-activated user.
     * @param Throwable|null $previous The previous exception.
     * @param int            $code     The internal exception code.
     */
    public function __construct(int $userId, ?Throwable $previous = null, int $code = 0)
    {
        parent::__construct('The user has not been activated yet.', $code, $previous);

        $this->userId = $userId;
    }

    /**
     * @inheritDoc
     */
    public function getContext(): array
    {
        return [
            'userId' => $this->userId,
        ];
    }
}
```

> **Note:** My preferred convention is to not use the `Exception` suffix on exception classes, even though it's common to do so in the PHP community. The reason is that I don't personally find it provides any useful information - exception classes are often located in some kind of an `Exceptions` namespace, and when you're working with them in code, you're usually `throw`ing or `catch`ing them - making it pretty obvious what they are.

That's it. The idea is simply to pass additional context info - in this contrived example, a user ID - to the exception, so that it can return it in the `getContext()` method implementation.

How do you pass the context to an exception? It doesn't matter!

This example implementation uses a constructor to achieve that, so you would throw it maybe something like this:

```php
if (!$user->isActivated()) {
    throw new UserNotActivated($user->id);
}
```

You could also use a setter method, or even a public property (although you probably don't want these if you prefer immutability in your code).

And all of these approaches work even if you prefer using exception factories (either as separate classes, or static creator methods on the exception class itself).

The only important aspects are:

- The exception object carries some additional context info
- This additional context info is exposed through a `getContext()` method which returns an array of information

This just made it trivial to log this context along with the exception, no matter where/how you handle your logging.

### How to log

Logging is a complex subject and depends a lot on your approach to logging in general (e.g. what stuff you want to log, when/why, where you are logging _to_, etc.), as well as your app's (or framework's) logging architecture - so this section will be a little generic.

So how _do_ you log this information?

Assuming you have a PSR-3 logger instance, you can do something like this:

```php
$logger->error($exception->getMessage(), $exception->getContext());
```

Of course, the logger needs to be configured with a handler and formatter that are able to process and output the context, wherever it is you're logging to. As far as I know, most common default setups already handle this, so most of the time, this should just work as-is.

So where do you do this? Wherever you want!

A default place where you would definitely _want_ to do this is your app's top-level exception handler.

Another useful location where you can do this is any `catch` block within which you might already have a way to handle the situation, but still want to log that this exception happened, for debugging or general logging purposes.

E.g. if your app has some service class which is interacting with an external API, you might have something like this:

```php
$response = makeRequestToExternalApi($someRequestData);

if ($response->statusCode !== 200) {
    throw new InvalidResponseFromExternalApi(
        $someRequestData,
        $response->statusCode,
        $response->body,
        $response->headers,
    );
}

return $response;
```

The `InvalidResponseFromExternalApi` exception's constructor would accept these arguments, store them, and then format them nicely in the `getContext()` method.

Some higher-level code, e.g. a controller which sits in the app's HTTP layer, might in turn do something like this:

```php
try {
    $response = $apiService->getResponse();
} catch (InvalidResponseFromExternalApi $exception) {
    $logger->error($exception->getMessage(), $exception->getContext());

    return new \Symfony\Component\HttpFoundation\Response('External API is currently unavailable.', 503);
}

return $response;
```

(Or you can rethrow as a `ServiceUnavailableHttpException` or whatever makes sense in the context of your app's architecture).

With this, you would basically return a useful message to the client, while still logging internally that an error occurred - and this log message would include useful context information.

#### Laravel

If you're using a somewhat recent Laravel version, you can override [`Illuminate\Foundation\Exceptions\Handler::exceptionContext()`](https://github.com/laravel/framework/blob/2c9c12c5e41b3f13bcf9d7374417c0d211ae4dd9/src/Illuminate/Foundation/Exceptions/Handler.php#L269-L278) in your `App\Exceptions\Handler`, which extends Laravel's class in a default Laravel setup:

```php
protected function exceptionContext(Throwable $e)
{
    if ($e instanceof ExceptionWithContext) {
        return $e->getContext();
    }

    return parent::exceptionContext($e);
}
```

This is, in turn, called automatically when Laravel [logs an error](https://github.com/laravel/framework/blob/2c9c12c5e41b3f13bcf9d7374417c0d211ae4dd9/src/Illuminate/Foundation/Exceptions/Handler.php#L233-L240).

Thus, any exceptions with context that are uncaught within application code will get logged along with the context they're carrying.

However, this will only work when the caught exception is the one carrying context - but any chained exceptions with context would have their context ignored. A better solution can be achieved with a custom Monolog processor, in case, of course, you're using Monolog - which is highly likely.

#### Custom Monolog processor

You can attach a processor to Monolog which checks if an `exception` key in the log record's context is set, and if it is, whether it is a `Throwable`, and then do some custom logic in that case.

To achieve what we want, we would basically just traverse the exception chain (via `$exception->getPrevious()`), and for each of the exceptions in the chain, check whether it implements `ExceptionWithContext` and then extract that data.

In fact, I'm in the process of creating a package with a processor that does exactly this - which I'll link to here once it has been published.

## License

This package is open-source software licensed under the [MIT license][LICENSE].
