<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Driver;

use Revolt\EventLoop\CallbackType;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\InvalidCallbackError;
use Revolt\EventLoop\Suspension;

/**
 * @purpose 追踪驱动
 */
final class TracingDriver implements Driver
{
    private readonly Driver $driver;

    /** @var array<string, true> */
    private array $enabledCallbacks = [];

    /** @var array<string, true> */
    private array $unreferencedCallbacks = [];

    /** @var array<string, string> */
    private array $creationTraces = [];

    /** @var array<string, string> */
    private array $cancelTraces = [];

    /**
     * 初始化
     * @param Driver $driver
     */
    public function __construct(Driver $driver)
    {
        $this->driver = $driver;
    }

    /**
     * 启动
     * @return void
     */
    public function run(): void
    {
        $this->driver->run();
    }

    /**
     * 停止
     * @return void
     */
    public function stop(): void
    {
        $this->driver->stop();
    }

    /**
     * 挂起
     * @return Suspension
     */
    public function getSuspension(): Suspension
    {
        return $this->driver->getSuspension();
    }

    /**
     * 判断是否在运行
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->driver->isRunning();
    }

    /**
     * 延迟执行
     * @param \Closure $closure
     * @return string
     */
    public function defer(\Closure $closure): string
    {
        $id = $this->driver->defer(function (...$args) use ($closure) {
            $this->cancel($args[0]);
            return $closure(...$args);
        });
        /** 记录追踪日志 */
        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    /**
     * 延迟执行
     * @param float $delay
     * @param \Closure $closure
     * @return string
     */
    public function delay(float $delay, \Closure $closure): string
    {
        $id = $this->driver->delay($delay, function (...$args) use ($closure) {
            $this->cancel($args[0]);
            return $closure(...$args);
        });

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    /**
     * 重复执行
     * @param float $interval
     * @param \Closure $closure
     * @return string
     */
    public function repeat(float $interval, \Closure $closure): string
    {
        $id = $this->driver->repeat($interval, $closure);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    /**
     * 可读事件
     * @param mixed $stream
     * @param \Closure $closure
     * @return string
     */
    public function onReadable(mixed $stream, \Closure $closure): string
    {
        $id = $this->driver->onReadable($stream, $closure);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    /**
     * 可写事件
     * @param mixed $stream
     * @param \Closure $closure
     * @return string
     */
    public function onWritable(mixed $stream, \Closure $closure): string
    {
        $id = $this->driver->onWritable($stream, $closure);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    /**
     * 信号处理
     * @param int $signal
     * @param \Closure $closure
     * @return string
     * @throws \Revolt\EventLoop\UnsupportedFeatureException
     */
    public function onSignal(int $signal, \Closure $closure): string
    {
        $id = $this->driver->onSignal($signal, $closure);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    /**
     * 开启追踪
     * @param string $callbackId
     * @return string
     */
    public function enable(string $callbackId): string
    {
        try {
            $this->driver->enable($callbackId);
            $this->enabledCallbacks[$callbackId] = true;
        } catch (InvalidCallbackError $e) {
            $e->addInfo("Creation trace", $this->getCreationTrace($callbackId));
            $e->addInfo("Cancellation trace", $this->getCancelTrace($callbackId));

            throw $e;
        }

        return $callbackId;
    }

    /**
     * 取消追踪
     * @param string $callbackId
     * @return void
     */
    public function cancel(string $callbackId): void
    {
        $this->driver->cancel($callbackId);

        if (!isset($this->cancelTraces[$callbackId])) {
            $this->cancelTraces[$callbackId] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        }

        unset($this->enabledCallbacks[$callbackId], $this->unreferencedCallbacks[$callbackId]);
    }

    /**
     * 关闭追踪
     * @param string $callbackId
     * @return string
     */
    public function disable(string $callbackId): string
    {
        $this->driver->disable($callbackId);
        unset($this->enabledCallbacks[$callbackId]);

        return $callbackId;
    }

    /**
     * 引用
     * @param string $callbackId
     * @return string
     */
    public function reference(string $callbackId): string
    {
        try {
            $this->driver->reference($callbackId);
            unset($this->unreferencedCallbacks[$callbackId]);
        } catch (InvalidCallbackError $e) {
            $e->addInfo("Creation trace", $this->getCreationTrace($callbackId));
            $e->addInfo("Cancellation trace", $this->getCancelTrace($callbackId));

            throw $e;
        }

        return $callbackId;
    }

    /**
     * 取消引用
     * @param string $callbackId
     * @return string
     */
    public function unreference(string $callbackId): string
    {
        $this->driver->unreference($callbackId);
        $this->unreferencedCallbacks[$callbackId] = true;

        return $callbackId;
    }

    /**
     * 设定错误处理器
     * @param \Closure|null $errorHandler
     * @return void
     */
    public function setErrorHandler(?\Closure $errorHandler): void
    {
        $this->driver->setErrorHandler($errorHandler);
    }

    /**
     * 获取错误处理器
     * @return \Closure|null
     */
    public function getErrorHandler(): ?\Closure
    {
        return $this->driver->getErrorHandler();
    }

    /** @inheritdoc */
    public function getHandle(): mixed
    {
        return $this->driver->getHandle();
    }

    /**
     * 打印追踪记录
     * @return string
     */
    public function dump(): string
    {
        $dump = "Enabled, referenced callbacks keeping the loop running: ";

        foreach ($this->enabledCallbacks as $callbackId => $_) {
            if (isset($this->unreferencedCallbacks[$callbackId])) {
                continue;
            }

            $dump .= "Callback identifier: " . $callbackId . "\r\n";
            $dump .= $this->getCreationTrace($callbackId);
            $dump .= "\r\n\r\n";
        }

        return \rtrim($dump);
    }

    /** 获取识别符号
     * @return array|string[]
     */
    public function getIdentifiers(): array
    {
        return $this->driver->getIdentifiers();
    }

    /**
     * 获取回调类型
     * @param string $callbackId
     * @return CallbackType
     */
    public function getType(string $callbackId): CallbackType
    {
        return $this->driver->getType($callbackId);
    }

    /**
     * 是否可用
     * @param string $callbackId
     * @return bool
     */
    public function isEnabled(string $callbackId): bool
    {
        return $this->driver->isEnabled($callbackId);
    }

    /**
     * 是否可引用
     * @param string $callbackId
     * @return bool
     */
    public function isReferenced(string $callbackId): bool
    {
        return $this->driver->isReferenced($callbackId);
    }

    /**
     * 调试
     * @return array
     */
    public function __debugInfo(): array
    {
        return $this->driver->__debugInfo();
    }

    /**
     * 投递到队列
     * @param \Closure $closure
     * @param mixed ...$args
     * @return void
     */
    public function queue(\Closure $closure, mixed ...$args): void
    {
        $this->driver->queue($closure, ...$args);
    }

    /**
     * 获取创建追踪信息
     * @param string $callbackId
     * @return string
     */
    private function getCreationTrace(string $callbackId): string
    {
        return $this->creationTraces[$callbackId] ?? 'No creation trace, yet.';
    }

    /**
     * 获取取消追踪信息
     * @param string $callbackId
     * @return string
     */
    private function getCancelTrace(string $callbackId): string
    {
        return $this->cancelTraces[$callbackId] ?? 'No cancellation trace, yet.';
    }

    /**
     * 格式化追踪信息
     * Formats a stacktrace obtained via `debug_backtrace()`.
     *
     * @param list<array{
     *     args?: list<mixed>,
     *     class?: class-string,
     *     file?: string,
     *     function: string,
     *     line?: int,
     *     object?: object,
     *     type?: string
     * }> $trace Output of `debug_backtrace()`.
     *
     * @return string Formatted stacktrace.
     */
    private function formatStacktrace(array $trace): string
    {
        return \implode("\n", \array_map(static function ($e, $i) {
            $line = "#{$i} ";

            if (isset($e["file"], $e['line'])) {
                $line .= "{$e['file']}:{$e['line']} ";
            }

            if (isset($e["class"], $e["type"])) {
                $line .= $e["class"] . $e["type"];
            }

            return $line . $e["function"] . "()";
        }, $trace, \array_keys($trace)));
    }
}
