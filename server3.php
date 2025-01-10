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
// 客户端可读消息协程数组（用于可读事件处理）
$readMessageFibers = [];
// 客户端可写消息协程数组（用于可写事件处理）
$writeMessageFibers = [];
// 客户端写缓冲数据数组（用于暂存要发送给客户端的数据）
$writeBuffers = [];

// 当有新的客户端连接时的回调函数
$onConnect = function ($clientSocket) use (&$writeBuffers) {
    // 初始化客户端写缓冲数据为空字符串
    $writeBuffers[(int) $clientSocket] = '';
    // 当从客户端接收到数据时的回调函数（处理可读事件）
    $onReadData = function () use ($clientSocket, &$readMessageFibers, &$writeBuffers) {
        global $servers;
        if (!isset($readMessageFibers[(int) $clientSocket])) {
            $readMessageFibers[(int) $clientSocket] = new Fiber(function () use ($clientSocket, &$readMessageFibers, &$writeBuffers, $servers) {
                /** 获取当前协程  */
                $fiber = Fiber::getCurrent();
                /** 唤醒协程 */
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                }
                $data = fread($clientSocket, 1024);
                if ($data === false) {
                    // 如果读取数据出错，关闭客户端连接，移除相关资源
                    EventLoop::cancel($servers[(int) $clientSocket]);
                    unset($readMessageFibers[(int) $clientSocket]);
                    unset($writeBuffers[(int) $clientSocket]);
                    if (isset($writeMessageFibers[(int) $clientSocket])) {
                        unset($writeMessageFibers[(int) $clientSocket]);
                    }
                    return;
                }
                /** 如果读取的数据为空，则暂停当前协程 */
                if ($data === "") {
                    usleep(2);
                    $fiber->suspend();
                    return;
                }
                if (!is_resource($clientSocket)) {
                    // 连接已断开，移除相关资源
                    EventLoop::cancel($servers[(int) $clientSocket]);
                    unset($readMessageFibers[(int) $clientSocket]);
                    unset($writeBuffers[(int) $clientSocket]);
                    if (isset($writeMessageFibers[(int) $clientSocket])) {
                        unset($writeMessageFibers[(int) $clientSocket]);
                    }
                    return;
                }
                echo $data;
                echo "\r\n";
                // 将接收到的数据添加到写缓冲数据中，准备后续发送给客户端
                $writeBuffers[(int) $clientSocket].= $data;
            });
            $readMessageFibers[(int) $clientSocket]->start();
        } else {
            /** 如果协程已暂停，则唤醒协程 */
            if ($readMessageFibers[(int) $clientSocket]->isSuspended()) {
                $readMessageFibers[(int) $clientSocket]->resume();
            }
        }
    };
    // 当客户端可写时的回调函数（处理可写事件）
    $onWriteData = function () use ($clientSocket, &$writeMessageFibers, &$writeBuffers) {
        global $servers;
        if (!isset($writeMessageFibers[(int) $clientSocket])) {
            $writeMessageFibers[(int) $clientSocket] = new Fiber(function () use ($clientSocket, &$writeMessageFibers, &$writeBuffers,$servers) {
                $fiber = Fiber::getCurrent();
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                }
                $writeFiber = $writeMessageFibers[(int) $clientSocket];
                // 尝试发送写缓冲数据中的数据
                $bytesWritten = fwrite($clientSocket, $writeBuffers[(int) $clientSocket]);
                if ($bytesWritten === false) {
                    // 如果写入数据出错，关闭客户端连接，移除相关资源
                    EventLoop::cancel($servers[(int) $clientSocket]);
                    unset($writeBuffers[(int) $clientSocket]);
                    unset($writeMessageFibers[(int) $clientSocket]);
                    return;
                }
                if ($bytesWritten === 0) {
                    // 如果写入字节数为0，表示暂时不可写，暂停等待下次可写事件
                    $writeFiber->suspend();
                    return;
                }
                // 从写缓冲数据中移除已经成功发送的数据
                $writeBuffers[(int) $clientSocket] = substr($writeBuffers[(int) $clientSocket], $bytesWritten);
                // 如果写缓冲数据为空，暂停写协程等待下次有数据写入
                if ($writeBuffers[(int) $clientSocket] === '') {
                    $writeFiber->suspend();
                }
            });
        }else{
            if ($writeMessageFibers[(int) $clientSocket]->isSuspended()) {
                $writeMessageFibers[(int) $clientSocket]->resume();
            }
        }


    };
    global $servers;
    // 为客户端套接字添加可读事件监听器，当有数据可读时触发$onReadData回调
    $servers[(int) $clientSocket] = EventLoop::onReadable($clientSocket, $onReadData);
    // 为客户端套接字添加可写事件监听器，当可写时触发$onWriteData回调
    $servers[(int) $clientSocket] = EventLoop::onWritable($clientSocket, $onWriteData);
};

// 为服务器套接字添加可读事件监听器，当有新客户端连接时触发$onConnect回调
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

// 启动事件循环，驱动协程运行，处理各种事件
EventLoop::run();
?>