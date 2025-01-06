<?php

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
$message = "你好，服务端";
if (socket_write($clientSocket, $message) === false) {
    die('无法发送消息到服务器: '. socket_strerror(socket_last_error()));
}

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