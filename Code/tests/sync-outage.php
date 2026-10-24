<?php
require_once __DIR__.'/../src/Bootstrap.php';
require_once __DIR__.'/../demo/fixtures.php';
FleetApi\Bootstrap::configureRuntime();
$checks=0;
function outageCheck($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function outageReject($callback,$code){$caught=false;try{$callback();}catch(FleetApi\ApiException $e){$caught=$e->getMessage()===$code;}outageCheck($caught,'expected_'.$code);}
class OutageStatement{
 private $rows;private $position=0;
 function __construct($rows){$this->rows=$rows;}
 function execute($args){return true;}
 function fetch(){return isset($this->rows[$this->position])?$this->rows[$this->position++]:null;}
}
class OutageDatabase{
 public $endpoint='vehicles';public $corrupt=false;public $missing=false;public $future=false;
 function requireInnoDb($table){}
 function prepare($sql){
  $demo=fleet_demo_rows();$captured=$this->future?'2099-01-01 00:00:00':'2020-01-01 00:00:00';
  if(strpos($sql,'FROM api_suite_state')!==false){$scope='["7"]';$rows=$this->missing?array():array(array('captured_utc'=>$captured,'scope_json'=>$scope,'scope_sha256'=>hash('sha256',$scope),'generation'=>2,'status'=>'COLLECTING','in_flight'=>1));}
  elseif(strpos($sql,'FROM api_suite_snapshots')!==false){$json=json_encode(array($demo[$this->endpoint][0]),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$rows=array(array('payload_json'=>$json,'payload_sha256'=>$this->corrupt?str_repeat('0',64):hash('sha256',$json)));}
  elseif(strpos($sql,'FROM api_suite_feeds')!==false){$rows=array(array('first_present_utc'=>$captured,'last_present_utc'=>$captured));}
  elseif(strpos($sql,'COUNT(*) AS n,MIN(')!==false){$rows=array(array('n'=>'1','earliest'=>$captured,'latest'=>$captured));}
  elseif(strpos($sql,'COUNT(*) AS n,COALESCE(')!==false){$rows=array(array('n'=>'3','payload_bytes'=>'1000'));}
  elseif(strpos($sql,'FROM api_suite_history')!==false){$row=$demo[$this->endpoint][0];$json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$rows=array(array('record_id'=>$row['ID'],'vehicle_id'=>(string)$row['CWVehicleID'],'measured_at'=>FleetApi\Contract::eventTime($this->endpoint,$row['MeasuredTime']),'payload_json'=>$json,'payload_sha256'=>$this->corrupt?str_repeat('0',64):hash('sha256',$json)));}
  else throw new RuntimeException('unexpected_read_query');
  return new OutageStatement($rows);
 }
}
class OutageConfiguredRepository extends FleetApi\ConfiguredRepository{
 public $db;public $mode='last-known-with-warning';private $meta=null;private $repo;
 function __construct(){parent::__construct(array());$this->db=new OutageDatabase();}
 function supportsExpandedAccountScope($tenant){return true;}
 function supportsVehicleAccountScope($tenant){return true;}
 function clearRequestMetadata(){parent::clearRequestMetadata();$this->meta=null;}
 function publicationMetadata(){return $this->meta;}
 function verifiedVehicleScope($tenant){return $this->repo->verifiedVehicleScope($tenant);}
 function read($tenant,$ep,$id,$from,$to,$limit){$this->db->endpoint=$ep;$this->repo=new FleetApi\SuiteLiveRepository($this->db,$tenant,300,null,null,$this->mode);$rows=$this->repo->read($tenant,$ep,$id,$from,$to,$limit);$this->meta=$this->repo->publicationMetadata();return $rows;}
}
class OutageLimiter implements FleetApi\RateLimiter{public $limited=false;function acquire($key,$interval){return $this->limited?31:0;}}
try{
 $stamp=strtotime('2026-10-20 00:00:00 UTC');
 foreach(array(0,300) as $age){$m=FleetApi\SnapshotReadPolicy::evaluate('2026-10-20 00:00:00',300,'fail-closed',$stamp+$age);outageCheck($m['publicationFreshness']==='FRESH','fresh_boundary');}
 $m=FleetApi\SnapshotReadPolicy::evaluate('2026-10-20 00:00:00',300,'last-known-with-warning',$stamp+301);outageCheck($m['publicationFreshness']==='STALE'&&$m['publicationAgeSeconds']===301,'stale_is_explicit');
 outageReject(function()use($stamp){FleetApi\SnapshotReadPolicy::evaluate('2026-10-20 00:00:00',300,'fail-closed',$stamp+301);},'source_stale');
 foreach(array('fail-closed','last-known-with-warning') as $mode){outageReject(function()use($stamp,$mode){FleetApi\SnapshotReadPolicy::evaluate('2026-10-20 00:00:00',300,$mode,$stamp-1);},'source_from_future');}
 outageReject(function(){FleetApi\SnapshotReadPolicy::normalize('silently-fresh');},'configuration_invalid');
 $repo=new OutageConfiguredRepository();$limiter=new OutageLimiter();
 $accounts=array('fixture'=>array('tenant'=>'outage_fixture','tokenSha256'=>hash('sha256','fixture-token'),'vehicleIds'=>array(),'scopeMode'=>'eai-account','endpoints'=>array_keys(FleetApi\Contract::schemas())));
 $api=new FleetApi\Api($accounts,$repo,$limiter,array('maxRows'=>100,'maxResponseBytes'=>1048576,'minIntervalSeconds'=>60,'enforceMinuteRule'=>true));
 foreach(array_keys(FleetApi\Contract::schemas()) as $ep){
  $uri='/cweai/fixture/'.$ep.'?password=fixture-token'.(FleetApi\Contract::history($ep)?'&vehicleid=7&ymdfrom=2000-01-01&ymdto=2099-01-01':'');
  $r=$api->handle('GET',$uri);$rows=json_decode($r['body'],true);
  outageCheck($r['status']===200&&count($rows)===1,'stored_response_'.$ep);
  outageCheck($r['headers']['X-Data-Freshness']==='STALE','warning_'.$ep);
  outageCheck($r['headers']['X-Data-Captured-At']==='2020-01-01T00:00:00Z','capture_time_'.$ep);
  outageCheck((int)$r['headers']['X-Data-Age-Seconds']>300,'age_'.$ep);
  outageCheck($r['headers']['X-Collection-State']==='REVIEW-REQUIRED','collection_state_'.$ep);
  outageCheck(count($rows[0])===count(FleetApi\Contract::fields($ep)),'schema_unchanged_'.$ep);
  $r=$api->handle('GET',str_replace('password=fixture-token','password=wrong-token',$uri));outageCheck($r['status']===503&&!isset($r['headers']['X-Data-Freshness']),'unauthorized_stays_closed_'.$ep);
  $repo->db->corrupt=true;$r=$api->handle('GET',$uri);outageCheck($r['status']===503,'corruption_stays_closed_'.$ep);$repo->db->corrupt=false;
  $repo->mode='fail-closed';$r=$api->handle('GET',$uri);outageCheck($r['status']===503,'legacy_default_fail_closed_'.$ep);$repo->mode='last-known-with-warning';
  $limiter->limited=true;$r=$api->handle('GET',$uri);outageCheck(($ep==='positions'?$r['status']===200:$r['status']===503)&&$r['headers']['Retry-After']==='31','quota_still_enforced_'.$ep);$limiter->limited=false;
 }
 $uri='/cweai/fixture/vehicles?password=fixture-token';
 $repo->db->missing=true;outageCheck($api->handle('GET',$uri)['status']===503,'no_publication_not_invented');$repo->db->missing=false;
 $repo->db->future=true;outageCheck($api->handle('GET',$uri)['status']===503,'future_stays_closed');$repo->db->future=false;
 $uri='/cweai/fixture/telemetry?password=fixture-token&vehicleid=99&ymdfrom=2000-01-01&ymdto=2099-01-01';outageCheck($api->handle('GET',$uri)['status']===503,'foreign_vehicle_stays_closed');
 echo json_encode(array('result'=>'PASS','suite'=>'synchronization-outage','checks'=>$checks,'eaiCalls'=>0,'filesDeleted'=>0)).PHP_EOL;
}catch(Exception $e){echo json_encode(array('result'=>'FAIL','category'=>$e->getMessage(),'checks'=>$checks)).PHP_EOL;exit(1);}
