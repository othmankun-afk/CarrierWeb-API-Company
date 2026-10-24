<?php
require_once __DIR__.'/../src/Bootstrap.php';
require_once __DIR__.'/../src/CompactStateJson.php';
FleetApi\Bootstrap::configureRuntime();mysqli_report(MYSQLI_REPORT_OFF);
function oldFixtureDates($value){if(!is_array($value))return is_string($value)&&preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',$value)?'2020-01-01 00:00:00':$value;$out=array();foreach($value as $k=>$v)$out[$k]=oldFixtureDates($v);return $out;}
try {
    $p=json_decode(stream_get_contents(STDIN),true);$c=$p['connection'];
    if(PHP_SAPI!=='cli'||$p['fictitiousOnly']!==true||strpos($p['tenant'],'fictitious-')!==0||!is_file($p['directory'].'/SYNC-FICTITIOUS-ONLY.json')||$c['host']!=='127.0.0.1')throw new RuntimeException('FICTITIOUS_ONLY');
    $db=mysqli_init();if(!@$db->real_connect($c['host'],$c['username'],$c['password'],$c['database'],$c['port']))throw new RuntimeException('FIXTURE_CONNECT_FAILED');
    $identity=$db->query('SELECT @@datadir AS directory')->fetch_assoc();
    if(strtolower(rtrim(str_replace('\\','/',$identity['directory']),'/'))!==strtolower(str_replace('\\','/',$p['directory'].'/data')))throw new RuntimeException('OWN_FIXTURE_ONLY');
    $tenant=$p['tenant'];$s=$db->prepare('SELECT bundle_json,bundle_sha256 FROM api_suite_state WHERE tenant_key=?');$s->bind_param('s',$tenant);$s->execute();$old=$s->get_result()->fetch_assoc();$s->close();
    if(!hash_equals($old['bundle_sha256'],hash('sha256',$old['bundle_json'])))throw new RuntimeException('FIXTURE_HASH_INVALID');
    $bundle=oldFixtureDates(json_decode(FleetApi\CompactStateJson::decode($old['bundle_json']),true));$json=FleetApi\CompactStateJson::encode(json_encode($bundle,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));$sha=hash('sha256',$json);
    $s=$db->prepare("UPDATE api_suite_state SET bundle_json=?,bundle_sha256=?,captured_utc='2020-01-01 00:00:00',last_attempt_utc='2020-01-01 00:01:00',status='FAULT_STATE_SOURCE_TIE_BOUND',in_flight=1 WHERE tenant_key=?");$s->bind_param('sss',$json,$sha,$tenant);if(!$s->execute()||$s->affected_rows!==1)throw new RuntimeException('FIXTURE_STATE_FAILED');$s->close();
    $s=$db->prepare('SELECT endpoint,record_id,payload_json FROM api_suite_history WHERE tenant_key=?');$s->bind_param('s',$tenant);$s->execute();$history=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    foreach($history as $event){$row=json_decode($event['payload_json'],true);$row['MeasuredTime']=$event['endpoint']==='canbus'?'01.01.2020 0:00:00':'2020-01-01 00:00:00';$bytes=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$digest=hash('sha256',$bytes);$s=$db->prepare("UPDATE api_suite_history SET measured_at='2020-01-01 00:00:00',payload_json=?,payload_sha256=? WHERE tenant_key=? AND endpoint=? AND record_id=?");$s->bind_param('sssss',$bytes,$digest,$tenant,$event['endpoint'],$event['record_id']);if(!$s->execute())throw new RuntimeException('FIXTURE_HISTORY_FAILED');$s->close();}
    $db->close();echo '{"result":"PASS","realEaiCalls":0}';
} catch(Exception $e){echo json_encode(array('result'=>'FAIL','category'=>$e->getMessage()));exit(1);}
