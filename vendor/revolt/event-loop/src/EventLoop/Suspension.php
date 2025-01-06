<?php

declare(strict_types=1);

namespace Revolt\EventLoop;

/**
 * 应用于运行和暂停事件循环，而不是直接与协程交互
 * Should be used to run and suspend the event loop instead of directly interacting with fibers.
 *
 * **Example**
 *
 * ```php
 * $suspension = EventLoop::getSuspension();
 *
 * $promise->then(
 *     fn (mixed $value) => $suspension->resume($value),
 *     fn (Throwable $error) => $suspension->throw($error)
 * );
 *
 * $suspension->suspend();
 * ```
 *
 * @template T
 */
interface Suspension
{
    /**
     * 协程复位
     * @param T $value The value to return from the call to {@see suspend()}.
     */
    public function resume(mixed $value = null): void;

    /**
     * 协程挂起
     * Returns the value provided to {@see resume()} or throws the exception provided to {@see throw()}.
     *
     * @return T
     */
    public function suspend(): mixed;

    /**
     * Throws the given exception from the call to {@see suspend()}.
     */
    public function throw(\Throwable $throwable): void;
}
