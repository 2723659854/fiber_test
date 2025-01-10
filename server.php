<?php
/** 以下是使用fiber协程创建的服务端 */
// 引入自动加载文件（假设通过Composer管理依赖）
require 'vendor/autoload.php';
// 引入Revolt\EventLoop命名空间
use Revolt\EventLoop;

// 创建服务器套接字资源，基于TCP协议监听在本地127.0.0.1的8888端口
$serverSocket = stream_socket_server("tcp://127.0.0.1:8888", $errno, $errstr);
if (!$serverSocket) {
    die("无法创建服务器套接字: $errstr ($errno)");
}
/** 设置为异步 */
stream_set_blocking($serverSocket, 0);
echo "=================================================\r\n";
/** 保存所有的客户端 */
$servers = [];
/** 客户端消息协程数组 */
$messageFibers = [];
// 当有新的客户端连接时的回调函数
$onConnect = function ($clientSocket) {
    echo "客户端发起连接\r\n";
    // 当从客户端接收到数据时的回调函数
    $onData = function () use($clientSocket){
        global $messageFibers;
        if (!isset($messageFibers[(int)$clientSocket])) {
            $messageFibers[(int)$clientSocket] = new Fiber(function () use ($clientSocket,$messageFibers) {
                /** 获取当前协程  */
                $fiber = Fiber::getCurrent();
                /** 唤醒协程 */
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                }
                global $servers;
                echo "接收到客户端数据\r\n";
                $data = fread($clientSocket, 1024);
                if ($data === false) {
                    // 如果读取数据出错，关闭客户端连接
                    EventLoop::cancel($servers[(int)$clientSocket]);
                    unset($messageFibers[(int)$clientSocket]);
                    return;
                }
                /** 如果读取的数据为空，则暂停当前协程 */
                if ($data === "") {
                    usleep(2);
                    $fiber->suspend();
                    return;
                }
                if (!is_resource($clientSocket)) {
                    // 连接已断开
                    EventLoop::cancel($servers[(int)$clientSocket]);
                    unset($messageFibers[(int)$clientSocket]);
                    return;
                }
                fwrite($clientSocket, $data . "\r");

                echo "发送数据给客户端完成\r\n";
            });
            $messageFibers[(int)$clientSocket]->start();
        }else{
            /** 如果协程已暂停，则唤醒协程 */
            if ($messageFibers[(int)$clientSocket]->isSuspended()){
                $messageFibers[(int)$clientSocket]->resume();
            }
        }
    };
    global $servers;
    // 为客户端套接字添加可读事件监听器，当有数据可读时触发$onData回调
    $servers[(int)$clientSocket] = EventLoop::onReadable($clientSocket, $onData);
};

// 为服务器套接字添加可读事件监听器，当有新客户端连接时触发$onConnect回调
EventLoop::onReadable($serverSocket, function () use ($serverSocket, $onConnect) {
    $clientSocket = stream_socket_accept($serverSocket);
    if ($clientSocket) {
        // 调用 $onConnect 回调函数处理新连接
        $fiber = new Fiber(function () use($clientSocket,$onConnect) {
            $onConnect($clientSocket);
        });
        $fiber->start();
    }
});

// 启动事件循环，驱动协程运行，处理各种事件
EventLoop::run();

?>