<?php
require_once __DIR__.'/../src/Bootstrap.php';
FleetApi\Bootstrap::configureRuntime();mysqli_report(MYSQLI_REPORT_OFF);
try{
    if(PHP_SAPI!=='cli'||$argc!==1||getenv('FLEET_API_LOCAL_TEST')!=='1')throw new RuntimeException('LOCAL_SERVICE_ONLY');$p=FleetApi\StrictJson::decode(stream_get_contents(STDIN,33554433),true,64);$c=$p['connection'];if($c['host']!=='127.0.0.1')throw new RuntimeException('LOCAL_SQL_ONLY');
    $db=mysqli_init();$db->options(MYSQLI_OPT_CONNECT_TIMEOUT,3);$db->options(MYSQLI_OPT_LOCAL_INFILE,false);if(!@$db->real_connect($c['host'],$c['username'],$c['password'],isset($c['database'])?$c['database']:null,$c['port'])||!$db->set_charset('utf8mb4'))throw new RuntimeException('LOCAL_SQL_CONNECT_FAILED');
    $identity=$db->query('SELECT @@datadir AS directory,@@port AS port')->fetch_assoc();if(strtolower(rtrim(str_replace('\\','/',$identity['directory']),'/'))!==strtolower(rtrim(str_replace('\\','/',$p['directory'].'/data'),'/'))||(int)$identity['port']!==$c['port'])throw new RuntimeException('OWN_SERVICE_SQL_REQUIRED');$out=array('result'=>'PASS');
    if($p['action']==='ready'){}
    elseif($p['action']==='bootstrap'){$pw=$db->real_escape_string($p['rootPassword']);if(!$db->query("ALTER USER 'root'@'localhost' IDENTIFIED BY '".$pw."'"))throw new RuntimeException('ROOT_BOOTSTRAP_FAILED');}
    elseif($p['action']==='shutdown'){if(!$db->query('SHUTDOWN'))throw new RuntimeException('OWN_SHUTDOWN_FAILED');}
    elseif($p['action']==='ensure-backfill-schema'){
        if(!isset($c['database'])||!preg_match('/^suite_[a-f0-9]{16}$/D',$c['database']))throw new RuntimeException('OWN_SUITE_SCHEMA_REQUIRED');
        $out=FleetApi\SuiteServiceStore::ensureBackfillSchema($db);
    }
    elseif($p['action']==='provision'){
        if(isset($p['capacityPolicy'])&&!is_array($p['capacityPolicy']))throw new RuntimeException('CAPACITY_POLICY_INVALID');
        $capacityPolicy=isset($p['capacityPolicy'])?FleetApi\SuiteCapacityPolicy::normalize($p['capacityPolicy']):null;
        $tenant=$p['tenant'];$schema=$p['schema'];if(!preg_match('/^suite_[a-f0-9]{16}$/D',$schema)||!preg_match('/^[a-z0-9_-]{1,64}$/D',$tenant))throw new RuntimeException('SUITE_PROVISION_INVALID');
        if(!$db->query('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin')||!$db->select_db($schema)||!$db->multi_query(file_get_contents(__DIR__.'/../database/suite_live.sql')))throw new RuntimeException('SUITE_PROVISION_FAILED');while($db->more_results()){if(!$db->next_result())throw new RuntimeException('SUITE_PROVISION_FAILED');}
        $suffix=substr($schema,6,12);$reader='sr_'.$suffix;$writer='sw_'.$suffix;foreach(array($reader=>$p['readerPassword'],$writer=>$p['writerPassword']) as $user=>$password){if(!preg_match('/^[a-f0-9]{64}$/D',$password))throw new RuntimeException('SQL_SECRET_INVALID');if(!$db->query("CREATE USER '".$user."'@'127.0.0.1' IDENTIFIED BY '".$password."'"))throw new RuntimeException('SQL_USER_FAILED');}
        if(!$db->query("GRANT SELECT ON `".$schema."`.* TO '".$reader."'@'127.0.0.1'")||!$db->query("GRANT SELECT,INSERT,UPDATE ON `".$schema."`.* TO '".$writer."'@'127.0.0.1'"))throw new RuntimeException('SQL_GRANT_FAILED');
        FleetApi\SuiteServiceStore::initialize($db,$tenant);$out['readerConnection']=array('host'=>'127.0.0.1','port'=>$c['port'],'database'=>$schema,'username'=>$reader,'password'=>$p['readerPassword'],'queryTimeoutMs'=>3000,'maxSnapshotAgeSeconds'=>300);$out['publisherConnection']=$out['readerConnection'];$out['publisherConnection']['username']=$writer;$out['publisherConnection']['password']=$p['writerPassword'];$out['publisherConnection']['tenant']=$tenant;
        if($capacityPolicy!==null){$out['readerConnection']['capacityPolicy']=$capacityPolicy;$out['publisherConnection']['capacityPolicy']=$capacityPolicy;}
    }else{
        if(!isset($c['tenant'])||$c['tenant']!==$p['tenant'])throw new RuntimeException('SUITE_TENANT_MISMATCH');
        $capacityPolicy=isset($c['capacityPolicy'])?$c['capacityPolicy']:null;
        if($capacityPolicy!==null&&!is_array($capacityPolicy))throw new RuntimeException('CAPACITY_POLICY_INVALID');
        if($p['action']==='state')$out=FleetApi\SuiteServiceStore::state($db,$p['tenant']);
        elseif($p['action']==='backfill-state')$out=FleetApi\SuiteServiceStore::backfillState($db,$p['tenant']);
        elseif($p['action']==='dispatch-backfill')$out=FleetApi\SuiteServiceStore::dispatchBackfill($db,$p['tenant'],$p['intervalSeconds']);
        elseif($p['action']==='accept-backfill')$out=FleetApi\SuiteServiceStore::acceptBackfill($db,$p['tenant'],$p['capture'],$capacityPolicy);
        elseif($p['action']==='capacity')$out=FleetApi\SuiteServiceStore::capacity($db,$p['tenant'],$capacityPolicy);
        elseif($p['action']==='capacity-preflight')$out=FleetApi\SuiteServiceStore::preflightCapacity($db,$p['tenant'],$capacityPolicy);
        elseif($p['action']==='inventory'){
            $state=FleetApi\SuiteServiceStore::row($db,$p['tenant']);$out=array('state'=>FleetApi\SuiteServiceStore::state($db,$p['tenant']),'vehicleIds'=>FleetApi\StrictJson::decode($state['scope_json'],true,8),'history'=>array());
            foreach(array('positions','canbus','telemetry') as $endpoint){$s=$db->prepare('SELECT COUNT(*) AS rows_count,MIN(measured_at) AS first_event,MAX(measured_at) AS last_event FROM api_suite_history WHERE tenant_key=? AND endpoint=?');$s->bind_param('ss',$p['tenant'],$endpoint);$s->execute();$info=$s->get_result()->fetch_assoc();$s->close();$s=$db->prepare('SELECT vehicle_id,measured_at FROM api_suite_history WHERE tenant_key=? AND endpoint=? ORDER BY measured_at DESC,record_id DESC LIMIT 1');$s->bind_param('ss',$p['tenant'],$endpoint);$s->execute();$sample=$s->get_result()->fetch_assoc();$s->close();$out['history'][$endpoint]=array('rows'=>(int)$info['rows_count'],'first'=>$info['first_event'],'last'=>$info['last_event'],'sample'=>$sample);}
        }
        elseif($p['action']==='dispatch')$out=FleetApi\SuiteServiceStore::dispatch($db,$p['tenant'],$p['intervalSeconds']);
        elseif($p['action']==='recover')$out=FleetApi\SuiteServiceStore::recover($db,$p['tenant'],$p['review']);
        elseif($p['action']==='fault'){$category=preg_match('/^[A-Z0-9_]{1,48}$/D',$p['category'])?'FAULT_'.$p['category']:'FAULT_UNCLASSIFIED';$s=$db->prepare('UPDATE api_suite_state SET status=? WHERE tenant_key=?');$s->bind_param('ss',$category,$p['tenant']);if(!$s->execute())throw new RuntimeException('FAULT_RECORD_FAILED');$s->close();}
        elseif($p['action']==='accept'||$p['action']==='replay-pending'){$bytes=file_get_contents($p['ruleProfilePath']);if(!hash_equals($p['ruleProfileSha256'],hash('sha256',$bytes)))throw new RuntimeException('SUITE_PROFILE_CHANGED');$profile=FleetApi\StrictJson::decode($bytes,true,32);if($p['action']==='replay-pending'&&(!isset($p['review'])||!is_array($p['review'])))throw new RuntimeException('LOCAL_REPLAY_REVIEW_REQUIRED');$out=FleetApi\SuiteServiceStore::accept($db,$p['tenant'],$p['capture'],$profile,$capacityPolicy,$p['action']==='replay-pending'?$p['review']:null);}
        else throw new RuntimeException('SUITE_ACTION_REFUSED');$out['result']='PASS';
    }
    $db->close();echo json_encode($out,JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Exception $e){$m=$e->getMessage();echo json_encode(array('result'=>'FAIL','category'=>preg_match('/^[A-Za-z0-9_]{1,80}$/D',$m)?$m:'SUITE_SQL_REFUSED')).PHP_EOL;exit(1);}
