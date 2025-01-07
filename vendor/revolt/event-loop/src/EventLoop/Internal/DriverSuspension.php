<?php

/** @noinspection PhpPropertyOnlyWrittenInspection */

declare(strict_types=1);

namespace Revolt\EventLoop\Internal;

use Revolt\EventLoop\Suspension;

/**
 * 驱动挂起
 * @internal
 *
 * @template T
 * @implements Suspension<T>
 */
final class DriverSuspension implements Suspension
{
    private ?\Fiber $suspendedFiber = null;

    /** @var \WeakReference<\Fiber>|null */
    private readonly ?\WeakReference $fiberRef;

    private ?\Error $error = null;

    private bool $pending = false;

    private bool $deadMain = false;

    /**
     * @param \WeakMap<object, \WeakReference<DriverSuspension>> $suspensions
     */
    public function __construct(
        private readonly \Closure $run,
        private readonly \Closure $queue,
        private readonly \Closure $interrupt,
        private readonly \WeakMap $suspensions,
    ) {
        $fiber = \Fiber::getCurrent();

        $this->fiberRef = $fiber ? \WeakReference::create($fiber) : null;
    }

    /**
     * 唤起协程
     * @param mixed|null $value
     * @return void
     */
    public function resume(mixed $value = null): void
    {
        /** 忽略已经死亡的协程 */
        // Ignore spurious resumes to old dead {main} suspension
        if ($this->deadMain) {
            return;
        }
        /** 只有被挂起的协程才可以被唤醒 */
        if (!$this->pending) {
            throw $this->error ?? new \Error('Must call suspend() before calling resume()');
        }

        $this->pending = false;

        /** @var \Fiber|null $fiber */
        $fiber = $this->fiberRef?->get();
        /** 如果是协程，并且没有被停止，则唤醒 */
        if ($fiber) {
            ($this->queue)(static function () use ($fiber, $value): void {
                // The fiber may be destroyed with suspension as part of the GC cycle collector.
                if (!$fiber->isTerminated()) {
                    $fiber->resume($value);
                }
            });
        } else {
            /** 将回调函数投递到主协程，挂起 */
            // Suspend event loop fiber to {main}.
            ($this->interrupt)(static fn () => $value);
        }
    }

    /**
     * 挂起协程
     * @return mixed
     * @throws \Throwable
     */
    public function suspend(): mixed
    {
        /** 忽略已死亡的协程 */
        // Throw exception when trying to use old dead {main} suspension
        if ($this->deadMain) {
            throw new \Error(
                'Suspension cannot be suspended after an uncaught exception is thrown from the event loop',
            );
        }
        /** 只有被唤醒或者异常的协程才可以被挂起 */
        if ($this->pending) {
            throw new \Error('Must call resume() or throw() before calling suspend() again');
        }

        $fiber = $this->fiberRef?->get();
        /** 只有当前协程才可以被挂起，不能挂起其他协程 */
        if ($fiber !== \Fiber::getCurrent()) {
            throw new \Error('Must not call suspend() from another fiber');
        }

        $this->pending = true;
        $this->error = null;
        /** 将当前协程挂起 */
        // Awaiting from within a fiber.
        if ($fiber) {
            $this->suspendedFiber = $fiber;

            try {
                $value = \Fiber::suspend();
                $this->suspendedFiber = null;
            } catch (\FiberError $error) {
                /** 挂起当前协程失败 */
                $this->pending = false;
                $this->suspendedFiber = null;
                $this->error = $error;

                throw $error;
            }

            // Setting $this->suspendedFiber = null in finally will set the fiber to null if a fiber is destroyed
            // as part of a cycle collection, causing an error if the suspension is subsequently resumed.

            return $value;
        }
        /** 执行主任务 */
        // Awaiting from {main}.
        $result = ($this->run)();
        /** 如果当前协程被挂起 */
        /** @psalm-suppress RedundantCondition $this->pending should be changed when resumed. */
        if ($this->pending) {
            /** 标记当前协程已死 */
            // This is now a dead {main} suspension.
            $this->deadMain = true;
            /** 删除当前队列中的任务 */
            // Unset suspension for {main} using queue closure.
            unset($this->suspensions[$this->queue]);
            /** 从事件循环中解开任何未捕获的异常 */
            $result && $result(); // Unwrap any uncaught exceptions from the event loop
            /** gc_collect_cycles函数用于强制启动一个垃圾回收周期 ，强制回收gc垃圾，防止内存泄漏 */
            \gc_collect_cycles(); // Collect any circular references before dumping pending suspensions.

            $info = '';
            /** 遍历所有被引用的协程 */
            foreach ($this->suspensions as $suspensionRef) {
                if ($suspension = $suspensionRef->get()) {
                    \assert($suspension instanceof self);
                    $fiber = $suspension->fiberRef?->get();
                    if ($fiber === null) {
                        continue;
                    }
                    /** 通过反射类获取协程 */
                    $reflectionFiber = new \ReflectionFiber($fiber);
                    /** 回去追踪信息 */
                    $info .= "\n\n" . $this->formatStacktrace($reflectionFiber->getTrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
                }
            }
            /** 事件循环终止，但未恢复当前暂停（原因可能是光纤死锁，或未被引用/取消的观察器不正确） */
            throw new \Error('Event loop terminated without resuming the current suspension (the cause is either a fiber deadlock, or an incorrectly unreferenced/canceled watcher):' . $info);
        }
        /** 返回处理结果 */
        return $result();
    }

    /**
     * 抛出异常
     * @param \Throwable $throwable
     * @return void
     */
    public function throw(\Throwable $throwable): void
    {
        /** 协程已死，忽略 */
        // Ignore spurious resumes to old dead {main} suspension
        if ($this->deadMain) {
            return;
        }
        /** 必须挂起协程才可以抛出异常 */
        if (!$this->pending) {
            throw $this->error ?? new \Error('Must call suspend() before calling throw()');
        }

        $this->pending = false;

        /** @var \Fiber|null $fiber */
        $fiber = $this->fiberRef?->get();
        /** 如果是协程，则使用协程抛出异常 ，否则使用主协程抛出异常 */
        if ($fiber) {
            ($this->queue)(static function () use ($fiber, $throwable): void {
                // The fiber may be destroyed with suspension as part of the GC cycle collector.
                if (!$fiber->isTerminated()) {
                    $fiber->throw($throwable);
                }
            });
        } else {
            // Suspend event loop fiber to {main}.
            ($this->interrupt)(static fn () => throw $throwable);
        }
    }

    /**
     * 格式化追踪信息
     * @param array $trace
     * @return string
     */
    private function formatStacktrace(array $trace): string
    {
        return \implode("\n", \array_map(static function ($e, $i) {
            $line = "#{$i} ";

            if (isset($e["file"])) {
                $line .= "{$e['file']}:{$e['line']} ";
            }

            if (isset($e["class"], $e["type"])) {
                $line .= $e["class"] . $e["type"];
            }

            return $line . $e["function"] . "()";
        }, $trace, \array_keys($trace)));
    }
}
