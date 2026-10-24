<?php
// Fictitious JSON and local files only. No HTTP/SQL/EAI connection.
require_once __DIR__.'/../src/Bootstrap.php';
require_once __DIR__.'/../demo/fixtures.php';
\FleetApi\Bootstrap::configureRuntime();
ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(E_ALL);
set_error_handler(function($s,$m,$f,$l){if(!(error_reporting() & $s)){return false;}throw new ErrorException('JSON_FIXTURE_DIAGNOSTIC',0,$s,$f,$l);});
$checks=0;
$finished=false;
register_shutdown_function(function()use(&$finished,&$checks){
    if(!$finished){$last=error_get_last();echo json_encode(array('suite'=>'json-source-and-config-integrity','result'=>'FAIL','checks'=>$checks,'php'=>PHP_VERSION,'utc'=>gmdate('Y-m-d\TH:i:s\Z'),'category'=>'FATAL_FIXTURE_DIAGNOSTICS_WITHHELD','fixtureLine'=>$last?$last['line']:null,'apiCalls'=>0,'eaiCycles'=>0,'databaseConnections'=>0)).PHP_EOL;}
});
function integrityCheck($ok,$label){global $checks;if(!$ok){throw new RuntimeException('JSON_INTEGRITY_FAIL: '.$label);} $checks++;}
function integrityReject($call,$label){try{$call();}catch(\FleetApi\ApiException $e){integrityCheck($e->status===503,$label);return;}throw new RuntimeException('DUPLICATE_JSON_ACCEPTED: '.$label);}
$vehicle=fleet_demo_rows()['vehicles'][0];$json=json_encode(array($vehicle));
$duplicate=str_replace('"License":','"License":"FICTITIOUS_FIRST","License":',$json);
try{
    integrityReject(function()use($duplicate){\FleetApi\Contract::decodeArray($duplicate);},'representation duplicate key');
    integrityReject(function()use($json){\FleetApi\Contract::decodeArray(str_replace('"License":','"\\u004cicense":"FICTITIOUS_FIRST","License":',$json));},'escaped equivalent key');
    integrityReject(function(){\FleetApi\Contract::decodeArray('[{"extra":{"x":1,"x":2}}]');},'nested duplicate');
    $escaped=json_encode(array(array('x'=>'a"b','y'=>'\\','z'=>"\xF0\x9F\x9A\x9A")));
    foreach(array($json," \r\n".$json.' ', '[1,2,3]', '[{},[],null,false,true]', $escaped) as $valid){
        integrityCheck(\FleetApi\Contract::decodeArray($valid)===json_decode($valid,true,64),'valid JSON value/type preservation');
    }
    foreach(array('[{"x":1,"x":1}]','[{"x":null,"x":1}]','[{"x":1,"\\u0078":1}]','[{"1":1,"1":2}]','[{"":1,"":2}]','[{"a\\u0000b":1,"a\\u0000b":2}]') as $bad){
        integrityReject(function()use($bad){\FleetApi\Contract::decodeArray($bad);},'decoded duplicate object keys');
    }
    foreach(array('[{"x":1,"X":2}]','[{"01":1,"1":2}]','[{"a":{"x":1},"b":{"x":2}}]','[{"x":1},{"x":2}]') as $valid){
        integrityCheck(\FleetApi\Contract::decodeArray($valid)===json_decode($valid,true,64),'separate names/scopes remain valid');
    }
    // Differential fixtures: escaping, Unicode, scalar types, nesting and scopes.
    // None of these synthetic values is a customer record or an inferred unit.
    $keys=array('', 'x', 'X', '01', '1', 'key:', 'a"b', 'a\\b', "a\0b", "\xC3\xA9", "\xF0\x9F\x9A\x9A");
    $values=array(null,true,false,0,1,-1,1.25,1e-8,'', '00123','  ', 'a"b', 'a\\b', "\xC3\xA9",array(),array(1,2),array('inside'=>array('x'=>1)));
    foreach($keys as $key){
        foreach($values as $value){
            $valid='[{'.json_encode($key).':'.json_encode($value).'}]';
            integrityCheck(\FleetApi\StrictJson::decode($valid,true,64)===json_decode($valid,true,64),'synthetic escaped key/value preservation');
        }
        $token=json_encode($key);$bad='[{'.$token.':1,'.$token.':2}]';
        integrityReject(function()use($bad){\FleetApi\Contract::decodeArray($bad);},'escaped duplicate key');
    }
    foreach(array('{"a":"quoted \\" value"}', '{"a":"trailing \\\\"}', '{"a":1e2,"b":-0.0,"c":true,"d":null}', '{"x":{"a":1},"y":{"a":2}}', 'null','true','1','"scalar"') as $valid){
        // Round-trip compare object mode without object-identity comparison.
        $expected=json_decode($valid,false,64);integrityCheck(json_last_error()===JSON_ERROR_NONE,'positive fixture grammar');
        integrityCheck(json_encode(\FleetApi\StrictJson::decode($valid,false,64))===json_encode($expected),'object-mode PHP semantics preserved');
    }
    foreach(array('', ' ', '[', '[1,]', '{"a":1,}', '{a:1}', '[01]', '[NaN]', '[] trailing', "[\"\xFF\"]", '["\\ud800"]', '["\\udc00"]', '["\\ud800\\u0000"]', '["\\ud800\\ud800"]', '[{"\\ud800":1}]', '["\\x00"]') as $invalid){
        // PHP5.6 does not flag the empty string consistently; the scanner still
        // rejects it as an absent JSON value. Do not equate that quirk to valid JSON.
        $rejected=false;try{\FleetApi\StrictJson::decode($invalid,true,64);}catch(\FleetApi\JsonDecodeException $e){$rejected=$e->getMessage()==='json_invalid';}
        integrityCheck($rejected,'malformed JSON refused with fixed diagnostic');
    }
    foreach(array('["\\ud83d\\ude9a"]','["\\\\ud800"]','["\\ud800\\udc00"]','["\\uDBFF\\uDFFF"]') as $valid){
        integrityCheck(\FleetApi\StrictJson::decode($valid,true,64)===json_decode($valid,true,64),'paired Unicode and escaped literals preserved');
    }
    $encodedDuplicate='[{"\\ud83d\\ude9a":1,"'."\xF0\x9F\x9A\x9A".'":2}]';
    integrityReject(function()use($encodedDuplicate){\FleetApi\Contract::decodeArray($encodedDuplicate);},'supplementary Unicode equivalent keys');
    foreach(array(1,2,3,8,64,128) as $depth){
        foreach(array(0,1,2,3,7,63,127,129) as $nesting){
            $candidate=str_repeat('[',$nesting).'1'.str_repeat(']',$nesting);
            $expected=json_decode($candidate,true,$depth);$phpValid=json_last_error()===JSON_ERROR_NONE;
            $accepted=false;try{$actual=\FleetApi\StrictJson::decode($candidate,true,$depth);$accepted=true;}catch(\FleetApi\JsonDecodeException $e){}
            integrityCheck($accepted===$phpValid,'depth validity agrees with PHP');
            if($accepted){integrityCheck($actual===$expected,'depth value agrees with PHP');}
        }
    }
    foreach(array(array(null,true,64),array('[]',1,64),array('[]',true,0),array('[]',true,129),array('[]',true,'64')) as $args){
        $rejected=false;try{\FleetApi\StrictJson::decode($args[0],$args[1],$args[2]);}catch(\FleetApi\JsonDecodeException $e){$rejected=$e->getMessage()==='json_invalid';}
        integrityCheck($rejected,'invalid decoder options refused');
    }
    $run=__DIR__.'/../test-results/json-integrity-'.getmypid().'-'.str_replace('.','',uniqid('',true));
    integrityCheck(mkdir($run,0700,true),'own fictitious directory');$file=$run.'/source.json';
    $ordinary='{"tenant":"fictitious_tenant","endpoints":{"vehicles":'.$json.'}}';
    integrityCheck(file_put_contents($file,$ordinary)===strlen($ordinary),'own fictitious source');
    $reader=new \FleetApi\JsonFileRepository(array('fictitious_tenant'=>$file),1048576);
    integrityCheck($reader->read('fictitious_tenant','vehicles',null,null,null,100)===array($vehicle),'valid represented source unchanged');
    foreach(array(str_replace('"tenant":','"tenant":"FICTITIOUS_OTHER","tenant":',$ordinary),str_replace('"vehicles":','"vehicles":[],"vehicles":',$ordinary),str_replace('"License":','"License":"FICTITIOUS_FIRST","License":',$ordinary)) as $bad){
        file_put_contents($file,$bad);integrityReject(function()use($reader){$reader->read('fictitious_tenant','vehicles',null,null,null,100);},'ambiguous source envelope/row');
    }
    $config=array('accounts'=>fleet_demo_accounts(),'options'=>array('maxRows'=>100,'maxResponseBytes'=>1048576,'minIntervalSeconds'=>0),'source'=>array('driver'=>'json','files'=>array('demo_tenant'=>$file),'maxFileBytes'=>1048576),'rateLimitDirectory'=>$run);
    $configFile=$run.'/fictitious-config.json';$configText=json_encode($config);file_put_contents($configFile,$configText);putenv('FLEET_API_CONFIG='.$configFile);
    integrityCheck(\FleetApi\Bootstrap::configuredApi() instanceof \FleetApi\Api,'valid local config accepted');
    foreach(array(str_replace('"accounts":','"accounts":{},"accounts":',$configText),str_replace('"tenant":','"tenant":"FICTITIOUS_OTHER","tenant":',$configText),str_replace('"maxRows":','"maxRows":1,"maxRows":',$configText)) as $bad){file_put_contents($configFile,$bad);integrityReject(function(){\FleetApi\Bootstrap::configuredApi();},'ambiguous configuration');}
    putenv('FLEET_API_CONFIG');
    class IntegrityStatement {
        private $rows;public function __construct($rows){$this->rows=$rows;}
        public function execute($values){return true;}public function fetch($unused=null){return array_shift($this->rows);}
    }
    class IntegrityDatabase {public $queue;public function __construct($queue){$this->queue=$queue;}public function prepare($sql){return new IntegrityStatement(array_shift($this->queue));}}
    $raw=fleet_demo_rows()['positions'][0];$payload=json_encode($raw);$badPayload=str_replace('"Location":','"Location":"FICTITIOUS_FIRST","Location":',$payload);
    $db=new IntegrityDatabase(array(array(array('dataset_ready'=>'1')),array(array('vehicle_id'=>'7','record_id'=>$raw['ID'],'measured_at'=>$raw['MeasuredTime'],'payload_json'=>$badPayload))));
    integrityReject(function()use($db){(new \FleetApi\PdoRepository($db))->read('demo_tenant','positions','7','2030-01-02 10:00:00','2030-01-02 10:02:00',100);},'ambiguous SQL history JSON payload');
    $nativeObservations=array();
    foreach(array('empty_text'=>'','unpaired_surrogate'=>'["\\ud800"]','paired_surrogates'=>'["\\ud83d\\ude9a"]') as $name=>$candidate){
        $native=json_decode($candidate,true,64);$nativeError=json_last_error();$strictAccepted=false;
        try{\FleetApi\StrictJson::decode($candidate,true,64);$strictAccepted=true;}catch(\FleetApi\JsonDecodeException $e){}
        $nativeObservations[]=array('fictitiousCase'=>$name,'nativeJsonError'=>$nativeError,'nativeReencoded'=>json_encode($native),'strictAccepted'=>$strictAccepted);
    }
    $finished=true;
    echo json_encode(array('suite'=>'json-source-and-config-integrity','result'=>'PASS','checks'=>$checks,'php'=>PHP_VERSION,'utc'=>gmdate('Y-m-d\TH:i:s\Z'),'runDirectory'=>$run,'nativeDecoderObservations'=>$nativeObservations,'apiCalls'=>0,'eaiCycles'=>0,'databaseConnections'=>0,'limits'=>array('Fictitious/in-memory SQL double only','Generated files retained, not deleted','Rejecting ambiguous local sources is not new Carrierweb HTTP behavior'))).PHP_EOL;
}catch(Exception $e){
    $finished=true;
    echo json_encode(array('suite'=>'json-source-and-config-integrity','result'=>'FAIL','checks'=>$checks,'php'=>PHP_VERSION,'utc'=>gmdate('Y-m-d\TH:i:s\Z'),'category'=>strpos($e->getMessage(),'DUPLICATE_JSON_ACCEPTED:')===0?'DUPLICATE_JSON_ACCEPTED':'FIXTURE_FAILED_DIAGNOSTICS_WITHHELD','apiCalls'=>0,'eaiCycles'=>0,'databaseConnections'=>0)).PHP_EOL;exit(1);
}
