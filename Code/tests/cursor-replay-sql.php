<?php
require_once __DIR__.'/../src/Bootstrap.php';
require_once __DIR__.'/../src/SuiteServiceStore.php';
FleetApi\Bootstrap::configureRuntime();mysqli_report(MYSQLI_REPORT_OFF);$checks=0;
function cq($db,$sql){$r=$db->query($sql);if($r===false)throw new RuntimeException('FICTITIOUS_SQL_FAILED');return $r;}
function ck($v,$name){global $checks;if(!$v)throw new RuntimeException($name);$checks++;}
function rejectCursor($fn,$expected){try{$fn();}catch(FleetApi\ApiException $e){ck($e->getMessage()===$expected,'EXPECTED_'.$expected);return;}throw new RuntimeException('UNEXPECTED_ACCEPTANCE');}
function dateFixture($v,$stamp){if(!is_array($v))return is_string($v)&&preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',$v)?$stamp:$v;$r=array();foreach($v as $k=>$x)$r[$k]=dateFixture($x,$stamp);return $r;}
function tenantDigest($db,$tenant){$all=array();foreach(array('api_suite_state','api_suite_snapshots','api_suite_feeds','api_suite_history') as $table)$all[$table]=cq($db,"SELECT * FROM ".$table." WHERE tenant_key='".$tenant."' ORDER BY 1,2")->fetch_all(MYSQLI_ASSOC);return hash('sha256',json_encode($all));}
try{
 $p=json_decode(stream_get_contents(STDIN),true);$c=$p['connection'];
 if(PHP_SAPI!=='cli'||$c['host']!=='127.0.0.1'||!is_file($p['directory'].'/FICTITIOUS-CURSOR-ONLY.json')||strpos(basename($p['directory']),'cursor-native-')!==0)throw new RuntimeException('OWN_FICTITIOUS_DATADIR_REQUIRED');
 $db=mysqli_init();$db->options(MYSQLI_OPT_CONNECT_TIMEOUT,2);if(!@$db->real_connect('127.0.0.1',$c['username'],$c['password'],null,$c['port']))throw new RuntimeException('FIXTURE_SQL_NOT_READY');
 $id=cq($db,'SELECT @@datadir AS directory,@@port AS port')->fetch_assoc();if(strtolower(rtrim(str_replace('\\','/',$id['directory']),'/'))!==strtolower(str_replace('\\','/',$p['directory'].'/data'))||(int)$id['port']!==$c['port'])throw new RuntimeException('OWN_FICTITIOUS_SQL_REQUIRED');
 if($p['action']==='ready'){echo '{"result":"PASS"}';exit;}
 if($p['action']==='bootstrap'){cq($db,"ALTER USER 'root'@'localhost' IDENTIFIED BY '".$db->real_escape_string($p['rootPassword'])."'");echo '{"result":"PASS"}';exit;}
 if($p['action']==='shutdown'){cq($db,'SHUTDOWN');echo '{"result":"PASS"}';exit;}
 if($p['action']!=='test'||!preg_match('/^suite_[a-f0-9]{16}$/D',$p['schema']))throw new RuntimeException('FIXTURE_SCHEMA_REQUIRED');
 $schema=$p['schema'];cq($db,'CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4');$db->select_db($schema);if(!$db->multi_query(file_get_contents(__DIR__.'/../database/suite_live.sql')))throw new RuntimeException('FIXTURE_SCHEMA_CREATE');while($db->more_results())if(!$db->next_result())throw new RuntimeException('FIXTURE_SCHEMA_CREATE');
 $tenant='fictitious-cursor';$capture=$p['capture'];$profile=$p['profile'];ck($capture['tenant']===$tenant&&$profile['customer']===$tenant,'SYNTHETIC_TENANT_ONLY');
 $capture['bundle']['feeds']['positions'][0]['Zipcode']='';$capture['bundle']['feeds']['canbushistory'][0]['DriverNumber']='';
 FleetApi\SuiteServiceStore::initialize($db,$tenant);FleetApi\SuiteServiceStore::dispatch($db,$tenant,60);FleetApi\SuiteServiceStore::accept($db,$tenant,$capture,$profile);
 $other=$capture;$other['tenant']='fictitious-other';$other['bundle']['tenant']='fictitious-other';FleetApi\SuiteServiceStore::initialize($db,'fictitious-other');FleetApi\SuiteServiceStore::dispatch($db,'fictitious-other',60);FleetApi\SuiteServiceStore::accept($db,'fictitious-other',$other,$p['otherProfile']);$otherDigest=tenantDigest($db,'fictitious-other');
 $stored=FleetApi\SuiteServiceStore::row($db,$tenant);$bundle=dateFixture(json_decode(FleetApi\CompactStateJson::decode($stored['bundle_json']),true),'2020-01-01 00:00:00');$encoded=FleetApi\CompactStateJson::encode(json_encode($bundle));
 $prior=array('positions'=>array('0'=>'1'),'canbushistory'=>'1','telemetry'=>array('0'=>'0','20260101'=>'202601019999999','20260102'=>'202601020000123'),'wiredtelemetry'=>array('0'=>'0'));
 $s=$db->prepare("UPDATE api_suite_state SET bundle_json=?,bundle_sha256=?,cursors_json=?,captured_utc='2020-01-01 00:00:00',last_attempt_utc='2020-01-01 00:01:00',status='FAULT_SUITE_CURSOR_REGRESSED',in_flight=1 WHERE tenant_key=?");$hash=hash('sha256',$encoded);$cursorJson=json_encode($prior);$s->bind_param('ssss',$encoded,$hash,$cursorJson,$tenant);if(!$s->execute())throw new RuntimeException('FIXTURE_STATE_UPDATE');$s->close();
 $incoming=dateFixture($capture,'2020-01-01 00:01:30');$incoming['bundle']['capturedUtc']='2020-01-01 00:02:00';foreach(array('positions','canbushistory','telemetry') as $part)$incoming['bundle']['feeds'][$part][0]['ID'].='1';
 $incoming['cursors']=$prior;$incoming['cursors']['positions']['0']='2';$incoming['cursors']['canbushistory']='2';$incoming['cursors']['telemetry']['20260102']='202601010000456';
 $state=FleetApi\SuiteServiceStore::state($db,$tenant);$review=array('expectedGeneration'=>$state['generation'],'expectedStatus'=>$state['status'],'expectedLastAttemptUtc'=>$state['lastAttemptUtc'],'reason'=>'LOCAL_PENDING_CAPTURE_REPLAY');$before=tenantDigest($db,$tenant);
 rejectCursor(function()use($db,$tenant,$incoming,$profile){FleetApi\SuiteServiceStore::accept($db,$tenant,$incoming,$profile);},'source_stale');
 $fresh=$incoming;$fresh['bundle']['capturedUtc']=gmdate('Y-m-d H:i:s');rejectCursor(function()use($db,$tenant,$fresh,$profile){FleetApi\SuiteServiceStore::accept($db,$tenant,$fresh,$profile);},'suite_cursor_regressed');
 $bad=$review;$bad['expectedGeneration']+=1;rejectCursor(function()use($db,$tenant,$incoming,$profile,$bad){FleetApi\SuiteServiceStore::accept($db,$tenant,$incoming,$profile,null,$bad);},'suite_local_replay_state_changed');
 $lost=$incoming;unset($lost['cursors']['telemetry']['20260101']);rejectCursor(function()use($db,$tenant,$lost,$profile,$review){FleetApi\SuiteServiceStore::accept($db,$tenant,$lost,$profile,null,$review);},'suite_cursor_lost');
 ck(tenantDigest($db,$tenant)===$before,'REFUSED_REPLAYS_CHANGED_NOTHING');
 $accepted=FleetApi\SuiteServiceStore::accept($db,$tenant,$incoming,$profile,null,$review);ck($accepted['cursorMetadataReviewApplied']===true,'EXPLICIT_CURSOR_REVIEW_RECORDED');
 $after=FleetApi\SuiteServiceStore::state($db,$tenant);ck($after['generation']===$state['generation']+1&&!$after['inFlight'],'ONE_PUBLICATION_ONLY');ck($after['capturedUtc']==='2020-01-01 00:02:00','OLD_CAPTURE_TIME_NOT_RELABELLED');ck($after['cursors']['telemetry']===$prior['telemetry'],'HIGH_WATERS_PRESERVED');ck($after['cursors']['positions']['0']==='2'&&$after['cursors']['canbushistory']==='2','RECEIVED_ADVANCES_RETAINED');
 ck((int)cq($db,"SELECT COUNT(*) AS n FROM api_suite_history WHERE tenant_key='fictitious-cursor'")->fetch_assoc()['n']===6,'THREE_OLD_AND_THREE_NEW_EVENTS_PRESENT');ck(tenantDigest($db,'fictitious-other')===$otherDigest,'OTHER_TENANT_UNCHANGED');
 rejectCursor(function()use($db,$tenant,$incoming,$profile,$review){FleetApi\SuiteServiceStore::accept($db,$tenant,$incoming,$profile,null,$review);},'suite_dispatch_required');
 $reader='cursor_reader_'.substr($schema,-5);cq($db,"CREATE USER '".$reader."'@'127.0.0.1' IDENTIFIED BY '".$p['readerPassword']."'");cq($db,"GRANT SELECT ON `".$schema."`.* TO '".$reader."'@'127.0.0.1'");
 $connection=array('host'=>'127.0.0.1','port'=>$c['port'],'database'=>$schema,'username'=>$reader,'password'=>$p['readerPassword'],'queryTimeoutMs'=>3000,'maxSnapshotAgeSeconds'=>300,'staleReadPolicy'=>'last-known-with-warning');
 $repo=new FleetApi\ConfiguredRepository(array('driver'=>'eai-suite-live-mysqli','profile'=>'eai-suite-live-v1','tenants'=>array($tenant=>$connection)));$beforeRead=tenantDigest($db,$tenant);
 $rows=$repo->read($tenant,'positions',(string)$capture['bundle']['feeds']['vehicles'][0]['CWVehicleID'],'2020-01-01 00:00:00','2020-01-02 00:00:00',1000);ck(count($rows)===1&&$rows[0]['Zipcode']===' ','CONFIGURED_READER_APPLIES_SDK_TEXT');
 ck(tenantDigest($db,$tenant)===$beforeRead,'READ_COMPATIBILITY_CHANGED_NO_STORED_PAYLOAD_OR_HASH');
 ck($repo->publicationMetadata()['publicationFreshness']==='STALE','REPLAY_REMAINS_HONESTLY_STALE');
 $db->close();echo json_encode(array('result'=>'PASS','suite'=>'native_sql_cursor_replay_and_text','checks'=>$checks,'php'=>PHP_VERSION,'eaiCalls'=>0,'realCustomerDatabasesOpened'=>0,'allFixturesRetained'=>true,'filesDeleted'=>0,'historyDeleted'=>false));
}catch(Exception $e){echo json_encode(array('result'=>'FAIL','category'=>$e->getMessage(),'checks'=>$checks));exit(1);}
