<?php

// 创建客户端
function createClient()
{
    $client = stream_socket_client("tcp://127.0.0.1:8888", $errno, $errstr);
    if ($errno) {
        echo "$errstr ($errno)";
        return false;
    }
    stream_set_blocking($client, 0);
    return $client;
}

// 循环创建客户端并保存
$allClients = [];
for ($i = 0; $i < 10; $i++) {
    $client = createClient();
    if ($client) {
        $allClients[(int)$client] = $client;
    }
}

// 分别用于存储读协程对象和写协程对象的数组，键为客户端套接字对应的整数表示
$readClientFibers = [];
$writeClientFibers = [];

// 监测所有客户端读写状态
while (true) {
    if (empty($allClients)){
        var_dump("没有客户端了");
        break;
    }
    $read =   $write = $allClients;
    $except = [];

    stream_select($read, $write, $except, 1);

    // 处理可读的连接
    foreach ($read as $stream) {
        $fd = (int)$stream;
        if (!isset($readClientFibers[$fd])) {
            // 如果还没有为该客户端创建读协程，则创建一个协程用于读取数据
            $readClientFibers[$fd] = new Fiber(function () use ($stream, &$allClients, &$readClientFibers) {
                $fiber = Fiber::getCurrent();
                /** 如果当前协程暂停，则唤醒 */
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                }
                $content = fread($stream, 1024);
                if ($content === false) {
                    // 如果读取数据出错，关闭客户端连接，移除相关记录
                    fclose($stream);
                    unset($allClients[$fd = (int)$stream]);
                    unset($readClientFibers[$fd]);
                    echo "1关闭客户端{$fd}\r\n";
                    return;
                }
                if ($content === "") {
                    // 如果暂时没读到数据，暂停当前协程
                    $fiber->suspend();
                    sleep(1);
                }
                $content = trim($content, "\r");
                echo "server:{$content}";
                echo "\r\n";
                if ($content == 'close') {
                    // 如果读取数据出错，关闭客户端连接，移除相关记录
                    fclose($stream);
                    unset($allClients[$fd = (int)$stream]);
                    unset($readClientFibers[$fd]);
                    echo "2关闭客户端{$fd}\r\n";
                    return;
                }
            });
            $readClientFibers[$fd]->start();
        } else {
            // 如果读协程已存在，恢复读协程执行
            $fiber = $readClientFibers[$fd];
            if ($fiber->isSuspended()) {
                $fiber->resume();
            }
        }
    }

    // 处理可写的连接
    foreach ($write as $stream) {
        $fd = (int)$stream;
        global $writeClientFibers;
        if (!isset($writeClientFibers[$fd])) {
            // 如果还没有为该客户端创建写协程，创建一个协程用于发送数据
            $writeClientFibers[$fd] = new Fiber(function () use ($stream, $fd, $writeClientFibers) {

                $fiber = Fiber::getCurrent();
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                }
                /** 协程客户端不死 */
                while(true){
                    $string = date('Y-m-d H:i:s')." 你好，服务端{$fd}\r\n";
                    fwrite($stream, $string);
                    echo "client: ".$string;
                    // 发送完后，暂停当前协程
                    //usleep(1000000);
                    $fiber->suspend();
                    sleep(1);
                }

            });
            $writeClientFibers[$fd]->start();

        } else {
            // 如果写协程已存在，恢复写协程执行
            $fiber =  $writeClientFibers[$fd] ;
            if ($fiber->isSuspended()) {
                echo "1唤醒写协程{$fd}\r\n";
                $res = $fiber->resume();

            }
            if ($fiber->isTerminated()){
                echo "{$fd}协程已结束\r\n";
            }
        }
    }

    foreach ($except as $stream) {
        $fd = (int)$stream;
        fclose($stream);
        unset($allClients[$fd]);
        unset($readClientFibers[$fd]);
        unset($writeClientFibers[$fd]);
        echo "3关闭客户端{$fd}\r\n";
    }
}