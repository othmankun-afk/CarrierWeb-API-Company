<?php
require_once __DIR__.'/../src/Bootstrap.php';
\FleetApi\Bootstrap::configureRuntime();
error_reporting(E_ALL);ini_set('display_errors','1');
$checks=0;
function protectedCheck($condition,$label){global $checks;if(!$condition){throw new \RuntimeException('FAIL '.$label);}$checks++;}
function protectedReject($call,$code,$label){try{$call();}catch(\FleetApi\ApiException $e){protectedCheck($e->status===503&&$e->getMessage()===$code,$label);return;}throw new \RuntimeException('FAIL missing rejection '.$label);}
class ProtectionTestLimiter implements \FleetApi\RateLimiter{public $calls=0;public function acquire($key,$interval){$this->calls++;return 0;}}
class ProtectionTestRepository implements \FleetApi\Repository{public $calls=0;public $fail=false;public function read($tenant,$endpoint,$vehicle,$from,$to,$limit){$this->calls++;if($this->fail){throw new \RuntimeException('PRIVATE_SYNTHETIC_SECRET');}return array();}}
$run=__DIR__.'/../test-results/request-protection-'.gmdate('Ymd-His').'-'.str_replace('.','',uniqid('',true));
if(!mkdir($run,0700,true)){throw new \RuntimeException('TEST_DIRECTORY');}
$configuration=array('directory'=>realpath($run),'globalMaxConcurrent'=>2,'tenantMaxConcurrent'=>1,'requestTimeoutSeconds'=>20);
$guard=new \FleetApi\RequestProtection($configuration);
$first=$guard->begin();$first->tenant('fixture_tenant');
$second=$guard->begin();
protectedReject(function()use($second){$second->tenant('fixture_tenant');},'request_capacity_busy','same tenant cannot consume second global slot');
$second->release();
$other=$guard->begin();$other->tenant('other_tenant');
protectedReject(function()use($guard){$guard->begin();},'request_capacity_busy','global capacity enforced across tenants');
$first->release();$first->release();$other->check();$other->release();
$again=$guard->begin();$again->tenant('fixture_tenant');$again->release();protectedCheck(true,'slots reusable without file deletion');
protectedCheck(count(glob($run.'/*.lock'))===4,'bounded two global and two authenticated tenant slot files');
foreach(array('globalMaxConcurrent','tenantMaxConcurrent','requestTimeoutSeconds') as $key){
    foreach(array(0,-1,'1',1.5,true,null) as $value){$bad=$configuration;$bad[$key]=$value;protectedReject(function()use($bad){new \FleetApi\RequestProtection($bad);},'configuration_invalid','invalid typed bound '.$key);}
}
foreach(array(array('globalMaxConcurrent'=>257),array('tenantMaxConcurrent'=>3),array('requestTimeoutSeconds'=>301),array('unexpected'=>1),array('directory'=>__DIR__.'/../public'),array('directory'=>'relative'),array('directory'=>$run.'/absent')) as $override){$bad=array_merge($configuration,$override);protectedReject(function()use($bad){new \FleetApi\RequestProtection($bad);},'configuration_invalid','invalid configuration shape/path/bound');}
$expired=new \FleetApi\RequestProtectionLease($guard,fopen($run.'/expired.lock','c+b'),microtime(true)-1,20);
protectedReject(function()use($expired){$expired->check();},'request_deadline_exceeded','expired deadline fails closed');$expired->release();
$accounts=array('fixture'=>array('tenant'=>'fixture_tenant','tokenSha256'=>hash('sha256','synthetic-token'),'vehicleIds'=>array(),'endpoints'=>array_keys(\FleetApi\Contract::schemas())));
$options=array('maxRows'=>10000,'maxResponseBytes'=>16777216,'minIntervalSeconds'=>60,'enforceMinuteRule'=>true,'requestConcurrency'=>$configuration);
$repository=new ProtectionTestRepository();$limiter=new ProtectionTestLimiter();$api=new \FleetApi\Api($accounts,$repository,$limiter,$options);
$path='/cweai/fixture/vehicles?password=synthetic-token';
$held=$guard->begin();$held->tenant('fixture_tenant');
$response=$api->handleInternal('GET',$path);protectedCheck($response['status']===503&&strpos($response['body'],'request_capacity_busy')!==false,'API rejects busy tenant');
protectedCheck($repository->calls===0&&$limiter->calls===0,'busy request does not touch source or consume minute');
$wire=$api->handle('GET',$path);protectedCheck($wire['status']===503&&$wire['body']==='','legacy busy wire response unchanged');
$held->release();$response=$api->handleInternal('GET',$path);
protectedCheck($response['status']===200&&$response['body']==='[]'&&$repository->calls===1&&$limiter->calls===1,'capacity release admits actual repository read');
$repository->fail=true;$response=$api->handleInternal('GET',$path);protectedCheck($response['status']===503&&strpos($response['body'],'PRIVATE_SYNTHETIC_SECRET')===false,'source failure redacted');
$repository->fail=false;protectedCheck($api->handleInternal('GET',$path)['status']===200,'exception path releases both slots');
$before=$limiter->calls;protectedCheck($api->handleInternal('GET','/cweai/fixture/vehicles?password=invalid')['status']===401&&$limiter->calls===$before,'unauthorized does not consume tenant/minute');
protectedCheck($api->handleInternal('GET',$path)['status']===200,'unauthorized path releases global slot');
$minute=new \FleetApi\FileRateLimiter($run);$minuteApi=new \FleetApi\Api($accounts,$repository,$minute,$options);
protectedCheck($minuteApi->handleInternal('GET',$path)['status']===200,'first minute request admitted');$limited=$minuteApi->handleInternal('GET',$path);protectedCheck($limited['status']===429&&isset($limited['headers']['Retry-After']),'existing minute remains enforced');

// Independent PHP process, not merely independent objects in one request.
if(!function_exists('proc_open')){throw new \RuntimeException('PROCESS_TEST_UNAVAILABLE');}
$ready=$run.'/worker-ready';$release=$run.'/worker-release';$configFile=$run.'/worker-config.json';file_put_contents($configFile,json_encode($configuration));$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/request-protection-worker.php').' '.escapeshellarg($configFile).' '.escapeshellarg($ready).' '.escapeshellarg($release);
$pipes=array();$process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));
if(!is_resource($process)){throw new \RuntimeException('PROCESS_START');}fclose($pipes[0]);
try{
    $until=microtime(true)+8;while(!is_file($ready)&&microtime(true)<$until){usleep(10000);}
    protectedCheck(is_file($ready),'independent worker acquired and signals locks');
    $response=$api->handleInternal('GET',$path);protectedCheck($response['status']===503&&$response['body']==='{"error":{"code":"request_capacity_busy"}}','process-shared tenant contention');
    $free=$guard->begin();$free->tenant('other_tenant');protectedReject(function()use($guard){$guard->begin();},'request_capacity_busy','process-shared global contention');$free->release();
}finally{file_put_contents($release,'RELEASE');}
$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
protectedCheck($exit===0&&strpos($stdout,'PASS')!==false&&$stderr==='','worker released without termination or deletion');
protectedCheck($api->handleInternal('GET',$path)['status']===200,'worker release restores service');
echo json_encode(array('suite'=>'request-protection','result'=>'PASS','checks'=>$checks,'php'=>PHP_VERSION,'processSharedVerified'=>true,'sqlConnections'=>0,'eaiCalls'=>0,'filesDeleted'=>0,'testDirectory'=>$run))."\n";
