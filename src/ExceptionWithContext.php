<?php

declare(strict_types=1);

namespace Levacic\Exceptions;

use Throwable;

interface ExceptionWithContext extends Throwable
{
    /**
     * Get the exception context.
     *
     * @return array
     */
    public function getContext(): array;
}
