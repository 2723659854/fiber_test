<?php

/** 创建客户端 */
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
/** 循环创建客户端并保存 */
$allClients = [];
for($i=0;$i<10;$i++){
    $client = createClient();
    if ($client){
        $allClients[(int)$client] = $client;
    }
}

/** io轮训 */
while($allClients){
    $read = $write = $allClients;
    $except = [];
    stream_select($read, $write, $except, 1);
    foreach ($read as $stream) {
        $content = fread($stream, 1024);
        if ($content === false) {
            $fd = (int)$stream;
            fclose($stream);
            unset($allClients[$fd]);
            echo "1关闭客户端{$fd}\r\n";
        }
        echo $content . "\r\n";
        if ($content == 'close') {
            $fd = (int)$stream;
            fclose($stream);
            unset($allClients[$fd]);
            echo "2关闭客户端{$fd}\r\n";
        }
    }
    foreach ($write as $stream) {
        if (is_resource($stream)) {
            fwrite($stream, "你好，服务端\r\n");
        }
    }
    foreach ($except as $stream) {
        $fd = (int)$stream;
        fclose($stream);
        unset($allClients[$fd]);
        echo "3关闭客户端{$fd}\r\n";
    }
}