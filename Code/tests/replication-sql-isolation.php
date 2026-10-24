<?php
require_once __DIR__.'/../src/Bootstrap.php';mysqli_report(MYSQLI_REPORT_OFF);
try{
    $p=json_decode(stream_get_contents(STDIN),true);$c=$p['connection'];$db=mysqli_init();$db->options(MYSQLI_OPT_CONNECT_TIMEOUT,3);
    if($c['host']!=='127.0.0.1'||!preg_match('/^suite_[a-f0-9]{16}$/D',$p['otherSchema']))throw new RuntimeException('FICTITIOUS_CONNECTION_REQUIRED');
    if(!@$db->real_connect($c['host'],$c['username'],$c['password'],$c['database'],$c['port']))throw new RuntimeException('FICTITIOUS_READER_CONNECT');
    $identity=$db->query('SELECT @@datadir AS directory,@@port AS port')->fetch_assoc();
    if(strtolower(rtrim(str_replace('\\','/',$identity['directory']),'/'))!==strtolower(str_replace('\\','/',$p['directory'].'/data'))||(int)$identity['port']!==$c['port'])throw new RuntimeException('OWN_FICTITIOUS_INSTANCE_REQUIRED');
    $own=$db->query('SELECT COUNT(*) AS rows_count FROM api_suite_snapshots');if(!$own||$own->fetch_assoc()['rows_count']!== '2')throw new RuntimeException('OWN_TWO_SNAPSHOTS_REQUIRED');
    if(@$db->query('UPDATE api_suite_state SET generation=999')!==false)throw new RuntimeException('READER_WRITE_NOT_DENIED');
    if(@$db->query('SELECT * FROM `'.$p['otherSchema'].'`.api_suite_state')!==false)throw new RuntimeException('CROSS_TENANT_READ_NOT_DENIED');
    $db->close();echo '{"result":"PASS","checks":3}';
}catch(Exception $e){echo json_encode(array('result'=>'FAIL','category'=>$e->getMessage()));exit(1);}
