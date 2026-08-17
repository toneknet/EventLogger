<?php
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/';
$decoded=rawurldecode($path);
if(preg_match('~(?:^|/)\.|^/data(?:/|$)|\.(?:sqlite|sqlite-wal|sqlite-shm|db)$~i',$decoded)){
    http_response_code(404);exit('Not found');
}
$file=__DIR__.$decoded;
if($decoded!=='/'&&is_file($file))return false;
if($decoded==='/'||$decoded==='/index.php'){require __DIR__.'/index.php';return true;}
http_response_code(404);echo 'Not found';return true;
