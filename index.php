<?php // using EventLoop::defer()
require_once __DIR__ . '/vendor/autoload.php';


use Revolt\EventLoop;

// 创建一个TCP服务器套接字
$serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($serverSocket === false) {
    die('无法创建服务器套接字: '. socket_strerror(socket_last_error()));
}

// 绑定服务器套接字到指定地址和端口
$address = '0.0.0.0';  // 这里替换为你想要绑定的实际IP地址，如果想监听所有可用IP，可使用 '0.0.0.0'
$port = 8888;  // 自定义的监听端口
if (socket_bind($serverSocket, $address, $port) === false) {
    die('无法绑定服务器套接字: '. socket_strerror(socket_last_error()));
}

// 开始监听端口，设置最大等待连接数
if (socket_listen($serverSocket, 5) === false) {
    die('无法监听端口: '. socket_strerror(socket_last_error()));
}

// 当有新的客户端连接时的回调函数
$onConnect = function ($clientSocket) {
    // 当从客户端接收到数据时的回调函数
    $onData = function ($clientSocket) {
        $data = socket_read($clientSocket, 1024);
        if ($data === false) {
            // 如果读取数据出错，关闭客户端连接
            socket_close($clientSocket);
            return;
        }
        if ($data === "") {
            // 如果客户端关闭连接，也关闭对应的客户端套接字
            socket_close($clientSocket);
            return;
        }
        // 将接收到的数据回显给客户端
        socket_write($clientSocket, $data);
    };

    // 为客户端套接字添加可读事件监听器，当有数据可读时触发$onData回调
    EventLoop::onReadable($clientSocket, $onData);
};

// 为服务器套接字添加可读事件监听器，当有新客户端连接时触发$onConnect回调
EventLoop::onReadable($serverSocket, $onConnect);

// 启动事件循环，让服务器持续运行，处理各种事件
EventLoop::run();

// 关闭服务器套接字（正常情况下，执行到这里意味着要关闭服务器了）
socket_close($serverSocket);