<?php

/**
 * 关闭客户端
 * @param $clientSocket
 * @return void
 */
function closeClient($clientSocket)
{
    global $writeFibers, $readFibers;
    /** 将客户端和协程从内存中移除 */
    unset($readFibers[(int)$clientSocket]);
    unset($writeFibers[(int)$clientSocket]);
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

/** 客户端可读协程组 */
$readFibers = [];
/** 客户端可写协程组 */
$writeFibers = [];
/** 保存服务端 */
$allSockets[(int)$serverSocket] = $serverSocket;

while(true){

    $read = $write = $allSockets;
    $except = [];
    $res = stream_select($read, $write, $except, 1);

    /** 处理读事件 */
    foreach ($read as $socket) {
        if ($socket == $serverSocket){
            $clientSocket = stream_socket_accept($serverSocket);
            $allSockets[(int)$clientSocket] = $clientSocket;
        }else{
           if (!isset($readFibers[(int)$socket])){
               $readFibers[(int)$socket] = new Fiber(function ()use($socket){
                   /** 获取当前协程  */
                   $fiber = Fiber::getCurrent();
                   /** 唤醒协程 */
                   if ($fiber->isSuspended()) {
                       $fiber->resume();
                   }
                   /** 为了保持协程存活，使用while循环 */
                   while (true) {
                       /** 每次读取3个字节 */
                       $data = fread($socket, 3);
                       /** 如果客户端已关闭，或者读取数据出错，则删除这个客户端的读写协程，并关闭客户端 */
                       if (($data === false) || (!is_resource($socket))) {
                           closeClient($socket);
                           return;
                       }
                       /** 如果有数据，则打印 */
                       if ($data) {
                           //todo  具体业务逻辑写在这里
                           echo $data . "\r\n";
                           var_dump("处理业务的时候，挂起协程");
                           //todo 这里任务如果是阻塞的，那么手动挂起协程，那么就需要手动判断业务是否阻塞，那么需要手动处理mysql客户端，http客户端
                           $fiber->suspend();
                       }else{
                           var_dump("不处理业务，挂起协程");
                           /** 读取一次后立即暂停当前协程，切换到其他协程 */
                           $fiber->suspend();
                       }
                   }
               });
               $readFibers[(int)$socket]->start();
           }else{
               if (($readFibers[(int)$socket])!= null) {
                   /** 如果协程已暂停，则唤醒协程 */
                   if ($readFibers[(int)$socket]->isSuspended()) {
                       $readFibers[(int)$socket]->resume();
                   }
                   /** 如果协程已死，则清理读写事件，并关闭客户端 */
                   if ($readFibers[(int)$socket]->isTerminated()) {
                       closeClient($socket);
                       return;
                   }
               }
           }
        }
    }

    /** 处理写事件 */
    foreach ($write as $socket) {
        if (!isset($writeFibers[(int)$socket])) {
            $writeFibers[(int)$socket] = new Fiber(function () use ($socket) {
                /** 获取当前协程  */
                $fiber = Fiber::getCurrent();
                /** 唤醒协程 */
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                }
                while (true) {
                    /** 客户端已关闭，则取消写事件*/
                    if (!is_resource($socket)) {
                        closeClient($socket);
                        return;
                    } else {
                        /** 向客户端发送数据，保持通信状态 */
                        $length = @fwrite($socket, '123');
                        /** 写入失败，说明对方客户端已死 */
                        if ($length === false) {
                            closeClient($socket);return;
                        }
                        /** 暂停当前协程 保证协程存活 */
                        $fiber->suspend();
                    }
                }
            });
            /** 启动写协程  */
            $writeFibers[(int)$socket]->start();
        }else{
            if (($writeFibers[(int)$socket]) != null){
                /** 唤醒写协程 */
                if ($writeFibers[(int)$socket]->isSuspended()) {
                    $writeFibers[(int)$socket]->resume();
                }
                /** 写协程已死，则关闭客户端的所有协程和客户端 */
                if ($writeFibers[(int)$socket]->isTerminated()) {
                    closeClient($socket);
                    return;
                }
            }

        }
    }
}