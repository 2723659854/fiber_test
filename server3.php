<?php
// 引入自动加载文件（假设通过Composer管理依赖）
require 'vendor/autoload.php';
// 引入Revolt\EventLoop命名空间
use Revolt\EventLoop;

// 创建服务器套接字资源，基于TCP协议监听在本地127.0.0.1的8888端口
$serverSocket = stream_socket_server("tcp://127.0.0.1:8888", $errno, $errstr);
if (!$serverSocket) {
    die("无法创建服务器套接字: $errstr ($errno)");
}
// 设置为异步
stream_set_blocking($serverSocket, 0);
echo "=================================================\r\n";

// 保存所有的客户端
$servers = [];
// 客户端消息协程数组
$messageFibers = [];
// 存储要发送给客户端的数据的缓冲区
$writeBuffers = [];

// 当有新的客户端连接时的回调函数
$onConnect = function ($clientSocket) use (&$writeBuffers) {
    // 初始化客户端写缓冲数据为空字符串
    $writeBuffers[(int) $clientSocket] = '';

    // 当从客户端接收到数据时的回调函数
    $onData = function () use ($clientSocket, &$messageFibers, &$writeBuffers) {
        global $servers;
        if (!isset($messageFibers[(int) $clientSocket])) {
            $messageFibers[(int) $clientSocket] = new Fiber(function () use ($clientSocket, &$messageFibers, &$writeBuffers) {
                while (true) {
                    $data = fread($clientSocket, 1024);
                    if ($data === false) {
                        // 如果读取数据出错，关闭客户端连接，移除相关资源
                        EventLoop::cancel($servers[(int) $clientSocket]['read']);
                        EventLoop::cancel($servers[(int) $clientSocket]['write']);
                        unset($messageFibers[(int) $clientSocket]);
                        unset($writeBuffers[(int) $clientSocket]);
                        return;
                    }
                    if ($data === "") {
                        // 暂停协程，等待更多数据
                        Fiber::suspend();
                        continue;
                    }
                    if (!is_resource($clientSocket)) {
                        // 连接已断开，移除相关资源
                        EventLoop::cancel($servers[(int) $clientSocket]['read']);
                        EventLoop::cancel($servers[(int) $clientSocket]['write']);
                        unset($messageFibers[(int) $clientSocket]);
                        unset($writeBuffers[(int) $clientSocket]);
                        return;
                    }
                    echo $data;
                    echo "\r\n";
                    // 将接收到的数据添加到写缓冲数据中，准备后续发送给客户端
                    $writeBuffers[(int) $clientSocket].= $data;
                    // 唤醒可写协程发送数据
                    if (isset($servers[(int) $clientSocket]['write'])) {
                        $servers[(int) $clientSocket]['write']->resume();
                    }
                }
            });
            $messageFibers[(int) $clientSocket]->start();
        } else {
            if ($messageFibers[(int) $clientSocket]->isSuspended()) {
                $messageFibers[(int) $clientSocket]->resume();
            }
        }
    };

    // 当客户端可写时的回调函数
    $onWrite = function () use ($clientSocket, &$messageFibers, &$writeBuffers) {
        global $servers;
        if (!isset($servers[(int) $clientSocket]['write'])) {
            $servers[(int) $clientSocket]['write'] = new Fiber(function () use ($clientSocket, &$messageFibers, &$writeBuffers) {
                while (true) {
                    if (empty($writeBuffers[(int) $clientSocket])) {
                        // 暂停协程，等待有数据可写
                        Fiber::suspend();
                        continue;
                    }
                    $bytesWritten = fwrite($clientSocket, $writeBuffers[(int) $clientSocket]);
                    if ($bytesWritten === false) {
                        // 如果写入数据出错，关闭客户端连接，移除相关资源
                        EventLoop::cancel($servers[(int) $clientSocket]['read']);
                        EventLoop::cancel($servers[(int) $clientSocket]['write']);
                        unset($messageFibers[(int) $clientSocket]);
                        unset($writeBuffers[(int) $clientSocket]);
                        return;
                    }
                    if ($bytesWritten === 0) {
                        // 如果写入字节数为0，表示暂时不可写，暂停等待下次可写事件
                        Fiber::suspend();
                        continue;
                    }
                    // 从写缓冲数据中移除已经成功发送的数据
                    $writeBuffers[(int) $clientSocket] = substr($writeBuffers[(int) $clientSocket], $bytesWritten);
                }
            });
            $servers[(int) $clientSocket]['write']->start();
        } else {
            var_dump($servers[(int) $clientSocket]['write']);
            if ($servers[(int) $clientSocket]['write']->isSuspended()) {
                $servers[(int) $clientSocket]['write']->resume();
            }
        }
    };

    global $servers;
    // 为客户端套接字添加可读事件监听器，当有数据可读时触发$onData回调
    $servers[(int) $clientSocket] = [
        'read' => EventLoop::onReadable($clientSocket, $onData),
        'write' => null
    ];
    // 为客户端套接字添加可写事件监听器，当可写时触发$onWrite回调
    $servers[(int) $clientSocket]['write'] = EventLoop::onWritable($clientSocket, $onWrite);
};

// 为服务器套接字添加可读事件监听器，当有新客户端连接时触发$onConnect回调
EventLoop::onReadable($serverSocket, function () use ($serverSocket, $onConnect) {
    $clientSocket = stream_socket_accept($serverSocket);
    if ($clientSocket) {
        $onConnect($clientSocket);
    }
});

// 启动事件循环，驱动协程运行，处理各种事件
EventLoop::run();
?>