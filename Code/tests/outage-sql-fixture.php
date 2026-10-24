<?php
// Deliberately damage freshness in an owned, explicitly fictitious SQL fixture.
require_once __DIR__.'/../src/Bootstrap.php';FleetApi\Bootstrap::configureRuntime();
try{
 $p=FleetApi\StrictJson::decode(stream_get_contents(STDIN),true,32);$c=$p['connection'];
 if(PHP_SAPI!=='cli'||$c['host']!=='127.0.0.1'||!preg_match('/^suite_[a-f0-9]{16}$/D',$c['database'])||$p['fictitiousOnly']!==true)throw new RuntimeException('FICTITIOUS_LOCAL_FIXTURE_REQUIRED');
 $db=mysqli_init();if(!@$db->real_connect($c['host'],$c['username'],$c['password'],$c['database'],$c['port']))throw new RuntimeException('FIXTURE_CONNECT_FAILED');
 $r=$db->query('SELECT @@datadir AS directory')->fetch_assoc();if(strtolower(rtrim(str_replace('\\','/',$r['directory']),'/'))!==strtolower(str_replace('\\','/',$p['directory'].'/data')))throw new RuntimeException('OWN_FIXTURE_REQUIRED');
 $tenant=$p['tenant'];$s=$db->prepare("UPDATE api_suite_state SET captured_utc='2020-01-01 00:00:00',last_attempt_utc='2020-01-01 00:01:00',status='COLLECTING',in_flight=1 WHERE tenant_key=?");$s->bind_param('s',$tenant);if(!$s->execute()||$s->affected_rows!==1)throw new RuntimeException('FIXTURE_ROW_REQUIRED');$s->close();
 $s=$db->prepare("UPDATE api_suite_feeds SET last_present_utc='2020-01-01 00:00:00' WHERE tenant_key=?");$s->bind_param('s',$tenant);if(!$s->execute())throw new RuntimeException('FIXTURE_FEED_REQUIRED');$s->close();$db->close();echo '{"result":"PASS","realEaiCalls":0,"filesDeleted":0}';
}catch(Exception $e){echo json_encode(array('result'=>'FAIL','category'=>$e->getMessage()));exit(1);}
