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
echo "=================================================\r\n";
/** 保存所有的客户端 */
$servers = [];
// 当有新的客户端连接时的回调函数
$onConnect = function ($clientSocket) {
    echo "客户端发起连接\r\n";
    // 当从客户端接收到数据时的回调函数
    $onData = function () use($clientSocket){

        $fiber = new Fiber(function ()use($clientSocket){
            global $servers;
            echo "接收到客户端数据\r\n";
            $data = fread($clientSocket, 1024);
            if ($data === false) {
                // 如果读取数据出错，关闭客户端连接
                //fclose($clientSocket);
                EventLoop::cancel($servers[(int)$clientSocket]);
                return;
            }
            if ($data === "") {
                // 如果客户端关闭连接，也关闭对应的客户端套接字
                EventLoop::cancel($servers[(int)$clientSocket]);
                return;
            }
            if (!is_resource($clientSocket)) {
                EventLoop::cancel($servers[(int)$clientSocket]);
                return;
            }
            // 处理客户端数据，这里简单回显
            $response = "收到你的消息: " . $data;
            echo $response."\r\n";
            usleep(1000000);
            fwrite($clientSocket, $response);
        });
        $fiber->start();

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
        //todo 创建协程来处理

        $fiber = new Fiber(function () use($clientSocket,$onConnect) {
            $onConnect($clientSocket);
        });
        $fiber->start();
    }
});

// 启动事件循环，驱动协程运行，处理各种事件
EventLoop::run();

?>