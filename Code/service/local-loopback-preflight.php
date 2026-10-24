<?php
// A local challenge only. Fail BEFORE creating a MySQL datadir/process.
try{
    $p=json_decode(stream_get_contents(STDIN,4096),true);
    if(PHP_SAPI!=='cli'||!isset($p['port'],$p['challenge'])||!is_int($p['port'])||$p['port']<1024||$p['port']>65535||!preg_match('/^[a-f0-9]{64}$/D',$p['challenge']))throw new RuntimeException('LOCAL_PREFLIGHT_INPUT');
    $socket=@stream_socket_client('tcp://127.0.0.1:'.$p['port'],$errno,$error,2);
    if(!$socket)throw new RuntimeException('LOCAL_LOOPBACK_PERMISSION_REQUIRED');stream_set_timeout($socket,2);$actual=stream_get_contents($socket,65);fclose($socket);
    if($actual!==$p['challenge'])throw new RuntimeException('LOCAL_PREFLIGHT_IDENTITY');
    echo '{"result":"PASS","localOnly":true,"eaiCalls":0}';
}catch(Exception $e){echo json_encode(array('result'=>'FAIL','category'=>$e->getMessage()));exit(1);}
