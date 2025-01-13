<?php
/** 以下是使用fiber协程创建的服务端 */
// 引入自动加载文件（假设通过Composer管理依赖）
require 'vendor/autoload.php';

// 引入Revolt\EventLoop命名空间
use Revolt\EventLoop;

/**
 * 关闭客户端
 * @param $clientSocket
 * @return void
 */
function closeClient($clientSocket)
{
    global $readServers, $writeFibers, $writeServers, $readFibers;
    /** 首先移除客户端的读写事件 */
    EventLoop::cancel($writeServers [(int)$clientSocket] ?? '');
    EventLoop::cancel($readServers [(int)$clientSocket] ?? '');
    /** 将客户端和协程从内存中移除 */
    unset($readFibers[(int)$clientSocket]);
    unset($readServers[(int)$clientSocket]);
    unset($writeFibers[(int)$clientSocket]);
    unset($writeServers[(int)$clientSocket]);
    /** 关闭客户端 */
    @fclose($clientSocket);
}

// 创建服务器套接字资源，基于TCP协议监听在本地127.0.0.1的8888端口
$serverSocket = stream_socket_server("tcp://127.0.0.1:8888", $errno, $errstr);
if (!$serverSocket) {
    die("无法创建服务器套接字: $errstr ($errno)");
}
/** 设置为异步 */
stream_set_blocking($serverSocket, 0);

/** 读客户端 */
$readServers = [];
/** 写服务端 */
$writeServers = [];
/** 客户端可读协程组 */
$readFibers = [];
/** 客户端可写协程组 */
$writeFibers = [];
/** 客户端连接事件 */
$onConnect = function ($clientSocket) {

    global $readServers, $writeServers;
    /** 客户端可读事件 */
    $onRead = function () use ($clientSocket) {
        global $readFibers;
        /** 如果这个客户端没有创建写协程 ，则创建写协程 */
        if (!isset($readFibers[(int)$clientSocket])) {
            $readFibers[(int)$clientSocket] = new Fiber(function () use ($clientSocket) {
                /** 获取当前协程  */
                $fiber = Fiber::getCurrent();
                /** 唤醒协程 */
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                }
                /** 为了保持协程存活，使用while循环 */
                while (true) {
                    /** 每次读取3个字节 */
                    $data = fread($clientSocket, 3);
                    /** 如果客户端已关闭，或者读取数据出错，则删除这个客户端的读写协程，并关闭客户端 */
                    if (($data === false) || (!is_resource($clientSocket))) {
                        closeClient($clientSocket);
                        return;
                    }
                    /** 如果有数据，则打印 */
                    if ($data) {
                        //todo  具体业务逻辑写在这里
                        echo $data . "\r\n";
                    }
                    /** 读取一次后立即暂停当前协程，切换到其他协程 */
                    $fiber->suspend();
                }
            });
            /** 启动写协程 */
            $readFibers[(int)$clientSocket]->start();
        } else {
            /** 如果协程已暂停，则唤醒协程 */
            if ($readFibers[(int)$clientSocket]->isSuspended()) {
                $readFibers[(int)$clientSocket]->resume();
            }
            /** 如果协程已死，则清理读写事件，并关闭客户端 */
            if ($readFibers[(int)$clientSocket]->isTerminated()) {
                closeClient($clientSocket);
                return;
            }
        }
    };

    /** 给客户端添加可读事件 ，并保存 */
    $readServers[(int)$clientSocket] = EventLoop::onReadable($clientSocket, $onRead);

    /** 客户端可写事件 */
    $onWrite = function () use ($clientSocket) {
        global $writeFibers;
        /** 如果没有这个客户端的写协程，那么就创建 */
        if (!isset($writeFibers[(int)$clientSocket])) {
            $writeFibers[(int)$clientSocket] = new Fiber(function () use ($clientSocket) {
                global $writeServers;
                /** 获取当前协程  */
                $fiber = Fiber::getCurrent();
                /** 唤醒协程 */
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                }
                while (true) {
                    /** 客户端已关闭，则取消写事件*/
                    if (!is_resource($clientSocket)) {
                        closeClient($clientSocket);
                        return;
                    } else {
                        /** 向客户端发送数据，保持通信状态 */
                        $length = @fwrite($clientSocket, 'hello');
                        /** 写入失败，说明对方客户端已死 */
                        if ($length === false) {
                            closeClient($clientSocket);return;
                        }
                        /** 暂停当前协程 保证协程存活 */
                        $fiber->suspend();
                    }
                }
            });
            /** 启动写协程  */
            $writeFibers[(int)$clientSocket]->start();
        } else {
            /** 唤醒写协程 */
            $fiber = $writeFibers[(int)$clientSocket];
            if ($fiber->isSuspended()) {
                $fiber->resume();
            }
            /** 写协程已死，则关闭客户端的所有协程和客户端 */
            if ($fiber->isTerminated()) {
                closeClient($clientSocket);
                return;
            }
        }
    };
    /** 给客户端添加可写事件 */
    $writeServers[(int)$clientSocket] = EventLoop::onWritable($clientSocket, $onWrite);
};

/** 为服务器套接字添加可读事件监听器，当有新客户端连接时触发$onConnect回调 */
EventLoop::onReadable($serverSocket, function () use ($serverSocket, $onConnect) {
    $clientSocket = stream_socket_accept($serverSocket);
    if ($clientSocket) {
        // 调用 $onConnect 回调函数处理新连接
        $fiber = new Fiber(function () use ($clientSocket, $onConnect) {
            $onConnect($clientSocket);
        });
        $fiber->start();
    }
});

/** 启动事件循环，驱动协程运行，处理各种事件 */
EventLoop::run();

?>