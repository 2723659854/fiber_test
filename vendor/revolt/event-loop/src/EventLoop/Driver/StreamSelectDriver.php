<?php

declare(strict_types=1);

/** @noinspection PhpComposerExtensionStubsInspection */

namespace Revolt\EventLoop\Driver;

use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\DriverCallback;
use Revolt\EventLoop\Internal\SignalCallback;
use Revolt\EventLoop\Internal\StreamReadableCallback;
use Revolt\EventLoop\Internal\StreamWritableCallback;
use Revolt\EventLoop\Internal\TimerCallback;
use Revolt\EventLoop\Internal\TimerQueue;
use Revolt\EventLoop\UnsupportedFeatureException;

/**
 * select IO驱动模型
 */
final class StreamSelectDriver extends AbstractDriver
{
    /** @var array<int, resource> */
    private array $readStreams = [];

    /** @var array<int, array<string, StreamReadableCallback>> */
    private array $readCallbacks = [];

    /** @var array<int, resource> */
    private array $writeStreams = [];

    /** @var array<int, array<string, StreamWritableCallback>> */
    private array $writeCallbacks = [];

    private readonly TimerQueue $timerQueue;

    /** @var array<int, array<string, SignalCallback>> */
    private array $signalCallbacks = [];

    /** @var \SplQueue<int> */
    private readonly \SplQueue $signalQueue;

    private bool $signalHandling;

    private readonly \Closure $streamSelectErrorHandler;

    private bool $streamSelectIgnoreResult = false;

    public function __construct()
    {
        parent::__construct();
        /** 实例化信号处理器队列 */
        $this->signalQueue = new \SplQueue();
        /** 初始化定时器队列 都是存在内存里面的 */
        $this->timerQueue = new TimerQueue();
        $this->signalHandling = \extension_loaded("pcntl")
            && \function_exists('pcntl_signal_dispatch')
            && \function_exists('pcntl_signal');

        $this->streamSelectErrorHandler = function (int $errno, string $message): void {
            // Casing changed in PHP 8 from 'unable' to 'Unable'
            if (\stripos($message, "stream_select(): unable to select [4]: ") === 0) { // EINTR
                $this->streamSelectIgnoreResult = true;

                return;
            }
            /** select 模型超过最大连接数 提示安装扩展 */
            if (\str_contains($message, 'FD_SETSIZE')) {
                $message = \str_replace(["\r\n", "\n", "\r"], " ", $message);
                $pattern = '(stream_select\(\): You MUST recompile PHP with a larger value of FD_SETSIZE. It is set to (\d+), but you have descriptors numbered at least as high as (\d+)\.)';

                if (\preg_match($pattern, $message, $match)) {
                    $helpLink = 'https://revolt.run/extensions';

                    $message = 'You have reached the limits of stream_select(). It has a FD_SETSIZE of ' . $match[1]
                        . ', but you have file descriptors numbered at least as high as ' . $match[2] . '. '
                        . "You can install one of the extensions listed on {$helpLink} to support a higher number of "
                        . "concurrent file descriptors. If a large number of open file descriptors is unexpected, you "
                        . "might be leaking file descriptors that aren't closed correctly.";
                }
            }

            throw new \Exception($message, $errno);
        };
    }

    /**
     * 退出系统的时候，解除信号处理器，就是控制权归还给系统
     */
    public function __destruct()
    {
        foreach ($this->signalCallbacks as $signalCallbacks) {
            foreach ($signalCallbacks as $signalCallback) {
                $this->deactivate($signalCallback);
            }
        }
    }

    /**
     * 信号处理
     * @throws UnsupportedFeatureException If the pcntl extension is not available.
     */
    public function onSignal(int $signal, \Closure $closure): string
    {
        if (!$this->signalHandling) {
            throw new UnsupportedFeatureException("Signal handling requires the pcntl extension");
        }

        return parent::onSignal($signal, $closure);
    }

    public function getHandle(): mixed
    {
        return null;
    }

    protected function now(): float
    {
        return (float) \hrtime(true) / 1_000_000_000;
    }

    /**
     * 分发任务
     * @throws \Throwable
     */
    protected function dispatch(bool $blocking): void
    {
        if ($this->signalHandling) {
            /** 触发闹钟信号 */
            \pcntl_signal_dispatch();
            /** 取出信号队列中的所有回调函数，然后投递到待处理队列中按顺序执行 */
            while (!$this->signalQueue->isEmpty()) {
                $signal = $this->signalQueue->dequeue();

                foreach ($this->signalCallbacks[$signal] as $callback) {
                    $this->enqueueCallback($callback);
                }

                $blocking = false;
            }
        }
        /** 然后遍历 所有可读可写链接 */
        $this->selectStreams(
            $this->readStreams,
            $this->writeStreams,
            $blocking ? $this->getTimeout() : 0.0
        );
        /** 然后处理当前时刻的定时任务 */
        $now = $this->now();
        /** 取出定时任务投递到待处理队列中执行 */
        while ($callback = $this->timerQueue->extract($now)) {
            $this->enqueueCallback($callback);
        }
    }

    /** 激活 就是执行回调函数 */
    protected function activate(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            /** 可读事件 */
            if ($callback instanceof StreamReadableCallback) {
                \assert(\is_resource($callback->stream));

                $streamId = (int) $callback->stream;
                $this->readCallbacks[$streamId][$callback->id] = $callback;
                $this->readStreams[$streamId] = $callback->stream;
            } elseif ($callback instanceof StreamWritableCallback) {
                /** 可写事件 */
                \assert(\is_resource($callback->stream));

                $streamId = (int) $callback->stream;
                $this->writeCallbacks[$streamId][$callback->id] = $callback;
                $this->writeStreams[$streamId] = $callback->stream;
            } elseif ($callback instanceof TimerCallback) {
                /** 定时任务 */
                $this->timerQueue->insert($callback);
            } elseif ($callback instanceof SignalCallback) {
                /** 信号处理器 */
                if (!isset($this->signalCallbacks[$callback->signal])) {
                    \set_error_handler(static function (int $errno, string $errstr): bool {
                        throw new UnsupportedFeatureException(
                            \sprintf("Failed to register signal handler; Errno: %d; %s", $errno, $errstr)
                        );
                    });

                    /** 通过为临时变量赋值，避免Psalm处理一级可调用函数时出现错误 */
                    // Avoid bug in Psalm handling of first-class callables by assigning to a temp variable.
                    $handler = $this->handleSignal(...);
                    /** 注册信号，给一个空的回调 */
                    try {
                        \pcntl_signal($callback->signal, $handler);
                    } finally {
                        \restore_error_handler();
                    }
                }
                /** 保存这个信号处理回调函数 */
                $this->signalCallbacks[$callback->signal][$callback->id] = $callback;
            } else {
                // @codeCoverageIgnoreStart
                throw new \Error("Unknown callback type");
                // @codeCoverageIgnoreEnd
            }
        }
    }

    /**
     * 卸载回调函数
     * @param DriverCallback $callback
     * @return void
     */
    protected function deactivate(DriverCallback $callback): void
    {
        if ($callback instanceof StreamReadableCallback) {
            /** 可读事件 */
            $streamId = (int) $callback->stream;
            unset($this->readCallbacks[$streamId][$callback->id]);
            if (empty($this->readCallbacks[$streamId])) {
                unset($this->readCallbacks[$streamId], $this->readStreams[$streamId]);
            }
        } elseif ($callback instanceof StreamWritableCallback) {
            /** 可写事件 */
            $streamId = (int) $callback->stream;
            unset($this->writeCallbacks[$streamId][$callback->id]);
            if (empty($this->writeCallbacks[$streamId])) {
                unset($this->writeCallbacks[$streamId], $this->writeStreams[$streamId]);
            }
        } elseif ($callback instanceof TimerCallback) {
            /** 定时任务 */
            $this->timerQueue->remove($callback);
        } elseif ($callback instanceof SignalCallback) {
            if (isset($this->signalCallbacks[$callback->signal])) {
                unset($this->signalCallbacks[$callback->signal][$callback->id]);

                if (empty($this->signalCallbacks[$callback->signal])) {
                    unset($this->signalCallbacks[$callback->signal]);
                    \set_error_handler(static fn () => true);
                    try {
                        /** 重新注册信号，当接收到这个信号之后，恢复为默认的处理方式，就是归还给系统控制 */
                        \pcntl_signal($callback->signal, \SIG_DFL);
                    } finally {
                        \restore_error_handler();
                    }
                }
            }
        } else {
            // @codeCoverageIgnoreStart
            throw new \Error("Unknown callback type");
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * io遍历读写事件
     * @param array<int, resource> $read
     * @param array<int, resource> $write
     */
    private function selectStreams(array $read, array $write, float $timeout): void
    {
        /** 如果存在读写事件 */
        if (!empty($read) || !empty($write)) { // Use stream_select() if there are any streams in the loop.
            if ($timeout >= 0) {
                $seconds = (int) $timeout;
                $microseconds = (int) (($timeout - $seconds) * 1_000_000);
            } else {
                $seconds = null;
                $microseconds = null;
            }

            /** 失败的连接尝试通过Windows上的“除外”指示 */
            // Failed connection attempts are indicated via except on Windows
            // @link https://github.com/reactphp/event-loop/blob/8bd064ce23c26c4decf186c2a5a818c9a8209eb0/src/StreamSelectLoop.php#L279-L287
            // @link https://docs.microsoft.com/de-de/windows/win32/api/winsock2/nf-winsock2-select
            $except = null;
            if (\DIRECTORY_SEPARATOR === '\\') {
                $except = $write;
            }

            \set_error_handler($this->streamSelectErrorHandler);
            /** 遍历读写连接 */
            try {
                /** @psalm-suppress InvalidArgument */
                $result = \stream_select($read, $write, $except, $seconds, $microseconds);
            } finally {
                \restore_error_handler();
            }

            /** 没有可读写的连接 */
            if ($this->streamSelectIgnoreResult || $result === 0) {
                $this->streamSelectIgnoreResult = false;
                return;
            }

            if (!$result) {
                throw new \Exception('Unknown error during stream_select');
            }

            /** 遍历可读链接 */
            foreach ($read as $stream) {
                $streamId = (int) $stream;
                /** 判断可读事件是否可用 */
                if (!isset($this->readCallbacks[$streamId])) {
                    continue; // All read callbacks disabled.
                }
                /** 将这个链接的所有回调函数都投递到执行 队列中 */
                foreach ($this->readCallbacks[$streamId] as $callback) {
                    $this->enqueueCallback($callback);
                }
            }
            /** 如果有异常链接 */
            /** @var array<int, resource>|null $except */
            if ($except) {
                /** 将异常链接投递到可写连接 */
                foreach ($except as $key => $socket) {
                    $write[$key] = $socket;
                }
            }
            /** 遍历可写连接 */
            foreach ($write as $stream) {
                $streamId = (int) $stream;
                /** 如果已关闭连接的可写连接，则跳过 */
                if (!isset($this->writeCallbacks[$streamId])) {
                    continue; // All write callbacks disabled.
                }
                /** 将这个链接的所有可写事件投递到队列中 */
                foreach ($this->writeCallbacks[$streamId] as $callback) {
                    $this->enqueueCallback($callback);
                }
            }

            return;
        }

        /** 处理完之后，就需要判断超时时间  只启用信号回调，因此无限期睡眠。 除非被唤醒，否则一直休眠 */
        if ($timeout < 0) { // Only signal callbacks are enabled, so sleep indefinitely.
            /** @psalm-suppress ArgumentTypeCoercion */
            \usleep(\PHP_INT_MAX);
            return;
        }
        /** 休眠指定的时间，直到下一次循环 */
        if ($timeout > 0) { // Sleep until next timer expires.
            /** @psalm-suppress ArgumentTypeCoercion $timeout is positive here. */
            \usleep((int) ($timeout * 1_000_000));
        }
    }

    /**
     * 下一个计时器到期前的秒数，如果没有待处理的计时器，则为-1。
     * @return float Seconds until next timer expires or -1 if there are no pending timers.
     * @note select遍历需要这个时间，如果有定时任务，则等待，反之立即执行循环。这么写会不会有问题，会阻塞当前的服务器，导致不可用
     */
    private function getTimeout(): float
    {
        $expiration = $this->timerQueue->peek();

        if ($expiration === null) {
            return -1;
        }

        $expiration -= $this->now();

        return $expiration > 0 ? $expiration : 0.0;
    }

    private function handleSignal(int $signal): void
    {
        // Queue signals, so we don't suspend inside pcntl_signal_dispatch, which disables signals while it runs
        $this->signalQueue->enqueue($signal);
    }
}
