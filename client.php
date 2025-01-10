<?php

/** 创建客户端并向服务端发送消息 */
function sendMessage(int $i)
{
    // 创建客户端套接字
    $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($clientSocket === false) {
        die('无法创建客户端套接字: '. socket_strerror(socket_last_error()));
    }
    // 连接到服务器
    $serverAddress = '127.0.0.1';
    $serverPort = 8888;
    if (socket_connect($clientSocket, $serverAddress, $serverPort) === false) {
        die('无法连接到服务器: '. socket_strerror(socket_last_error()));
    }
    // 发送数据到服务器
    $message = "你好，服务端{$i}\r\n";
    if (socket_write($clientSocket, $message) === false) {
        die('无法发送消息到服务器: '. socket_strerror(socket_last_error()));
    }
    echo "发送消息完成{$i}\r\n";
    // 接收服务器返回的响应
    $response = socket_read($clientSocket, 1024);
    if ($response === false) {
        die('无法接收服务器响应: '. socket_strerror(socket_last_error()));
    }

    // 输出服务器响应
    echo $response;
    echo "\r\n";

    // 关闭客户端套接字
    socket_close($clientSocket);
}

/** 所有的客户端 */
$clients = [];

/** 创建100个客户端 */
for ($i = 0; $i < 100; $i++) {
    $clients[$i] = new Fiber(function ()use($i){
        sendMessage($i);
    });
}
/** 100个客户端同时启动 */
for ($i = 0; $i < 100; $i++) {
    $clients[$i]->start();
}