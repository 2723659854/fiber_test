<?php

declare(strict_types=1);

namespace Revolt\EventLoop;

use Revolt\EventLoop\Internal\ClosureHelper;

final class UncaughtThrowable extends \Error
{
    /**
     * 在事件循环回调%s中抛出未捕获的%s；使用Revolt\EventLoop:：setErrorHandler（）优雅地处理此类异常%s
     * @param \Closure $closure
     * @param \Throwable $previous
     * @return self
     */
    public static function throwingCallback(\Closure $closure, \Throwable $previous): self
    {
        return new self(
            "Uncaught %s thrown in event loop callback %s; use Revolt\EventLoop::setErrorHandler() to gracefully handle such exceptions%s",
            $closure,
            $previous
        );
    }

    /**
     * 不能捕获的错误回调
     * @param \Closure $closure
     * @param \Throwable $previous
     * @return self
     */
    public static function throwingErrorHandler(\Closure $closure, \Throwable $previous): self
    {
        return new self("Uncaught %s thrown in event loop error handler %s%s", $closure, $previous);
    }

    private function __construct(string $message, \Closure $closure, \Throwable $previous)
    {
        parent::__construct(\sprintf(
            $message,
            \str_replace("\0", '@', \get_class($previous)), // replace NUL-byte in anonymous class name
            ClosureHelper::getDescription($closure),
            $previous->getMessage() !== '' ? ': ' . $previous->getMessage() : ''
        ), 0, $previous);
    }
}
