<?php
// Synthetic own-server fixture only. Never accepts a live installation plan.
require_once __DIR__.'/../src/Bootstrap.php';
require_once __DIR__.'/../demo/fixtures.php';
FleetApi\Bootstrap::configureRuntime();
mysqli_report(MYSQLI_REPORT_OFF);
set_error_handler(function($n,$m){if(error_reporting()&$n)throw new ErrorException('FIXTURE_WARNING',0,$n);return true;});
function checkRecovery($ok,$label){if(!$ok)throw new RuntimeException($label);}
function recoveryQuery($db,$sql){$result=$db->query($sql);if($result===false)throw new RuntimeException('FIXTURE_QUERY_FAILED');return $result;}
function recoverySnapshot($db){
    $tables=array('api_suite_state','api_suite_snapshots','api_suite_feeds','api_suite_history','api_suite_backfill_state','fixture_recovery_probe');
    $summary=array();$allRows=array();$allTypes=array();
    foreach($tables as $table){
        $columns=recoveryQuery($db,'SHOW FULL COLUMNS FROM `'.$table.'`')->fetch_all(MYSQLI_ASSOC);
        $rows=recoveryQuery($db,'SELECT * FROM `'.$table.'`')->fetch_all(MYSQLI_ASSOC);
        // Binary cells are hashed as bytes, not lossy JSON encodings. Sorting
        // serialized rows makes the comparison independent of physical order.
        $encoded=array();foreach($rows as $row)$encoded[]=serialize($row);sort($encoded,SORT_STRING);
        $allRows[$table]=hash('sha256',implode("\n",$encoded));
        $allTypes[$table]=hash('sha256',serialize($columns));
        $summary[$table]=array('rows'=>count($rows),'columns'=>count($columns),'rowsSha256'=>$allRows[$table],'columnsSha256'=>$allTypes[$table]);
    }
    $state=FleetApi\SuiteServiceStore::state($db,'synthetic_recovery');
    $facade=new FleetApi\MysqliDatabase($db);$facade->beginRead();$responses=array();
    try{
        foreach(array('vehicles','vehiclesBase','positions','canbus','telemetry') as $endpoint){
            $repository=new FleetApi\SuiteLiveRepository($facade,'synthetic_recovery',3600);
            $history=FleetApi\Contract::history($endpoint);
            $rows=$repository->read('synthetic_recovery',$endpoint,$history?'7':null,'2000-01-01 00:00:00','2099-01-01 00:00:00',20000);
            $responses[$endpoint]=array('rows'=>count($rows),'typedRowsSha256'=>hash('sha256',serialize($rows)));
        }$facade->commit();
    }catch(Exception $e){$facade->rollback();throw $e;}
    return array('tables'=>$summary,'allRowsSha256'=>hash('sha256',serialize($allRows)),'allTypesSha256'=>hash('sha256',serialize($allTypes)),'state'=>$state,'repositoryResponses'=>$responses);
}
function recoveryPopulate($db){
    checkRecovery($db->multi_query(file_get_contents(__DIR__.'/../database/suite_live.sql')),'FIXTURE_SCHEMA_FAILED');
    while($db->more_results())checkRecovery($db->next_result(),'FIXTURE_SCHEMA_FAILED');
    recoveryQuery($db,'CREATE TABLE fixture_recovery_probe (id INT PRIMARY KEY,big_number BIGINT UNSIGNED NOT NULL,precise_number DECIMAL(24,7) NOT NULL,nullable_text TEXT NULL,unicode_text VARCHAR(120) NOT NULL,binary_bytes BLOB NOT NULL) ENGINE=InnoDB');
    recoveryQuery($db,"INSERT INTO fixture_recovery_probe VALUES (1,18446744073709551615,12345678901234567.1234567,NULL,'FICTITIOUS café 温度',0x0001275CFF10)");
    $stamp=gmdate('Y-m-d H:i:s',time()-5);$all=fleet_demo_rows();$vehicle=$all['vehicles'][0];
    foreach(FleetApi\EaiVehicleMapping::derivedFields() as $f)unset($vehicle[$f]);
    $vehicle['Speed']='42';$vehicle['ZipCode']=' ';$vehicle['LastLocation']='FICTITIOUS RECOVERY PLACE';unset($vehicle['CurrentSpeed'],$vehicle['Zipcode']);
    foreach($vehicle as $f=>$v)if(is_int($v)||is_float($v))$vehicle[$f]=sprintf('%.0F',$v);
    $vehicle['Latitude']='1234567';$vehicle['Longitude']='2345678';$vehicle['DivisionID']='0';
    foreach(array('LastLocationTime','LastReportTime','HOSActivityTime','LastCanbusTime','FuelLevelTime') as $f)$vehicle[$f]=$stamp;
    $feeds=array('vehicles'=>array($vehicle),'divisions'=>array(),'wiredtelemetry'=>array());
    foreach(array('positions','canbus','telemetry') as $ep){
        $rows=array();foreach($all[$ep] as $row){
            $row['MeasuredTime']=$stamp;foreach($row as $f=>$v)if(is_int($v)||is_float($v))$row[$f]=sprintf('%.0F',$v);
            if($ep==='positions'){$row['Latitude']='1234567';$row['Longitude']='2345678';}$rows[]=$row;
        }$feeds[$ep==='canbus'?'canbushistory':$ep]=$rows;
    }
    $profile=json_decode(file_get_contents(__DIR__.'/recovery-profile.fictitious.json'),true);$profile['customer']='synthetic_recovery';
    foreach($profile['ruleOverrides'] as &$rule)if(isset($rule['tenant']))$rule['tenant']='synthetic_recovery';unset($rule);
    $capture=array('result'=>'PASS','tenant'=>'synthetic_recovery','transportAttempts'=>1,'serverWrites'=>0,'nativeSha256'=>str_repeat('c',64),'bundle'=>array('tenant'=>'synthetic_recovery','timeBasis'=>'UTC','capturedUtc'=>$stamp,'feeds'=>$feeds),'cursors'=>array('positions'=>array('0'=>'100'),'telemetry'=>array('0'=>'100'),'canbushistory'=>'100','wiredtelemetry'=>array('0'=>'0')));
    FleetApi\SuiteServiceStore::initialize($db,'synthetic_recovery');
    FleetApi\SuiteServiceStore::dispatch($db,'synthetic_recovery',60);
    $published=FleetApi\SuiteServiceStore::accept($db,'synthetic_recovery',$capture,$profile);
    checkRecovery($published['generation']===1,'FIXTURE_FIRST_PUBLICATION_FAILED');
    // Enlarge only the synthetic fixture with valid already-mapped event
    // templates: 3 endpoints * 3334 rows = 10002 rows in this backup.
    $templates=recoveryQuery($db,'SELECT endpoint,payload_json FROM api_suite_history ORDER BY endpoint,record_id')->fetch_all(MYSQLI_ASSOC);
    $first=array();foreach($templates as $template)if(!isset($first[$template['endpoint']]))$first[$template['endpoint']]=$template['payload_json'];
    checkRecovery($db->begin_transaction(),'FIXTURE_BULK_TRANSACTION_FAILED');
    $insert=$db->prepare('INSERT INTO api_suite_history(tenant_key,endpoint,record_id,vehicle_id,measured_at,payload_json,payload_sha256) VALUES(?,?,?,?,?,?,?)');
    checkRecovery($insert!==false,'FIXTURE_BULK_PREPARE_FAILED');
    $tenant='synthetic_recovery';$vehicleId='7';$recordId='';$endpoint='';$measured='';$json='';$hash='';
    checkRecovery($insert->bind_param('sssssss',$tenant,$endpoint,$recordId,$vehicleId,$measured,$json,$hash),'FIXTURE_BULK_BIND_FAILED');
    $endpointIndex=0;
    foreach($first as $endpoint=>$template){
        $row=FleetApi\StrictJson::decode($template,true,16);
        for($index=0;$index<3331;$index++){
            $recordId=(string)(10000000+$endpointIndex*100000+$index);$measured=gmdate('Y-m-d H:i:s',strtotime($stamp.' UTC')-3331+$index);
            $row['ID']=$recordId;$row['MeasuredTime']=$endpoint==='canbus'?gmdate('d.m.Y G:i:s',strtotime($measured.' UTC')):$measured;
            $json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$hash=hash('sha256',$json);
            checkRecovery($insert->execute(),'FIXTURE_BULK_INSERT_FAILED');
        }$endpointIndex++;
    }$insert->close();checkRecovery($db->commit(),'FIXTURE_BULK_COMMIT_FAILED');
    // A separate historical cursor must survive the same backup as live state.
    $cursors=$db->real_escape_string(json_encode(array('positions'=>array('0'=>'40'),'canbushistory'=>'42','telemetry'=>array('0'=>'44'),'wiredtelemetry'=>array('0'=>'0'))));
    recoveryQuery($db,"INSERT INTO api_suite_backfill_state VALUES ('synthetic_recovery','".$cursors."',1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),'{\"positions\":3,\"canbus\":3,\"telemetry\":3}',0,'PAGE_ACCEPTED_NOT_COMPLETE')");
}
try{
    $p=FleetApi\StrictJson::decode(fgets(STDIN,2097153),true,16);
    checkRecovery(isset($p['directory'],$p['port'],$p['rootPassword'],$p['action'],$p['schema'])&&preg_match('/^recovery_[a-f0-9]{16}$/D',$p['schema']),'FIXTURE_INPUT_REQUIRED');
    $resolvedDirectory=realpath($p['directory']);$expectedRoot=realpath(__DIR__.'/../test-results');
    checkRecovery($resolvedDirectory!==false&&$expectedRoot!==false&&in_array(basename($resolvedDirectory),array('source','restored'),true)&&preg_match('/^storage-recovery-[a-zA-Z0-9]+$/D',basename(dirname($resolvedDirectory)))&&strtolower(dirname(dirname($resolvedDirectory)))===strtolower($expectedRoot),'OWN_FRESH_RECOVERY_DIRECTORY_REQUIRED');
    $db=mysqli_init();$db->options(MYSQLI_OPT_CONNECT_TIMEOUT,2);
    checkRecovery(@$db->real_connect('127.0.0.1','root',empty($p['bootstrapped'])?'':$p['rootPassword'],null,$p['port']),'FIXTURE_SQL_CONNECT_FAILED');
    $identity=recoveryQuery($db,'SELECT @@datadir AS directory,@@port AS port')->fetch_assoc();
    checkRecovery(strtolower(rtrim(str_replace('\\','/',$identity['directory']),'/'))===strtolower(str_replace('\\','/',$p['directory'].'/data'))&&(int)$identity['port']===$p['port'],'OWN_FIXTURE_REQUIRED');
    checkRecovery($db->set_charset('utf8mb4'),'FIXTURE_CHARSET_FAILED');
    if($p['action']==='ready'||$p['action']==='identity'){echo '{"result":"PASS","ownDatadirVerified":true}';exit;}
    if($p['action']==='bootstrap'){
        $pw=$db->real_escape_string($p['rootPassword']);recoveryQuery($db,"ALTER USER 'root'@'localhost' IDENTIFIED BY '".$pw."'");
        recoveryQuery($db,'CREATE DATABASE `'.$p['schema'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');echo '{"result":"PASS"}';exit;
    }
    if($p['action']==='shutdown'){recoveryQuery($db,'SHUTDOWN');echo '{"result":"PASS"}';exit;}
    checkRecovery($db->select_db($p['schema']),'FIXTURE_DATABASE_REQUIRED');
    if($p['action']==='populate'){recoveryPopulate($db);echo json_encode(array('result'=>'PASS','snapshot'=>recoverySnapshot($db)));exit;}
    if($p['action']==='snapshot'){echo json_encode(array('result'=>'PASS','snapshot'=>recoverySnapshot($db)));exit;}
    if($p['action']==='prepare-crash'){
        // Only fictitious fixture clock changes: no real cadence bypass occurs.
        recoveryQuery($db,"UPDATE api_suite_state SET last_attempt_utc=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 62 SECOND) WHERE tenant_key='synthetic_recovery'");
        FleetApi\SuiteServiceStore::dispatch($db,'synthetic_recovery',60);
        echo json_encode(array('result'=>'PASS','snapshot'=>recoverySnapshot($db)));exit;
    }
    if($p['action']==='hold-uncommitted'){
        checkRecovery($db->begin_transaction(),'FIXTURE_TRANSACTION_FAILED');
        recoveryQuery($db,"INSERT INTO fixture_recovery_probe VALUES (2,7,1.0000000,'MUST ROLL BACK','UNCOMMITTED',0xDEADBEEF)");
        recoveryQuery($db,"UPDATE api_suite_state SET generation=999,cursors_json='{\"corruption\":\"uncommitted\"}' WHERE tenant_key='synthetic_recovery'");
        echo '{"result":"PASS","transactionOpen":true,"ownDatadirVerified":true}'.PHP_EOL;flush();
        fgets(STDIN);$db->rollback();exit;
    }
    if($p['action']==='verify-recovery'){
        $before=FleetApi\SuiteServiceStore::state($db,'synthetic_recovery');
        checkRecovery($before['inFlight']===true&&$before['status']==='COLLECTING'&&$before['generation']===1,'CRASH_DID_NOT_PRESERVE_INFLIGHT');
        $rejected=false;try{FleetApi\SuiteServiceStore::dispatch($db,'synthetic_recovery',60);}catch(FleetApi\ApiException $e){$rejected=true;}
        checkRecovery($rejected,'AMBIGUOUS_REQUEST_RETRIED');
        checkRecovery(FleetApi\SuiteServiceStore::state($db,'synthetic_recovery')===$before,'RETRY_GUARD_CHANGED_STATE');
        $recentRejected=false;try{FleetApi\SuiteServiceStore::recover($db,'synthetic_recovery',array('expectedGeneration'=>1,'expectedStatus'=>'COLLECTING','expectedLastAttemptUtc'=>$before['lastAttemptUtc'],'reason'=>'MANUAL_REVIEW_AFTER_LOCAL_FAILURE'));}catch(FleetApi\ApiException $e){$recentRejected=true;}
        checkRecovery($recentRejected,'RECENT_REVIEW_NOT_BLOCKED');
        $publication=recoveryQuery($db,"SELECT generation,captured_utc,bundle_json,bundle_sha256,scope_json,scope_sha256,cursors_json FROM api_suite_state WHERE tenant_key='synthetic_recovery'")->fetch_assoc();
        recoveryQuery($db,"UPDATE api_suite_state SET last_attempt_utc=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 62 SECOND) WHERE tenant_key='synthetic_recovery'");
        $reviewState=FleetApi\SuiteServiceStore::state($db,'synthetic_recovery');
        $review=FleetApi\SuiteServiceStore::recover($db,'synthetic_recovery',array('expectedGeneration'=>1,'expectedStatus'=>'COLLECTING','expectedLastAttemptUtc'=>$reviewState['lastAttemptUtc'],'reason'=>'MANUAL_REVIEW_AFTER_LOCAL_FAILURE'));
        checkRecovery($review['cursorsPreserved']&&$review['publicationPreserved'],'REVIEW_DID_NOT_PRESERVE_PUBLICATION');
        checkRecovery($publication===recoveryQuery($db,"SELECT generation,captured_utc,bundle_json,bundle_sha256,scope_json,scope_sha256,cursors_json FROM api_suite_state WHERE tenant_key='synthetic_recovery'")->fetch_assoc(),'REVIEW_CHANGED_PUBLICATION_OR_CURSORS');
        $after=FleetApi\SuiteServiceStore::state($db,'synthetic_recovery');
        checkRecovery($after['inFlight']===false&&$after['status']==='RECOVERY_REVIEWED','EXPLICIT_REVIEW_FAILED');
        echo json_encode(array('result'=>'PASS','checks'=>7,'automaticRetryBlocked'=>true,'recentReviewBlocked'=>true,'reviewPreservedCursorsAndPublication'=>true,'state'=>$after));exit;
    }
    throw new RuntimeException('FIXTURE_ACTION_INVALID');
}catch(Exception $e){$m=$e->getMessage();echo json_encode(array('result'=>'FAIL','category'=>preg_match('/^[A-Z0-9_]+$/D',$m)?$m:'STORAGE_RECOVERY_FIXTURE_FAILED'));exit(1);}
