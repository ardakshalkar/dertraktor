#!/usr/local/bin/php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
$channel = $connection->channel();
function schedule($images_file){
    global $channel;
    global $connection;
    $channel->queue_declare('download4', false, false, false, false);
    $channel->queue_declare('failed', false, false, false, false);
    $myfile = fopen($images_file, "r") or die("Unable to open file!");
    $images = [];
    while(!feof($myfile)) {
        $image = trim(fgets($myfile));
        $parsed = parse_url($image);
        if (strlen($image)>0){
            if (($parsed["scheme"] == "http" || $parsed["scheme"] == "https") && filter_var($image, FILTER_VALIDATE_URL) !== FALSE){
                $msg = new AMQPMessage($image);
                $channel->basic_publish($msg, '', 'download4');
                echo " [x] Sent '$image' to download\n";
            } else{
                $msg = new AMQPMessage($image);
                $channel->basic_publish($msg, '', 'failed');
                echo " [x] Sent '$image' to failed\n";
            }
        }
    }
    fclose($myfile);
    $channel->close();
    $connection->close();
}
function download(){
    global $channel;
    global $connection;
    $channel->queue_declare('download4', false, false, false, false);
    $channel->queue_declare('done', false, false, false, false);
    $channel->queue_declare('failed', false, false, false, false);
    echo ' [*] Waiting for messages. To exit press CTRL+C', "\n";
    $callback = function($msg){
        downloadImage($msg);
    };
    if (!file_exists("images")) {
        mkdir("images");
    }
    $channel->basic_consume('download4', '', false, true, false, false, $callback);
    while(count($channel->callbacks)) {
        $channel->wait();
    }
    $channel->close();
    $connection->close();
}
function downloadImage($msg){
    global $channel;
    global $connection;
    $url  = $msg->body;
    echo "Downloading URL :".$url."\n";
    $parsed = parse_url($url);
    $headers = @get_headers($url);
    if ($headers && ($parsed["scheme"] == "http" || $parsed["scheme"] == "https")){
        $channel->basic_publish(new AMQPMessage($url), '', 'done');
        $ch = curl_init($url);
        $filename = "images/".fileNewName("images",basename($parsed["path"]));
        $fp = fopen($filename, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        $result = curl_exec($ch);
        curl_close($ch);
        fclose($fp);
    } else{
        echo "[!] Failed: ".$url;
        $channel->basic_publish(new AMQPMessage($url), '', 'failed');    
    }
}
function fileNewName($path, $filename){
    if ($pos = strrpos($filename, '.')) {
           $name = substr($filename, 0, $pos);
           $ext = substr($filename, $pos);
    } else {
           $name = $filename;
    }

    $newpath = $path.'/'.$filename;
    $newname = $filename;
    $counter = 0;
    while (file_exists($newpath)) {
           $newname = $name .'_'. $counter . $ext;
           $newpath = $path.'/'.$newname;
           $counter++;
     }

    return $newname;
}
$command = $argv[1];
if (count($argv)>2)
    $data = $argv[2];
if ($command == "schedule")
    schedule($data);
elseif ($command == "download")
    download();
else
    echo "error!!!";
echo "\n";
