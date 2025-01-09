<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Internal;

use Revolt\EventLoop\CallbackType;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\FiberLocal;
use Revolt\EventLoop\InvalidCallbackError;
use Revolt\EventLoop\Suspension;
use Revolt\EventLoop\UncaughtThrowable;

/**
 * Event loop driver which implements all basic operations to allow interoperability.
 *
 * Callbacks (enabled or new callbacks) MUST immediately be marked as enabled, but only be activated (i.e. callbacks can
 * be called) right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
 *
 * All registered callbacks MUST NOT be called from a file with strict types enabled (`declare(strict_types=1)`).
 *
 * @internal
 */
abstract class AbstractDriver implements Driver
{
    /** @var string Next callback identifier. */
    private string $nextId = "a";

    private \Fiber $fiber;

    private \Fiber $callbackFiber;
    private \Closure $errorCallback;

    /** @var array<string, DriverCallback> */
    private array $callbacks = [];

    /** @var array<string, DriverCallback> */
    private array $enableQueue = [];

    /** @var array<string, DriverCallback> */
    private array $enableDeferQueue = [];

    /** @var null|\Closure(\Throwable):void */
    private ?\Closure $errorHandler = null;

    /** @var null|\Closure():mixed */
    private ?\Closure $interrupt = null;

    private readonly \Closure $interruptCallback;
    private readonly \Closure $queueCallback;
    private readonly \Closure $runCallback;

    private readonly \stdClass $internalSuspensionMarker;

    /** @var \SplQueue<array{\Closure, array}> */
    private readonly \SplQueue $microtaskQueue;

    /** @var \SplQueue<DriverCallback> */
    private readonly \SplQueue $callbackQueue;

    private bool $idle = false;
    private bool $stopped = false;

    /** @var \WeakMap<object, \WeakReference<DriverSuspension>> */
    private \WeakMap $suspensions;

    public function __construct()
    {
        if (\PHP_VERSION_ID < 80117 || \PHP_VERSION_ID >= 80200 && \PHP_VERSION_ID < 80204) {
            // PHP GC is broken on early 8.1 and 8.2 versions, see https://github.com/php/php-src/issues/10496
            if (!\getenv('REVOLT_DRIVER_SUPPRESS_ISSUE_10496')) {
                throw new \Error('Your version of PHP is affected by serious garbage collector bugs related to fibers. Please upgrade to a newer version of PHP, i.e. >= 8.1.17 or => 8.2.4');
            }
        }
        /** 构建弱映射表 */
        $this->suspensions = new \WeakMap();
        /** 断点 */
        $this->internalSuspensionMarker = new \stdClass();
        /** 构建微服务队列 */
        $this->microtaskQueue = new \SplQueue();
        /** 构建回调函数队列 */
        $this->callbackQueue = new \SplQueue();
        /** 创建轮训协程 */
        $this->createLoopFiber();
        /** 创建回调函数协程 */
        $this->createCallbackFiber();
        /** 创建异常处理回调 */
        $this->createErrorCallback();
        /** 打断协程 */
        /** @psalm-suppress InvalidArgument */
        $this->interruptCallback = $this->setInterrupt(...);
        /** 挂载队列 */
        $this->queueCallback = $this->queue(...);
        /** 运行回调 */
        $this->runCallback = function () {
            /** 如果协程运行已中断 ，则创建新的轮训协程 */
            if ($this->fiber->isTerminated()) {
                $this->createLoopFiber();
            }
            /** 启动协程 ，那么是启动所有的协程吗 */
            return $this->fiber->isStarted() ? $this->fiber->resume() : $this->fiber->start();
        };
    }

    /**
     * 启动
     * @return void
     * @throws \Throwable
     */
    public function run(): void
    {
        if ($this->fiber->isRunning()) {
            throw new \Error("The event loop is already running");
        }

        /** 不能在携程中运行协程 */
        if (\Fiber::getCurrent()) {
            throw new \Error(\sprintf("Can't call %s() within a fiber (i.e., outside of {main})", __METHOD__));
        }
        /** 如果协程已经终止 */
        if ($this->fiber->isTerminated()) {
            /** 创建轮训协程 */
            $this->createLoopFiber();
        }
        /** 如果已启动，则唤醒协程，否则启动协程 */
        /** @noinspection PhpUnhandledExceptionInspection */
        $lambda = $this->fiber->isStarted() ? $this->fiber->resume() : $this->fiber->start();

        if ($lambda) {
            $lambda();
            /** TODO 如果有返回值，则抛出异常 */
            throw new \Error('Interrupt from event loop must throw an exception: ' . ClosureHelper::getDescription($lambda));
        }
    }

    /**
     * 停止任务
     * @return void
     */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /**
     * 判断协程是否在运行
     * @return bool
     */
    public function isRunning(): bool
    {
        /** 协程是否在运行中或者挂起 */
        return $this->fiber->isRunning() || $this->fiber->isSuspended();
    }

    /**
     * 投递到队列
     * @param \Closure $closure
     * @param mixed ...$args
     * @return void
     */
    public function queue(\Closure $closure, mixed ...$args): void
    {
        $this->microtaskQueue->enqueue([$closure, $args]);
    }

    /**
     * 延时回调
     * @param \Closure $closure
     * @return string
     */
    public function defer(\Closure $closure): string
    {
        $deferCallback = new DeferCallback($this->nextId++, $closure);

        $this->callbacks[$deferCallback->id] = $deferCallback;
        $this->enableDeferQueue[$deferCallback->id] = $deferCallback;

        return $deferCallback->id;
    }

    /**
     * 延迟任务
     * @param float $delay
     * @param \Closure $closure
     * @return string
     */
    public function delay(float $delay, \Closure $closure): string
    {
        if ($delay < 0) {
            throw new \Error("Delay must be greater than or equal to zero");
        }

        $timerCallback = new TimerCallback($this->nextId++, $delay, $closure, $this->now() + $delay);

        $this->callbacks[$timerCallback->id] = $timerCallback;
        $this->enableQueue[$timerCallback->id] = $timerCallback;

        return $timerCallback->id;
    }

    /**
     * 重复执行定时任务
     * @param float $interval
     * @param \Closure $closure
     * @return string
     */
    public function repeat(float $interval, \Closure $closure): string
    {
        if ($interval < 0) {
            throw new \Error("Interval must be greater than or equal to zero");
        }

        /** 计算下一次执行时间 */
        $timerCallback = new TimerCallback($this->nextId++, $interval, $closure, $this->now() + $interval, true);
        /** 投递到队列中 */
        $this->callbacks[$timerCallback->id] = $timerCallback;
        $this->enableQueue[$timerCallback->id] = $timerCallback;

        return $timerCallback->id;
    }

    /**
     * 可读事件回调函数
     * @param mixed $stream
     * @param \Closure $closure
     * @return string
     */
    public function onReadable(mixed $stream, \Closure $closure): string
    {
        $streamCallback = new StreamReadableCallback($this->nextId++, $closure, $stream);
        /** 投递到队列中 */
        $this->callbacks[$streamCallback->id] = $streamCallback;
        $this->enableQueue[$streamCallback->id] = $streamCallback;

        return $streamCallback->id;
    }

    /**
     * 可读事件回调函数
     * @param $stream
     * @param \Closure $closure
     * @return string
     */
    public function onWritable($stream, \Closure $closure): string
    {
        $streamCallback = new StreamWritableCallback($this->nextId++, $closure, $stream);
        /** 投递到队列 */
        $this->callbacks[$streamCallback->id] = $streamCallback;
        $this->enableQueue[$streamCallback->id] = $streamCallback;

        return $streamCallback->id;
    }

    /**
     * 信号处理回调函数
     * @param int $signal
     * @param \Closure $closure
     * @return string
     */
    public function onSignal(int $signal, \Closure $closure): string
    {
        $signalCallback = new SignalCallback($this->nextId++, $closure, $signal);
        /** 将回调函数投递到队列中 */
        $this->callbacks[$signalCallback->id] = $signalCallback;
        $this->enableQueue[$signalCallback->id] = $signalCallback;

        return $signalCallback->id;
    }

    /**
     * 启用回调函数
     * @param string $callbackId
     * @return string
     */
    public function enable(string $callbackId): string
    {
        /** 回调函数不存在 */
        if (!isset($this->callbacks[$callbackId])) {
            throw InvalidCallbackError::invalidIdentifier($callbackId);
        }

        $callback = $this->callbacks[$callbackId];
        /** 已启用 */
        if ($callback->enabled) {
            return $callbackId; // Callback already enabled.
        }

        $callback->enabled = true;

        if ($callback instanceof DeferCallback) {
            /** 将回调函数投递到延迟队列 */
            $this->enableDeferQueue[$callback->id] = $callback;
        } elseif ($callback instanceof TimerCallback) {
            /** 将回调函数投递到定时任务队列 */
            $callback->expiration = $this->now() + $callback->interval;
            $this->enableQueue[$callback->id] = $callback;
        } else {
            /** 普通的回调函数 */
            $this->enableQueue[$callback->id] = $callback;
        }

        return $callbackId;
    }

    /**
     * 取消回调函数
     * @param string $callbackId
     * @return void
     */
    public function cancel(string $callbackId): void
    {
        $this->disable($callbackId);
        unset($this->callbacks[$callbackId]);
    }

    /**
     * 禁用函数回调
     * @param string $callbackId
     * @return string
     */
    public function disable(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            return $callbackId;
        }

        $callback = $this->callbacks[$callbackId];

        if (!$callback->enabled) {
            return $callbackId; // Callback already disabled.
        }

        $callback->enabled = false;
        $callback->invokable = false;
        $id = $callback->id;

        /** 只有队列中的回调函数可以被禁用 ，其他的都是删除 */
        if ($callback instanceof DeferCallback) {
            // Callback was only queued to be enabled.
            unset($this->enableDeferQueue[$id]);
        } elseif (isset($this->enableQueue[$id])) {
            // Callback was only queued to be enabled.
            unset($this->enableQueue[$id]);
        } else {
            /** 回调函数停止工作 */
            $this->deactivate($callback);
        }

        return $callbackId;
    }

    /**
     * 设置回调函数引用
     * @param string $callbackId
     * @return string
     */
    public function reference(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            throw InvalidCallbackError::invalidIdentifier($callbackId);
        }

        $this->callbacks[$callbackId]->referenced = true;

        return $callbackId;
    }

    /**
     * 取消回调函数引用
     * @param string $callbackId
     * @return string
     */
    public function unreference(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            return $callbackId;
        }

        $this->callbacks[$callbackId]->referenced = false;

        return $callbackId;
    }

    /**
     * 获取挂起的协程
     * @return Suspension
     */
    public function getSuspension(): Suspension
    {
        /** 获取当前协程 */
        $fiber = \Fiber::getCurrent();

        /** 通常是在循环协程外部执行，所以必须是false ，就是说挂起协程不能是主循环协程 */
        // User callbacks are always executed outside the event loop fiber, so this should always be false.
        \assert($fiber !== $this->fiber);

        /** 在{main}的情况下使用队列闭包，这可以在未捕获的异常后由DriverSuspension取消设置。 */
        // Use queue closure in case of {main}, which can be unset by DriverSuspension after an uncaught exception.
        $key = $fiber ?? $this->queueCallback;
        /** 获取挂起的协程 */
        $suspension = ($this->suspensions[$key] ?? null)?->get();
        if ($suspension) {
            return $suspension;
        }
        /** 创建一个新的协程 */
        $suspension = new DriverSuspension(
            $this->runCallback,
            $this->queueCallback,
            $this->interruptCallback,
            $this->suspensions,
        );

        $this->suspensions[$key] = \WeakReference::create($suspension);

        return $suspension;
    }

    /**
     * 设置错误处理回调函数
     * @param \Closure|null $errorHandler
     * @return void
     */
    public function setErrorHandler(?\Closure $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }

    /**
     * 获取错误处理回调函数
     * @return \Closure|null
     */
    public function getErrorHandler(): ?\Closure
    {
        return $this->errorHandler;
    }

    /**
     * 调试信息
     * @return array
     */
    public function __debugInfo(): array
    {
        // @codeCoverageIgnoreStart 这个注释表示，在测试代码覆盖率的时候，忽略以下代码。代码覆盖率：
        return \array_map(fn (DriverCallback $callback) => [
            'type' => $this->getType($callback->id),
            'enabled' => $callback->enabled,
            'referenced' => $callback->referenced,
        ], $this->callbacks);
        // @codeCoverageIgnoreEnd
    }

    /**
     * 获取所有回调函数id
     * @return array|string[]
     */
    public function getIdentifiers(): array
    {
        return \array_keys($this->callbacks);
    }

    /**
     * 获取回调函数类型
     * @param string $callbackId
     * @return CallbackType
     */
    public function getType(string $callbackId): CallbackType
    {
        $callback = $this->callbacks[$callbackId] ?? throw InvalidCallbackError::invalidIdentifier($callbackId);

        return match ($callback::class) {
            /** 延迟回调 */
            DeferCallback::class => CallbackType::Defer,
            /** 定时任务 */
            TimerCallback::class => $callback->repeat ? CallbackType::Repeat : CallbackType::Delay,
            /** 可读链接 */
            StreamReadableCallback::class => CallbackType::Readable,
            /** 可写链接 */
            StreamWritableCallback::class => CallbackType::Writable,
            /** 信号处理器 */
            SignalCallback::class => CallbackType::Signal,
        };
    }

    /**
     * 回调函数是否可用
     * @param string $callbackId
     * @return bool
     */
    public function isEnabled(string $callbackId): bool
    {
        $callback = $this->callbacks[$callbackId] ?? throw InvalidCallbackError::invalidIdentifier($callbackId);

        return $callback->enabled;
    }

    /**
     * 回调函数是否可引用
     * @param string $callbackId
     * @return bool
     */
    public function isReferenced(string $callbackId): bool
    {
        /** 回去回调函数，如果不存在，则抛出异常 */
        $callback = $this->callbacks[$callbackId] ?? throw InvalidCallbackError::invalidIdentifier($callbackId);

        return $callback->referenced;
    }

    /**
     * Activates (enables) all the given callbacks.
     */
    abstract protected function activate(array $callbacks): void;

    /**
     * Dispatches any pending read/write, timer, and signal events.
     */
    abstract protected function dispatch(bool $blocking): void;

    /**
     * Deactivates (disables) the given callback.
     */
    abstract protected function deactivate(DriverCallback $callback): void;

    /**
     * 将回调函数投递到队列
     * @param DriverCallback $callback
     * @return void
     */
    final protected function enqueueCallback(DriverCallback $callback): void
    {
        $this->callbackQueue->enqueue($callback);
        $this->idle = false;
    }

    /**
     * 执行记录异常
     * Invokes the error handler with the given exception.
     *
     * @param \Throwable $exception The exception thrown from an event callback.
     */
    final protected function error(\Closure $closure, \Throwable $exception): void
    {
        /** 如果没有设置异常处理函数，抛出异常 */
        if ($this->errorHandler === null) {
            /** 如果前一个中断存在，则显式覆盖它，在这种情况下，隐藏异常会更糟糕 */
            // Explicitly override the previous interrupt if it exists in this case, hiding the exception is worse
            $this->interrupt = static fn () => $exception instanceof UncaughtThrowable
                ? throw $exception
                : throw UncaughtThrowable::throwingCallback($closure, $exception);
            return;
        }
        /** 如果定义了异常处理回调函数 ，那么就创建一个协程来处理异常 */
        $fiber = new \Fiber($this->errorCallback);

        /** 开启协程 ，接手异常处理 */
        /** @noinspection PhpUnhandledExceptionInspection */
        $fiber->start($this->errorHandler, $exception);
    }

    /**
     * 返回当前时间
     * Returns the current event loop time in second increments.
     *
     * Note this value does not necessarily correlate to wall-clock time, rather the value returned is meant to be used
     * in relative comparisons to prior values returned by this method (intervals, expiration calculations, etc.).
     */
    abstract protected function now(): float;

    /**
     * 执行微服务
     * @return void
     * @throws \Throwable
     */
    private function invokeMicrotasks(): void
    {
        /** 遍历微服务任务 */
        while (!$this->microtaskQueue->isEmpty()) {
            /** 取出任务 ：回调函数，参数 */
            [$callback, $args] = $this->microtaskQueue->dequeue();

            try {
                /** 清除$args以允许垃圾收集 ，用法，先传入参数，然后在清空参数 */
                // Clear $args to allow garbage collection
                $callback(...$args, ...($args = []));
            } catch (\Throwable $exception) {
                /** 记录错误 */
                $this->error($callback, $exception);
            } finally {
                /** 清理协程局部变量 */
                FiberLocal::clear();
            }
            /** 释放内存，清理变量 ，为了保险再清理一次参数 */
            unset($callback, $args);
            /** 每一个执行完毕，都要判断是否需要打断协程 */
            if ($this->interrupt) {
                /** @noinspection PhpUnhandledExceptionInspection */
                \Fiber::suspend($this->internalSuspensionMarker);
            }
        }
    }

    /**
     *
     * 判断回调函数队列是否为空
     * @return bool True if no enabled and referenced callbacks remain in the loop.
     */
    private function isEmpty(): bool
    {
        foreach ($this->callbacks as $callback) {
            if ($callback->enabled && $callback->referenced) {
                return false;
            }
        }

        return true;
    }

    /**
     * 执行事件循环
     * Executes a single tick of the event loop.
     */
    private function tick(bool $previousIdle): void
    {
        /** 激活队列 */
        $this->activate($this->enableQueue);

        /** 遍历队列中的所有回调函数 */
        foreach ($this->enableQueue as $callback) {
            $callback->invokable = true;
        }
        /** 清空队列 */
        $this->enableQueue = [];
        /** 遍历稍后需要执行的队列任务 */
        foreach ($this->enableDeferQueue as $callback) {
            /** 标记可执行 */
            $callback->invokable = true;
            /** 将回调函数投递到队列 */
            $this->enqueueCallback($callback);
        }
        /** 清空稍后执行的队列 */
        $this->enableDeferQueue = [];
        /** 是否阻塞 */
        $blocking = $previousIdle
            && !$this->stopped
            && !$this->isEmpty();
        /** 如果是阻塞的 */
        if ($blocking) {
            /** 先执行回调 */
            $this->invokeCallbacks();
            /** 如果队列有数据 ，那么就不阻塞 */
            /** @psalm-suppress TypeDoesNotContainType */
            if (!empty($this->enableDeferQueue) || !empty($this->enableQueue)) {
                $blocking = false;
            }
        }
        /** 渲染 */
        /** @psalm-suppress RedundantCondition */
        $this->dispatch($blocking);
    }

    /**
     * 执行回调
     * @return void
     * @throws \Throwable
     */
    private function invokeCallbacks(): void
    {
        /** 如果微服务任务或者回调任务队列不为空 */
        while (!$this->microtaskQueue->isEmpty() || !$this->callbackQueue->isEmpty()) {
            /** 唤醒协程 ，如果协程一开始则唤醒，如果未开始则启动协程 */
            /** @noinspection PhpUnhandledExceptionInspection */
            $yielded = $this->callbackFiber->isStarted()
                ? $this->callbackFiber->resume()
                : $this->callbackFiber->start();

            /** 如果不挂起协程 */
            if ($yielded !== $this->internalSuspensionMarker) {
                /** 那么就创建回调协程 */
                $this->createCallbackFiber();
            }
            /** 如果要打断 */
            if ($this->interrupt) {
                /** 打断协程 */
                $this->invokeInterrupt();
            }
        }
    }

    /**
     * 打断协程
     * @param \Closure():mixed $interrupt
     */
    private function setInterrupt(\Closure $interrupt): void
    {
        \assert($this->interrupt === null);

        $this->interrupt = $interrupt;
    }

    /**
     * 终端协程
     * @return void
     * @throws \Throwable
     */
    private function invokeInterrupt(): void
    {
        \assert($this->interrupt !== null);

        $interrupt = $this->interrupt;
        $this->interrupt = null;

        /** 打断协程 */
        /** @noinspection PhpUnhandledExceptionInspection */
        \Fiber::suspend($interrupt);
    }

    /**
     * 创建轮训协程
     * @return void
     */
    private function createLoopFiber(): void
    {
        var_dump("创建轮询协程");
        $this->fiber = new \Fiber(function (): void {
            $this->stopped = false;
            /** 执行回调 */
            // Invoke microtasks if we have some
            $this->invokeCallbacks();

            /** @psalm-suppress RedundantCondition $this->stopped may be changed by $this->invokeCallbacks(). */
            while (!$this->stopped) {
                if ($this->interrupt) {
                    /** 终端协程 */
                    $this->invokeInterrupt();
                }

                if ($this->isEmpty()) {
                    return;
                }

                $previousIdle = $this->idle;
                $this->idle = true;
                /** 执行任务 */
                $this->tick($previousIdle);
                /** 调用回调 */
                $this->invokeCallbacks();
            }
        });
    }

    /**
     * 创建回调协程
     * @return void
     */
    private function createCallbackFiber(): void
    {
        var_dump("创建回调协程");
        $this->callbackFiber = new \Fiber(function (): void {
            do {
                $this->invokeMicrotasks();

                while (!$this->callbackQueue->isEmpty()) {
                    /** 回调函数都存放在队列里面的 */
                    /** @var DriverCallback $callback */
                    $callback = $this->callbackQueue->dequeue();
                    //var_dump("打印回调函数",$callback);
                    /** 如果没有回调函数，或者回调函数不可调用 ，那么释放内存 */
                    if (!isset($this->callbacks[$callback->id]) || !$callback->invokable) {
                        unset($callback);

                        continue;
                    }
                    /** 如果回调函数都是继承了 DeferCallback 延迟执行 */
                    if ($callback instanceof DeferCallback) {
                        /** 取消这个回调函数 */
                        $this->cancel($callback->id);
                    } elseif ($callback instanceof TimerCallback) {
                        /** 如果这个回调是定时任务 ，并且不是重复执行的则取消 */
                        if (!$callback->repeat) {
                            $this->cancel($callback->id);
                        } else {
                            /** 需要循环重复执行 禁用并重新启用，这样它就不会在同一时间内重复执行 */
                            // Disable and re-enable, so it's not executed repeatedly in the same tick
                            // See https://github.com/amphp/amp/issues/131
                            $this->disable($callback->id);
                            $this->enable($callback->id);
                        }
                    }
                    /** 根据回调类型执行回调函数 */
                    try {
                        $result = match (true) {
                            /** 如果数据流类回调 ，那么执行回调，并传入函数id，数据流 */
                            $callback instanceof StreamCallback => ($callback->closure)(
                                $callback->id,
                                $callback->stream
                            ),
                            /** 如果是信号类回调 ，执行回调，传入函数id，以及信号 */
                            $callback instanceof SignalCallback => ($callback->closure)(
                                $callback->id,
                                $callback->signal
                            ),
                            /** 默认执行回调函数，只传入函数id */
                            default => ($callback->closure)($callback->id),
                        };
                        /** 如果执行结果不为空 ，抛出异常 */
                        if ($result !== null) {
                            throw InvalidCallbackError::nonNullReturn($callback->id, $callback->closure);
                        }
                    } catch (\Throwable $exception) {
                        $this->error($callback->closure, $exception);
                    } finally {
                        /** 执行完成后，清空缓存 貌似清空协程中的缓存 */
                        FiberLocal::clear();
                    }
                    /** 执行完成后，释放内存 */
                    unset($callback);
                    /** 打断 ，挂起协程 */
                    if ($this->interrupt) {
                        /** @noinspection PhpUnhandledExceptionInspection */
                        \Fiber::suspend($this->internalSuspensionMarker);
                    }
                    /** 执行微服务 */
                    $this->invokeMicrotasks();
                }
                /** 挂起协程 */
                /** @noinspection PhpUnhandledExceptionInspection */
                \Fiber::suspend($this->internalSuspensionMarker);
            } while (true);
        });
    }

    /**
     * 创建处理错误回调函数
     * @return void
     * @note 使用的匿名函数
     */
    private function createErrorCallback(): void
    {
        $this->errorCallback = function (\Closure $errorHandler, \Throwable $exception): void {
            try {
                $errorHandler($exception);
            } catch (\Throwable $exception) {
                $this->interrupt = static fn () => $exception instanceof UncaughtThrowable
                    ? throw $exception
                    : throw UncaughtThrowable::throwingErrorHandler($errorHandler, $exception);
            }
        };
    }
}
