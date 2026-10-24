<?php
require_once __DIR__.'/../src/Bootstrap.php';
require_once __DIR__.'/../demo/fixtures.php';
error_reporting(E_ALL);set_error_handler(function($n,$m){throw new ErrorException($m,0,$n);});
$checks=0;function suiteCheck($v,$message){global $checks;if(!$v)throw new RuntimeException($message);$checks++;}
function suiteReject($call,$message){try{$call();}catch(FleetApi\ApiException $e){suiteCheck(true,$message);return;}throw new RuntimeException('accepted_'.$message);}
$raw=array();foreach(array('positions','canbus','telemetry') as $endpoint){foreach(fleet_demo_rows()[$endpoint] as $row){
    if($endpoint==='canbus')$row['MeasuredTime']=FleetApi\Contract::eventTime('canbus',$row['MeasuredTime']);
    if($endpoint==='positions'){foreach(array('Latitude','Longitude') as $f)$row[$f]=(string)round($row[$f]*100000);}
    foreach($row as $f=>$v){if(is_int($v)||is_float($v))$row[$f]=sprintf('%.0F',$v);}
    $raw[$endpoint==='canbus'?'canbushistory':$endpoint][]=$row;
}}
$raw['vehicles']=array(array('CWVehicleID'=>'7'));$bundle=array('tenant'=>'synthetic','timeBasis'=>'UTC','capturedUtc'=>'2030-01-02 11:00:00','feeds'=>$raw);
foreach(array('positions'=>14,'canbus'=>33,'telemetry'=>7) as $endpoint=>$fields){
    $r=FleetApi\EaiSuiteMapping::history($bundle,$endpoint);suiteCheck(count($r)===3,'rows');suiteCheck(count($r[0])===$fields,'shape');suiteCheck($r[0]['ID']==='90071992547409930','id_not_rounded');
    foreach($r as $row)FleetApi\Contract::row($endpoint,$row);
    $bad=$bundle;unset($bad['feeds'][$endpoint==='canbus'?'canbushistory':$endpoint]);suiteReject(function()use($bad,$endpoint){FleetApi\EaiSuiteMapping::history($bad,$endpoint);},'missing_feed');
    $part=$endpoint==='canbus'?'canbushistory':$endpoint;$bad=$bundle;$bad['feeds'][$part]=array();suiteCheck(FleetApi\EaiSuiteMapping::history($bad,$endpoint)===array(),'known_empty');
    foreach(array('ID','CWVehicleID','MeasuredTime') as $field){$bad=$bundle;$bad['feeds'][$part][0][$field]=null;suiteReject(function()use($bad,$endpoint){FleetApi\EaiSuiteMapping::history($bad,$endpoint);},'missing_identity_or_time');}
    $bad=$bundle;$bad['feeds'][$part][0]['MeasuredTime']='2030-01-03 10:00:00';suiteReject(function()use($bad,$endpoint){FleetApi\EaiSuiteMapping::history($bad,$endpoint);},'future_event');
    $bad=$bundle;$bad['feeds'][$part][0]['ID']=9007199254740992.0;suiteReject(function()use($bad,$endpoint){FleetApi\EaiSuiteMapping::history($bad,$endpoint);},'float_id');
    $bad=$bundle;$bad['feeds'][$part][1]['ID']=$bad['feeds'][$part][0]['ID'];suiteReject(function()use($bad,$endpoint){FleetApi\EaiSuiteMapping::history($bad,$endpoint);},'duplicate_id');
    $bad=$bundle;$bad['feeds'][$part][0]['CWVehicleID']='8';suiteCheck(count(FleetApi\EaiSuiteMapping::history($bad,$endpoint))===2,'foreign_vehicle_excluded');
    $bad=$bundle;$bad['timeBasis']='LOCAL';suiteReject(function()use($bad,$endpoint){FleetApi\EaiSuiteMapping::history($bad,$endpoint);},'unverified_timezone');
}
$p=FleetApi\EaiSuiteMapping::history($bundle,'positions');suiteCheck(abs($p[0]['Latitude']-12.34567)<0.000001,'coordinates_scaled');suiteCheck($p[0]['GPSOdometer']===12345,'odometer_raw');
$nullText=$bundle;$nullText['feeds']['positions'][0]['Location']=null;suiteCheck(FleetApi\EaiSuiteMapping::history($nullText,'positions')[0]['Location']===' ','explicit_null_text_uses_original_sdk_space');
$missingText=$bundle;unset($missingText['feeds']['positions'][0]['Location']);suiteReject(function()use($missingText){FleetApi\EaiSuiteMapping::history($missingText,'positions');},'absent_text_key_is_not_explicit_null');
$nullNumber=$bundle;$nullNumber['feeds']['positions'][0]['Latitude']=null;suiteReject(function()use($nullNumber){FleetApi\EaiSuiteMapping::history($nullNumber,'positions');},'null_coordinate_never_zero');
$t=FleetApi\EaiSuiteMapping::history($bundle,'telemetry');suiteCheck($t[0]['Value0']===1234,'telemetry_raw');suiteCheck($t[0]['TransponderDescription']==='SYNTHETIC SENSOR  ','text_spaces_preserved');
$c=FleetApi\EaiSuiteMapping::history($bundle,'canbus');suiteCheck($c[0]['MeasuredTime']==='02.01.2030 10:00:00','canbus_date_wire');suiteCheck($c[0]['DriverNumber']===''&&$c[0]['DriverName']==='  ','canbus_difference_not_hidden');suiteCheck((float)$c[0]['Odometer']===2147483649.0,'canbus_safe32bit');
foreach(array('123.4999'=>123,'123.5000'=>124,'-123.5000'=>-124,'-0.4000'=>0,'-0.5000'=>-1) as $input=>$expected){suiteCheck(FleetApi\EaiSuiteMapping::sdkGramInteger($input)===$expected,'sdk_gram_rounding');}
suiteReject(function(){FleetApi\EaiSuiteMapping::sdkGramInteger('9007199254740991.5000');},'rounded_unsafe_integer');
suiteReject(function(){FleetApi\EaiSuiteMapping::sdkGramInteger('1e4');},'co2_exponent');
$document=array('profile'=>FleetApi\EaiSuiteMapping::PROFILE,'tenant'=>'synthetic','scopeMode'=>'eai-account','vehicleIds'=>array('7'),'sourceSha256'=>str_repeat('a',64),'sourceCapturedUtc'=>$bundle['capturedUtc'],'endpoints'=>array());
foreach(array('positions','canbus','telemetry') as $endpoint)$document['endpoints'][$endpoint]=FleetApi\EaiSuiteMapping::history($bundle,$endpoint);
$repository=new FleetApi\EaiSuiteRepository(array('tenants'=>array('synthetic'=>array())));
$r=$repository->readDocument('synthetic','telemetry','7','2030-01-02 10:00:00','2030-01-02 10:01:00',100,$document);suiteCheck(count($r)===2,'inclusive_window');suiteCheck($repository->verifiedVehicleScope('synthetic')===array('7'),'dynamic_scope');
suiteReject(function()use($repository,$document){$repository->readDocument('other','telemetry','7','2030-01-02 10:00:00','2030-01-02 10:01:00',100,$document);},'tenant_mismatch');
suiteReject(function()use($repository){$repository->verifiedVehicleScope('synthetic');},'scope_reset_after_failed_read');
suiteReject(function()use($repository,$document){$repository->readDocument('synthetic','telemetry','8','2030-01-02 10:00:00','2030-01-02 10:01:00',100,$document);},'foreign_request');
suiteReject(function()use($repository,$document){$repository->readDocument('synthetic','telemetry','7','2030-01-02 10:00:00','2030-01-02 10:02:00',1,$document);},'row_bound');
echo json_encode(array('result'=>'PASS','suite'=>'eai-suite-history-mapping','php'=>PHP_VERSION,'checks'=>$checks,'externalCalls'=>0,'productionReady'=>false)).PHP_EOL;
