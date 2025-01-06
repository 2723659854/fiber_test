<?php

// 创建服务器套接字
$serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($serverSocket === false) {
    die('无法创建服务器套接字: '. socket_strerror(socket_last_error()));
}

// 绑定地址和端口
$serverAddress = '127.0.0.1';
$serverPort = 8888;
if (socket_bind($serverSocket, $serverAddress, $serverPort) === false) {
    die('无法绑定服务器套接字: '. socket_strerror(socket_last_error()));
}

// 监听端口
if (socket_listen($serverSocket, 5) === false) {
    die('无法监听端口: '. socket_strerror(socket_last_error()));
}
echo "已监听 tcp://".$serverAddress.':'. $serverPort."\r\n";
// 存储客户端套接字的数组
$clientSockets = array($serverSocket);

while (true) {
    // 使用select模型，等待可读、可写或异常事件
    $readSockets = $clientSockets;
    $writeSockets = $clientSockets;
    $exceptSockets = null;
    $numChangedSockets = socket_select($readSockets, $writeSockets, $exceptSockets, null);
    if ($numChangedSockets === false) {
        die('select 出错: '. socket_strerror(socket_last_error()));
    }

    // 遍历可读套接字
    foreach ($readSockets as $readSocket) {
        if ($readSocket === $serverSocket) {
            // 有新的客户端连接
            $newClientSocket = socket_accept($serverSocket);
            if ($newClientSocket === false) {
                die('无法接受新客户端连接: '. socket_strerror(socket_last_error()));
            }
            $clientSockets[] = $newClientSocket;

            if (socket_getpeername($newClientSocket, $address,$port)){
                echo "客户端ip ：".$address.':'.$port."\r\n";
            }

        } else {
            // 接收客户端发送的数据
            $data = socket_read($readSocket, 1024);
            if ($data === false) {
                // 读取数据出错，关闭客户端连接
                socket_close($readSocket);
                $key = array_search($readSocket, $clientSockets);
                unset($clientSockets[$key]);
                continue;
            }
            if ($data === "") {
                // 客户端关闭连接
                socket_close($readSocket);
                $key = array_search($readSocket, $clientSockets);
                unset($clientSockets[$key]);
                continue;
            }
            // 处理客户端数据，这里简单回显
            $response = "收到你的消息: ". $data;
            socket_write($readSocket, $response);
        }
    }
}

// 关闭服务器套接字
socket_close($serverSocket);