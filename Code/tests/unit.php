<?php
require_once __DIR__.'/../src/Bootstrap.php';
require_once __DIR__.'/../demo/fixtures.php';
\FleetApi\Bootstrap::configureRuntime();
// Deliberately enable warnings in tests, not in the HTTP response.
error_reporting(E_ALL); ini_set('display_errors', '1');
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) { return false; }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});
$checks = 0;
function check($value, $label) { global $checks; if (!$value) { throw new \RuntimeException('FAIL: '.$label); } $checks++; }
function rejects($call, $status, $label) {
    try { $call(); } catch (\FleetApi\ApiException $e) { check($e->status === $status, $label); return; }
    throw new \RuntimeException('FAIL: expected rejection '.$label);
}
class TestLimiter implements \FleetApi\RateLimiter {
    public $calls = array(); public $wait = 0;
    public function acquire($key, $interval) { $this->calls[] = array($key,$interval); return $this->wait; }
}
class SpyRepository implements \FleetApi\Repository {
    public $calls = array(); public $failure = false; private $inner;
    public function __construct($inner) { $this->inner = $inner; }
    public function read($tenant,$endpoint,$vehicle,$from,$to,$limit) {
        $this->calls[] = func_get_args();
        if ($this->failure) { throw new \RuntimeException('PRIVATE_DATABASE_PASSWORD'); }
        return $this->inner->read($tenant,$endpoint,$vehicle,$from,$to,$limit);
    }
}
function pathFor($endpoint, $overrides = array(), $omit = array()) {
    $q = array('password'=>'demo-local-token-2026');
    if (\FleetApi\Contract::history($endpoint)) { $q += array('vehicleid'=>'7','ymdfrom'=>'2030-01-02 10:00:00','ymdto'=>'2030-01-02 10:02:00'); }
    foreach ($overrides as $k=>$v) { $q[$k]=$v; }
    foreach ($omit as $k) { unset($q[$k]); }
    return '/cweai/demo/'.$endpoint.'?'.http_build_query($q, '', '&', PHP_QUERY_RFC3986);
}
$options = array('maxRows'=>100,'maxResponseBytes'=>1048576,'minIntervalSeconds'=>60);
$data = fleet_demo_rows(); $accounts = fleet_demo_accounts();
$accounts['other'] = array('tenant'=>'other_tenant','tokenSha256'=>hash('sha256','other-synthetic-token'),'vehicleIds'=>array('7'),'endpoints'=>array_keys($data));
$other = $data; $other['positions'][0]['Location'] = 'OTHER TENANT';
$repo = new SpyRepository(new \FleetApi\ArrayRepository(array('demo_tenant'=>$data,'other_tenant'=>$other)));
$limiter = new TestLimiter(); $api = new \FleetApi\Api($accounts,$repo,$limiter,$options);
$expectedCounts = array('vehiclesBase'=>14,'vehicles'=>44,'positions'=>14,'canbus'=>33,'telemetry'=>7);
foreach ($expectedCounts as $endpoint=>$fieldCount) {
    $r=$api->handleInternal('GET',pathFor($endpoint)); $rows=json_decode($r['body'],true);
    check($r['status']===200 && count($rows)===(\FleetApi\Contract::history($endpoint)?3:1), $endpoint.' successful response');
    check(count($rows[0])===$fieldCount, $endpoint.' field count');
    check($r['headers']['Cache-Control']==='no-store', $endpoint.' no cache');
    check($rows[0]['CWVehicleID']===7, $endpoint.' numeric ID');
}
$positions=json_decode($api->handleInternal('GET',pathFor('positions'))['body'],true);
check($positions[0]['ID']==='90071992547409930','large ID remains string');
check($positions[0]['Zipcode']===' ','spaces preserved');
check($positions[0]['Latitude']===12.34567,'coordinates unscaled');
$canbus=json_decode($api->handleInternal('GET',pathFor('canbus'))['body'],true);
check($canbus[0]['Odometer']==2147483649.0 && !is_string($canbus[0]['Odometer']), 'x86 safe numeric counter');
check($canbus[0]['DriverName']==='  ' && $canbus[0]['DriverNumber']==='', 'empty versus whitespace');
check($canbus[0]['MeasuredTime']==='02.01.2030 10:00:00','CANbus original date');
$telemetry=json_decode($api->handleInternal('GET',pathFor('telemetry'))['body'],true);
check($telemetry[0]['Value0']===1234 && $telemetry[1]['Value0']===1235 && $telemetry[2]['TelemetryType']===9999,'raw 153/171 and unknown code');

foreach (array('positions','canbus','telemetry') as $endpoint) {
    foreach (array('vehicleid','ymdfrom','ymdto') as $parameter) {
        check($api->handleInternal('GET',pathFor($endpoint,array(),array($parameter)))['status']===400,$endpoint.' missing '.$parameter);
        check($api->handleInternal('GET',pathFor($endpoint,array($parameter=>'')))['status']===400,$endpoint.' empty '.$parameter);
    }
    foreach (array('not-a-date','2030-02-30','2030-01-02 24:00:00','2030-1-2','0000-01-01','2030-01-02T10:00:00Z','2030-01-02 10:00:00 ') as $date) {
        check($api->handleInternal('GET',pathFor($endpoint,array('ymdfrom'=>$date)))['status']===400,$endpoint.' invalid date '.$date);
    }
    $r=$api->handleInternal('GET',pathFor($endpoint,array('ymdfrom'=>'2030-01-02 10:01:00','ymdto'=>'2030-01-02 10:01:00')));
    check($r['status']===200 && count(json_decode($r['body'],true))===1,$endpoint.' inclusive equal boundary');
    check(count(json_decode($api->handleInternal('GET',pathFor($endpoint,array('ymdfrom'=>'2030-01-02')))['body'],true))===3,$endpoint.' midnight date-only');
    check($api->handleInternal('GET',pathFor($endpoint,array('ymdfrom'=>'2030-01-03')))['body']==='[]',$endpoint.' reversed interval');
    check($api->handleInternal('GET',pathFor($endpoint,array('vehicleid'=>'8')))['status']===403,$endpoint.' unauthorized known synthetic vehicle');
    foreach (array('7 OR 1=1','../7','07','-1','0','2147483648') as $id) { check($api->handleInternal('GET',pathFor($endpoint,array('vehicleid'=>$id)))['status']===400,$endpoint.' invalid ID'); }
}
check(\FleetApi\Contract::inputDate('2040-02-29')==='2040-02-29 00:00:00','post-2038 leap date works on x86');
$before=count($repo->calls);
foreach (array('','wrong-token') as $token) { check($api->handleInternal('GET',pathFor('positions',array('password'=>$token)))['status']===401,'invalid token'); }
check($api->handleInternal('GET',pathFor('positions',array(),array('password')))['status']===401,'missing token');
check($api->handleInternal('GET',str_replace('/demo/','/other/',pathFor('positions')))['status']===401,'account-scoped token');
check($api->handleInternal('GET',str_replace('/demo/','/missing/',pathFor('positions')))['status']===401,'unknown account');
check(count($repo->calls)===$before,'unauthorized requests never read data');
$r=$api->handleInternal('GET',pathFor('positions',array(),array('password')),array('authorization'=>'Bearer demo-local-token-2026'));
check($r['status']===401,'unverified Bearer channel is disabled');
check($api->handleInternal('GET',pathFor('positions'),array('authorization'=>'Bearer demo-local-token-2026'))['status']===401,'ambiguous credentials rejected');
$r=$api->handleInternal('GET',str_replace('/demo/','/other/',pathFor('positions',array('password'=>'other-synthetic-token'))));
check(json_decode($r['body'],true)[0]['Location']==='OTHER TENANT','second tenant own rows');
check($repo->calls[count($repo->calls)-1][0]==='other_tenant','repository receives authenticated tenant');
$restricted=$accounts; $restricted['demo']['endpoints']=array('positions');
$limited=new \FleetApi\Api($restricted,$repo,$limiter,$options);
check($limited->handleInternal('GET',pathFor('canbus'))['status']===403,'endpoint permission');
$restricted['demo']['vehicleIds']=array();
check((new \FleetApi\Api($restricted,$repo,$limiter,$options))->handleInternal('GET',pathFor('positions'))['status']===403,'empty allowlist denies');

$valid=pathFor('positions');
check($api->handleInternal('GET',$valid.'&contract_probe=1')['body']===$api->handleInternal('GET',$valid)['body'],'unknown parameter ignored');
check($api->handleInternal('GET',$valid.'&ymdto=2030-01-02%2010%3A02%3A00')['status']===200,'identical duplicate ymdto');
foreach (array('&ymdto=2030-01-03','&vehicleid=7','&password=wrong','&vehicleid%5B%5D=7','&x=%ZZ','&x=%00','&x=%FF','&&x=1') as $tail) { check($api->handleInternal('GET',$valid.$tail)['status']===400,'polluted/malformed query'); }
check($api->handleInternal('GET',$valid.'&x='.str_repeat('x',9000))['status']===400,'bounded input');
check($api->handleInternal('POST',$valid)['status']===405,'write method denied');
foreach (array('/src/Api.php','/config.example.json','/cweai/demo/../positions','/cweai/demo/%70ositions') as $path) { check($api->handleInternal('GET',$path)['status']===404,'no route traversal'); }
$limiter->wait=45; $before=count($repo->calls); $r=$api->handleInternal('GET',$valid);
check($r['status']===429 && $r['headers']['Retry-After']==='45','rate limit with retry header');
check(count($repo->calls)===$before,'rate limited request does not read'); $limiter->wait=0;
$repo->failure=true; $r=$api->handleInternal('GET',$valid);
check($r['status']===503 && strpos($r['body'],'PRIVATE')===false,'exception details redacted'); $repo->failure=false;
$small=$options; $small['maxRows']=2;
check((new \FleetApi\Api($accounts,$repo,$limiter,$small))->handleInternal('GET',$valid)['status']===413,'row cap no truncation');
$small=$options; $small['maxResponseBytes']=256;
check((new \FleetApi\Api($accounts,$repo,$limiter,$small))->handleInternal('GET',$valid)['status']===413,'byte cap no partial JSON');

// Current-list timestamps must be checked at the public contract boundary,
// even when the repository supplies already represented rows.
foreach (array('vehicles','vehiclesBase') as $endpoint) {
    foreach (array('LastReportTime','LastLocationTime') as $field) {
        foreach (array('', 'not-a-date', '2026-02-30 12:00:00', '2026-10-21 24:00:00') as $badDate) {
            $badData=$data; $badData[$endpoint][0][$field]=$badDate;
            $badApi=new \FleetApi\Api($accounts,new \FleetApi\ArrayRepository(array('demo_tenant'=>$badData)),new TestLimiter(),$options);
            $invalid=$badApi->handle('GET',pathFor($endpoint));
            check($invalid['status']===503 && $invalid['body']==='', $endpoint.' invalid '.$field.' rejected on public wire');
        }
        foreach (array('1999-11-30 00:00:00','2024-02-29 23:59:59','2040-02-29 12:00:00') as $validDate) {
            $row=$data[$endpoint][0]; $row[$field]=$validDate;
            check(\FleetApi\Contract::row($endpoint,$row)[$field]===$validDate, $endpoint.' valid '.$field.' preserved');
        }
    }
}

$base=$data['positions'][0];
foreach (array(null,true,'12.3',INF,NAN,9007199254740992.0) as $bad) {
    $row=$base; $row['Latitude']=$bad;
    rejects(function()use($row){ \FleetApi\Contract::row('positions',$row); },503,'reject invalid numeric value');
}
$row=$base; unset($row['Location']);
rejects(function()use($row){ \FleetApi\Contract::row('positions',$row); },503,'missing source field');
$row=$base; $row['ID']=42;
rejects(function()use($row){ \FleetApi\Contract::row('positions',$row); },503,'numeric event ID rejected');
$row=$base; $row['Location']="\xff";
rejects(function()use($row){ \FleetApi\Contract::row('positions',$row); },503,'invalid source UTF8');
rejects(function()use($base){ \FleetApi\Contract::rows('positions',array($base,$base)); },503,'duplicate events rejected');
rejects(function(){ \FleetApi\Contract::decodeArray('{}'); },503,'object not array');
$row=$base; $row['SecretColumn']='do not expose';
check(!isset(\FleetApi\Contract::row('positions',$row)['SecretColumn']),'project explicit fields only');
rejects(function(){ (new \FleetApi\ArrayRepository(array()))->read('absent','positions','7','2030-01-01','2030-01-03',100); },503,'missing dataset not empty');

// A fake PDO tests SQL construction/bindings only; it is NOT a database integration test.
class FakeStatement {
    private $records; private $owner;
    public function __construct($records,$owner){$this->records=$records;$this->owner=$owner;}
    public function execute($params){$this->owner->params[]=$params;return true;}
    public function fetch($mode){return count($this->records)?array_shift($this->records):false;}
}
class FakeDb {
    public $sql=array();public $params=array();public $queue=array();
    public function prepare($sql){$this->sql[]=$sql;return new FakeStatement(array_shift($this->queue),$this);}
}
$db=new FakeDb();$db->queue=array(array(array('dataset_ready'=>'1')),array(array('vehicle_id'=>'7','record_id'=>$base['ID'],'measured_at'=>$base['MeasuredTime'],'payload_json'=>json_encode($base))));
$rows=(new \FleetApi\PdoRepository($db))->read('demo_tenant','positions','7','2030-01-02 10:00:00','2030-01-02 10:02:00',100);
check(count($rows)===1,'PDO adapter valid record');
check($db->params[1]===array('demo_tenant','positions','7','2030-01-02 10:00:00','2030-01-02 10:02:00'),'SQL bound tenant/filter values');
check(strpos($db->sql[1],'tenant_key = ? AND endpoint = ? AND vehicle_id = ?')!==false && strpos($db->sql[1],'LIMIT 101')!==false,'bounded parameterized SQL');
$db=new FakeDb();$db->queue=array(array(array('dataset_ready'=>'1')),array(array('vehicle_id'=>'8','record_id'=>$base['ID'],'measured_at'=>$base['MeasuredTime'],'payload_json'=>json_encode($base))));
rejects(function()use($db){(new \FleetApi\PdoRepository($db))->read('demo_tenant','positions','7','2030-01-02 10:00:00','2030-01-02 10:02:00',100);},503,'SQL metadata mismatch fails closed');
foreach(array('0'=>0,'-1'=>-1,'2147483649'=>2147483649.0,'9007199254740991'=>9007199254740991.0) as $text=>$expected){
    check((float)\FleetApi\EaiTelemetryRepository::integer((string)$text)===(float)$expected,'lossless SQL integer within JSON safe range');
}
foreach(array(null,1,'01','+1','1.0','1e3','9007199254740992','-9007199254740992') as $invalid){
    rejects(function()use($invalid){\FleetApi\EaiTelemetryRepository::integer($invalid);},503,'invalid SQL numeric encoding rejected');
}
$raw=$data['telemetry'][0];foreach(\FleetApi\Contract::fields('telemetry') as $field=>$type){if($type==='n')$raw[$field]=(string)$raw[$field];}
$db=new FakeDb();$db->queue=array(array($raw));
$mapped=(new \FleetApi\EaiTelemetryRepository($db,'demo_tenant'))->read('demo_tenant','telemetry','7','2030-01-02 10:00:00','2030-01-02 10:02:00',100);
check($mapped===array($data['telemetry'][0]),'EAI seven-field mapping preserves ID text and raw value');
check($db->params[0]===array('7','2030-01-02 10:00:00','2030-01-02 10:02:00'),'EAI values bound, not interpolated');
check(strpos($db->sql[0],'CAST(`ID` AS CHAR)')!==false && strpos($db->sql[0],'LIMIT 101')!==false,'EAI SQL precision and result bound');
rejects(function()use($db){(new \FleetApi\EaiTelemetryRepository($db,'demo_tenant'))->read('other_tenant','telemetry','7','2030-01-02 10:00:00','2030-01-02 10:02:00',100);},503,'EAI tenant mismatch denied');
rejects(function()use($db){(new \FleetApi\EaiTelemetryRepository($db,'demo_tenant'))->read('demo_tenant','positions','7','2030-01-02 10:00:00','2030-01-02 10:02:00',100);},503,'unverified EAI mapping unavailable');
$db=new FakeDb();$db->queue=array(array($raw,$raw));
rejects(function()use($db){(new \FleetApi\EaiTelemetryRepository($db,'demo_tenant'))->read('demo_tenant','telemetry','7','2030-01-02 10:00:00','2030-01-02 10:02:00',1);},413,'EAI overflow no partial result');
check($api->handle('GET',pathFor('positions',array(),array('password')),array('authorization'=>'Bearer demo-local-token-2026'))['status']===503,'no new public Bearer contract');
$limiterRoot=__DIR__.'/../test-results/limiter-unit-'.getmypid().'-'.str_replace('.','',uniqid('',true));
if(!mkdir($limiterRoot,0700,true)){throw new \RuntimeException('FAIL: own limiter fixture directory');}
$realLimiter=new \FleetApi\FileRateLimiter($limiterRoot);
check($realLimiter->acquire('synthetic',60)===0,'first local rate acquisition');
check($realLimiter->acquire('synthetic',60)>0,'immediate repeat is refused');
$limitFile=$limiterRoot.'/'.hash('sha256','synthetic').'.limit';
file_put_contents($limitFile,(string)(time()-120));
check($realLimiter->acquire('synthetic',60)===0,'expired local timestamp is admitted without sleeping');
file_put_contents($limitFile,'not-a-time');
rejects(function()use($realLimiter){$realLimiter->acquire('synthetic',60);},503,'corrupt local rate state fails closed');
check($realLimiter->acquire('other-endpoint',60)===0,'independent limiter keys');
$auditFile=$limiterRoot.'/audit.ndjson';file_put_contents($auditFile,'');
$auditResponse=\FleetApi\CarrierwebResponse::raw(200,\FleetApi\CarrierwebResponse::DATE_ERROR,'application/json; charset=utf-8');
\FleetApi\SafeAudit::write($auditFile,$auditResponse,12.5);
$audit=json_decode(trim(file_get_contents($auditFile)),true);
check(array_keys($audit)===array('utc','category','httpStatus','durationMs') && $audit['category']==='date_error','audit fixed schema and legacy-200 error classification');
check(\FleetApi\SafeAudit::category(\FleetApi\CarrierwebResponse::raw(200,\FleetApi\CarrierwebResponse::CADENCE_ERROR,'application/json; charset=utf-8'))==='rate_limited','audit classifies cadence body independently of HTTP200');
echo json_encode(array('suite'=>'unit','php'=>PHP_VERSION,'checks'=>$checks,'result'=>'PASS')).PHP_EOL;
