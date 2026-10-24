<?php
require_once __DIR__.'/../src/EaiVehicleMapping.php';
use FleetApi\LegacyVehicleCounterPolicy as Counter;
error_reporting(E_ALL);set_error_handler(function($n,$m,$f,$l){throw new ErrorException($m,0,$n,$f,$l);});$checks=0;
function counterCheck($ok){global $checks;if(!$ok){throw new RuntimeException('COUNTER_POLICY_CHECK_FAILED');}$checks++;}
function counterReject($fn){try{$fn();}catch(FleetApi\ApiException $e){counterCheck(true);return;}throw new RuntimeException('COUNTER_POLICY_EXPECTED_REJECTION');}
$rule=array('source'=>'legacy_vehicles','operation'=>'legacy_vehicle_counter','policy'=>Counter::POLICY,'tenant'=>'fixture','referenceSha256'=>str_repeat('a',64));
$rules=array('EngineRunningTime'=>$rule,'EngineRunningTimeHHMM'=>$rule);$bundle=array('tenant'=>'fixture','wireContractMode'=>'carrierweb-legacy');
counterCheck(Counter::value('EngineRunningTime',$bundle,$rules)===0);counterCheck(Counter::value('EngineRunningTimeHHMM',$bundle,$rules)==='');
counterCheck(Counter::metadata($bundle,$rules)['EngineRunningTime']['isRealEaiMeasurement']===false);
counterCheck(Counter::metadata($bundle,array())===array());
foreach(array('Door','Battery','Temperature','Plugged','PluggedType','Engine') as $f){counterReject(function()use($f,$bundle,$rules){Counter::value($f,$bundle,$rules);});}
$bad=$bundle;unset($bad['wireContractMode']);counterReject(function()use($bad,$rules){Counter::value('EngineRunningTime',$bad,$rules);});
$bad=$bundle;$bad['wireContractMode']='physical';counterReject(function()use($bad,$rules){Counter::value('EngineRunningTime',$bad,$rules);});
$bad=$bundle;$bad['tenant']='foreign';counterReject(function()use($bad,$rules){Counter::value('EngineRunningTime',$bad,$rules);});
foreach(array('EngineRunningTime','EngineRunningTimeHHMM') as $f){$bad=$rules;unset($bad[$f]);counterReject(function()use($bundle,$bad){Counter::value('EngineRunningTime',$bundle,$bad);});}
foreach(array('source'=>'canbushistory','policy'=>'invented','referenceSha256'=>'bad','tenant'=>array(),'operation'=>array()) as $k=>$v){$bad=$rules;$bad['EngineRunningTime'][$k]=$v;counterReject(function()use($bundle,$bad){Counter::value('EngineRunningTime',$bundle,$bad);});}
$bad=$rules;$bad['EngineRunningTime']['value']=5;counterReject(function()use($bundle,$bad){Counter::value('EngineRunningTime',$bundle,$bad);});
$bad=$rules;$bad['EngineRunningTimeHHMM']['referenceSha256']=str_repeat('b',64);counterReject(function()use($bundle,$bad){Counter::value('EngineRunningTime',$bundle,$bad);});
$time='2030-01-01 00:00:01';$vehicle=array('CWVehicleID'=>'7');
$b=$bundle+array('capturedUtc'=>$time,'timeBasis'=>'UTC','feeds'=>array('vehicles'=>array($vehicle),'canbushistory'=>array(array('CWVehicleID'=>'7','ID'=>'1','MeasuredTime'=>$time,'EngineRunningTime'=>'90061'))));
$r=FleetApi\EaiVehicleMapping::evaluate($b,$rules,array('7'));
counterCheck($r['partial']['id:7']['EngineRunningTime']===0&&$r['partial']['id:7']['EngineRunningTimeHHMM']==='');
counterCheck(!$r['candidateComplete']&&isset($r['issues']['id:7']['Door']));
counterCheck($b['feeds']['canbushistory'][0]['EngineRunningTime']==='90061');
$none=$b;unset($none['feeds']['canbushistory']);$r=FleetApi\EaiVehicleMapping::evaluate($none,$rules,array('7'));counterCheck($r['partial']['id:7']['EngineRunningTime']===0);
$none=$b;unset($none['wireContractMode']);$r=FleetApi\EaiVehicleMapping::evaluate($none,$rules,array('7'));counterCheck(isset($r['issues']['id:7']['EngineRunningTime'])&&!isset($r['partial']['id:7']['EngineRunningTime']));
echo json_encode(array('result'=>'PASS','suite'=>'explicit-legacy-vehicles-counter-policy','checks'=>$checks,'php'=>PHP_VERSION,'externalCalls'=>0,'customerMappingVerified'=>false)).PHP_EOL;
