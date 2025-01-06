<?php

declare(strict_types=1);

namespace Revolt\EventLoop;

// @codeCoverageIgnoreStart
use Revolt\EventLoop\Driver\EvDriver;
use Revolt\EventLoop\Driver\EventDriver;
use Revolt\EventLoop\Driver\StreamSelectDriver;
use Revolt\EventLoop\Driver\TracingDriver;
use Revolt\EventLoop\Driver\UvDriver;

final class DriverFactory
{
    /**
     * 选择最匹配的驱动
     * Creates a new loop instance and chooses the best available driver.
     *
     * @return Driver
     *
     * @throws \Error If an invalid class has been specified via REVOLT_LOOP_DRIVER
     * @note 从以下代码看出，io性能：uv > ev > event > select
     * @note uv（libuv）> epoll > poll > select
     * @note uv和 ev 是封装的跨平台io模型，底层是epoll和poll
     */
    public function create(): Driver
    {
        /** 使用匿名函数获取驱动 */
        $driver = (function () {
            if ($driver = $this->createDriverFromEnv()) {
                return $driver;
            }

            if (UvDriver::isSupported()) {
                var_dump("uv");
                return new UvDriver();
            }

            if (EvDriver::isSupported()) {
                var_dump('ev');
                return new EvDriver();
            }

            if (EventDriver::isSupported()) {
                var_dump('event');
                return new EventDriver();
            }

            var_dump('select');
            return new StreamSelectDriver();
        })();

        if (\getenv("REVOLT_DRIVER_DEBUG_TRACE")) {
            return new TracingDriver($driver);
        }

        return $driver;
    }

    /**
     * 从env创建模型
     * @return Driver|null
     */
    private function createDriverFromEnv(): ?Driver
    {
        $driver = \getenv("REVOLT_DRIVER");

        if (!$driver) {
            return null;
        }

        if (!\class_exists($driver)) {
            throw new \Error(\sprintf(
                "Driver '%s' does not exist.",
                $driver
            ));
        }

        if (!\is_subclass_of($driver, Driver::class)) {
            throw new \Error(\sprintf(
                "Driver '%s' is not a subclass of '%s'.",
                $driver,
                Driver::class
            ));
        }

        return new $driver();
    }
}
// @codeCoverageIgnoreEnd
