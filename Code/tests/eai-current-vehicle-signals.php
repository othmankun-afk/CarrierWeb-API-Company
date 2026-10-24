<?php
require_once __DIR__.'/../src/EaiCurrentVehicleSignals.php';
use FleetApi\EaiCurrentVehicleSignals as Current;
error_reporting(E_ALL);
set_error_handler(function($number,$message,$file,$line){throw new ErrorException($message,0,$number,$file,$line);});
$checks = 0;
function currentCheck($ok,$name){global $checks;if(!$ok){throw new RuntimeException('EAI_CURRENT_SIGNAL_TEST_FAILED: '.$name);}$checks++;}
function currentReject($fn,$reason){try{$fn();}catch(FleetApi\ApiException $e){currentCheck($e->status===503&&$e->getMessage()===$reason,$reason);return;}throw new RuntimeException('EXPECTED_REJECTION: '.$reason);}
function currentRow($type,$raw,$id='90071992547409930001',$time='2040-01-01 00:00:01',$transponder='17'){
    return array('CWVehicleID'=>'7','ID'=>$id,'CWMergeTableID'=>'20400101','MeasuredTime'=>$time,'TransponderID'=>$transponder,'TelemetryType'=>$type,'Value0'=>$raw);
}
function currentBundle(array $telemetry=array(),array $wired=array()){
    return array('tenant'=>'fixture','timeBasis'=>'UTC','capturedUtc'=>'2040-01-01 00:00:05',
        'feeds'=>array('vehicles'=>array(array('CWVehicleID'=>'7','CWTrailerID'=>'0','Ignition'=>'1')),'telemetry'=>$telemetry,'wiredtelemetry'=>$wired));
}
function currentResolve($field,array $bundle,$rule=null){$rules=Current::rules('fixture');return Current::resolve($field,$bundle['feeds']['vehicles'][0],$bundle,$rule===null?$rules[$field]:$rule);}
function currentUnknown($field,array $bundle,$reason){$r=currentResolve($field,$bundle);$marker=($field==='Battery'||$field==='Temperature')?-1000:($field==='PluggedType'?' ':'-');currentCheck($r['value']===$marker&&$r['status']==='UNKNOWN'&&$r['reason']===$reason&&!$r['isLegacyProviderParity'],$field.' unknown '.$reason);return $r;}

$rules=Current::rules('fixture');
currentCheck(count($rules)===6&&$rules['Plugged']===$rules['PluggedType'],'six explicit rules and identical hitch selection');
currentCheck(Current::selected($rules['Door'])&&Current::selected(array('source'=>'eai_signals'))&&Current::selected(array('operation'=>'eai_current_signal'))&&!Current::selected(array('source'=>'vehicles')),'partial selection cannot bypass policy validation');
foreach(array('Door','Temperature','Battery','Engine','Plugged','PluggedType') as $field){
    $b=currentBundle();unset($b['feeds']['telemetry'],$b['feeds']['wiredtelemetry']);$r=currentUnknown($field,$b,'source_missing');
    currentCheck($r['source']===null&&$r['measurementAgeSeconds']===null,'missing source has no fabricated provenance');
    currentCheck(Current::value($field,$b['feeds']['vehicles'][0],$b,$rules[$field])===$r['value'],'value dispatch '.$field);
}
foreach(array(60,61,73,74) as $type){foreach(array(0,1) as $raw){$r=currentResolve('Door',currentBundle(array(currentRow($type,$raw))));$open=in_array($type,array(73,74),true)?$raw===0:$raw===1;currentCheck($r['value']===($open?'OPENED':'CLOSED')&&$r['status']==='KNOWN_LAST_REPORTED'&&$r['measurementAgeSeconds']===4&&!$r['isLegacyProviderParity'],'normal and reversed door '.$type);}}
foreach(array(50,51,174,180) as $type){foreach(array('-2300'=>-23,'0'=>0,'725'=>7.25) as $raw=>$value){$r=currentResolve('Temperature',currentBundle(array(currentRow((string)$type,(string)$raw))));currentCheck($r['value']==$value&&$r['status']==='KNOWN_LAST_REPORTED','Celsius '.$type.' '.$raw);}}
foreach(array(153,157) as $type){foreach(array(0,2237) as $raw){$r=currentResolve('Battery',currentBundle(array(currentRow($type,$raw))));currentCheck($r['value']===$raw,'raw battery, never volts '.$type);}}
foreach(array(0=>'OFF',1=>'ON',800=>'ON',2147483647=>'ON') as $raw=>$label){$r=currentResolve('Engine',currentBundle(array(currentRow('183',(string)$raw))));currentCheck($r['value']===$label,'actual RPM '.$raw);}
foreach(array(63,67,93,151,154,156,166,169) as $type){currentUnknown('Engine',currentBundle(array(currentRow($type,1))),'source_missing');}
currentUnknown('Battery',currentBundle(array(currentRow(155,2055),currentRow(90,10,'2'))),'source_missing');
currentUnknown('Temperature',currentBundle(array(currentRow(52,725))),'source_missing');
currentUnknown('Door',currentBundle(array(currentRow(55,1),currentRow(176,1,'2'))),'source_missing');
foreach(array(71=>'QUANTA',72=>'MDT',76=>'REEFER',77=>'HITCH') as $type=>$label){foreach(array('0','1') as $raw){$b=currentBundle(array(currentRow((string)$type,$raw)));$attached=$type===71?$raw==='0':$raw==='1';$p=currentResolve('Plugged',$b);$t=currentResolve('PluggedType',$b);currentCheck($p['value']===($attached?'HITCHED':'UNHITCHED')&&$t['value']===$label&&$p['source']===$t['source'],'hitch polarity and shared event '.$type);}}
foreach(array(70=>'SENSITE_RSSI',75=>'SENSOR_NAME') as $type=>$label){foreach(array('-61'=>'UNHITCHED','-60'=>'HITCHED','0'=>'HITCHED') as $raw=>$expected){$row=currentRow((string)$type,(string)$raw);$row['MinValue']='-60';$b=currentBundle(array($row));currentCheck(currentResolve('Plugged',$b)['value']===$expected&&currentResolve('PluggedType',$b)['value']===$label,'hitch threshold '.$type);}}
$b=currentBundle();$b['feeds']['vehicles'][0]['CWTrailerID']='123';currentUnknown('Plugged',$b,'source_missing');$b['feeds']['vehicles'][0]['CWTrailerID']='0';currentUnknown('Plugged',$b,'source_missing');

$wired=array('CWVehicleID'=>7,'ID'=>'90071992547409930002','MeasuredTime'=>'2040-01-01 00:00:01','TelemetrySource'=>0,'TelemetryType99'=>'51','Value99'=>'-2300');
$r=currentResolve('Temperature',currentBundle(array(),array($wired)));currentCheck($r['value']==-23&&$r['source']['channel']===99&&$r['source']['telemetrySource']==='0','wired type/value pair and final channel');
$wired['TelemetryType0']='60';$wired['Value0']='1';currentCheck(currentResolve('Door',currentBundle(array(),array($wired)))['value']==='OPENED','wired channel zero');
$wiredHitch=$wired;$wiredHitch['TelemetryType0']='71';$wiredHitch['Value0']='0';$wb=currentBundle(array(),array($wiredHitch));currentCheck(currentResolve('Plugged',$wb)['value']==='HITCHED'&&currentResolve('PluggedType',$wb)['value']==='QUANTA','wired hitch pair');
$threshold=$wired;$threshold['TelemetryType0']='70';$threshold['Value0']='-50';currentUnknown('Plugged',currentBundle(array(),array($threshold)),'measurement_missing');$threshold['MinValue']='-60';currentCheck(currentResolve('Plugged',currentBundle(array(),array($threshold)))['value']==='HITCHED','wired threshold requires recorded minimum');

$first=currentRow('60','1');$second=$first;$second['ID']='90071992547409930002';$second['TransponderID']='18';
currentUnknown('Door',currentBundle(array($first,$second)),'source_ambiguous');$second['Value0']='0';currentUnknown('Door',currentBundle(array($first,$second)),'source_ambiguous');
$otherType=$first;$otherType['TelemetryType']='61';currentUnknown('Door',currentBundle(array($first,$otherType)),'source_ambiguous');
currentUnknown('Door',currentBundle(array($first),array($wired)),'source_ambiguous');
$twoWired=$wired;$twoWired['TelemetryType1']='60';$twoWired['Value1']='1';currentUnknown('Door',currentBundle(array(),array($twoWired)),'source_ambiguous');
$twoSources=$wired;$twoSources['TelemetrySource']='1';currentUnknown('Door',currentBundle(array(),array($wired,$twoSources)),'source_ambiguous');
$older=$first;$older['ID']='1';$older['MeasuredTime']='2039-12-31 23:59:00';$older['Value0']='0';
foreach(array(array($older,$first),array($first,$older)) as $events){$r=currentResolve('Door',currentBundle($events));currentCheck($r['value']==='OPENED'&&$r['source']['id']==='90071992547409930001','latest event within physical source, huge ID stays string');}
$tie=$first;$tie['ID']='90071992547409930003';currentUnknown('Door',currentBundle(array($first,$tie)),'latest_event_ambiguous');currentUnknown('Door',currentBundle(array($first,$first)),'latest_event_ambiguous');
$oldTie=$older;$oldTie['ID']='2';currentCheck(currentResolve('Door',currentBundle(array($older,$oldTie,$first)))['value']==='OPENED','older tie cannot poison unique latest');
$acrossPartition=$older;$acrossPartition['CWMergeTableID']='20391231';currentCheck(currentResolve('Door',currentBundle(array($first,$acrossPartition)))['value']==='OPENED','server partition is not physical source');
$oldSource=$older;$oldSource['TransponderID']='18';currentUnknown('Door',currentBundle(array($oldSource,$first)),'source_ambiguous');
$latestBad=$first;$latestBad['Value0']='2';currentUnknown('Door',currentBundle(array($older,$latestBad)),'measurement_invalid');
foreach(array(null,false,array(),'1.5','NaN','9007199254740992') as $raw){$row=$first;$row['Value0']=$raw;currentUnknown('Door',currentBundle(array($row)),$raw===null?'measurement_missing':'measurement_invalid');}
$missing=$first;unset($missing['Value0']);currentUnknown('Door',currentBundle(array($missing)),'measurement_missing');
$missingWired=$wired;unset($missingWired['Value0']);currentUnknown('Door',currentBundle(array(),array($missingWired)),'measurement_missing');
foreach(array('4294967295'=>'measurement_unknown','4294967294'=>'sensor_error','-1'=>'measurement_invalid') as $raw=>$reason){currentUnknown('Engine',currentBundle(array(currentRow('183',(string)$raw))),$reason);}
currentUnknown('Battery',currentBundle(array(currentRow('153','-1'))),'measurement_invalid');
$thresholdBad=currentRow('70','-50');$thresholdBad['MinValue']='bad';currentUnknown('Plugged',currentBundle(array($thresholdBad)),'measurement_invalid');currentUnknown('PluggedType',currentBundle(array($thresholdBad)),'measurement_invalid');
$old=currentRow('60','0','1','1901-01-01 00:00:00');$lastKnown=currentResolve('Door',currentBundle(array($old)));currentCheck($lastKnown['value']==='CLOSED'&&$lastKnown['measurementAgeSeconds']>2147483647&&$lastKnown['status']==='KNOWN_LAST_REPORTED','explicit last-known has age without fabricated freshness cutoff');

$foreign=$first;$foreign['CWVehicleID']='8';$foreign['MeasuredTime']='not-a-date';$foreign['ID']=array();currentUnknown('Door',currentBundle(array($foreign)),'source_missing');
currentCheck(currentResolve('Door',currentBundle(array($foreign,$first)))['value']==='OPENED','foreign vehicle cannot alter selected value');
$outside=array('CWVehicleID'=>'8');$b=currentBundle();currentReject(function()use($outside,$b,$rules){Current::resolve('Door',$outside,$b,$rules['Door']);},'eai_signal_vehicle_outside_scope');
foreach(array('CWVehicleID','ID','TransponderID','TelemetryType','CWMergeTableID') as $column){foreach(array(null,false,1.0,'01','-1','1e3',array()) as $bad){$row=$first;$row[$column]=$bad;$b=currentBundle(array($row));currentReject(function()use($b){currentResolve('Door',$b);},'eai_signal_source_id_invalid');}}
foreach(array(null,'2040-02-30 00:00:01','2040-01-01','0000-01-01 00:00:01') as $bad){$row=$first;$row['MeasuredTime']=$bad;$b=currentBundle(array($row));currentReject(function()use($b){currentResolve('Door',$b);},'eai_signal_event_time_invalid');}
$future=$first;$future['MeasuredTime']='2040-01-01 00:00:06';$b=currentBundle(array($future));currentReject(function()use($b){currentResolve('Door',$b);},'eai_signal_event_after_capture');
$badSource=$wired;$badSource['TelemetrySource']='bad';$b=currentBundle(array(),array($badSource));currentReject(function()use($b){currentResolve('Door',$b);},'eai_signal_source_id_invalid');
foreach(array('tenant'=>'other','timeBasis'=>'UNVERIFIED','capturedUtc'=>'2040-02-30 00:00:05') as $key=>$bad){$b=currentBundle();$b[$key]=$bad;currentReject(function()use($b){currentResolve('Door',$b);},'eai_signal_bundle_invalid');}
foreach(array('source'=>'vehicles','operation'=>'number','policy'=>'invented','tenant'=>'other','selection'=>'newest','anchor'=>'LastReportTime','maxAgeSeconds'=>0,'unknownPolicy'=>'invented') as $key=>$bad){$rule=$rules['Door'];$rule[$key]=$bad;$b=currentBundle();currentReject(function()use($b,$rule){currentResolve('Door',$b,$rule);},$key==='tenant'?'eai_signal_bundle_invalid':'eai_signal_rule_invalid');}
foreach(array_keys($rules['Door']) as $key){$rule=$rules['Door'];unset($rule[$key]);$b=currentBundle();currentReject(function()use($b,$rule){currentResolve('Door',$b,$rule);},'eai_signal_rule_invalid');}
$rule=$rules['Door'];$rule['bindings']=array();$b=currentBundle();currentReject(function()use($b,$rule){currentResolve('Door',$b,$rule);},'eai_signal_rule_invalid');
$rule=$rules['Door'];$rule['policy']=array();currentReject(function()use($b,$rule){currentResolve('Door',$b,$rule);},'eai_signal_rule_invalid');
$b=currentBundle();$b['feeds']['telemetry']=null;currentReject(function()use($b){currentResolve('Door',$b);},'eai_signal_feed_invalid');
$b=currentBundle();$b['feeds']['vehicles'][]=$b['feeds']['vehicles'][0];currentReject(function()use($b){currentResolve('Door',$b);},'eai_signal_vehicle_duplicate');
currentReject(function(){Current::rules('bad tenant');},'eai_signal_rule_invalid');
$b=currentBundle();currentReject(function()use($b,$rules){Current::validateRule('Ignition',$b,$rules['Door']);},'eai_signal_rule_invalid');
Current::validateRule('Door',array('tenant'=>'fixture','timeBasis'=>'UTC','capturedUtc'=>'2040-01-01 00:00:05'),$rules['Door']);currentCheck(true,'rule guard supports minimal validation context');
require_once __DIR__.'/../src/EaiVehicleMapping.php';
require_once __DIR__.'/../src/VehiclesServiceDiagnostic.php';
$diagnostic=new ReflectionMethod('FleetApi\\VehiclesServiceDiagnostic','resolutions');$diagnostic->setAccessible(true);
$b=currentBundle(array(currentRow('71','0')));$r=Current::rules('fixture');$r['PluggedType']['tenant']='other';
$details=$diagnostic->invoke(null,$b,$r,array('7'));
foreach(array('Plugged','PluggedType') as $field){currentCheck($details['id:7'][$field]['status']==='ERROR'&&$details['id:7'][$field]['reason']==='eai_hitch_pair_invalid'&&!isset($details['id:7'][$field]['value']),'diagnostic never reports a known half of an invalid hitch pair');}
$r=Current::rules('fixture');$details=$diagnostic->invoke(null,$b,$r,array('7'));
currentCheck($details['id:7']['Plugged']['value']==='HITCHED'&&$details['id:7']['Plugged']['source']===$details['id:7']['PluggedType']['source'],'valid diagnostic hitch pair shares event');

echo json_encode(array('result'=>'PASS','suite'=>'documented-eai-current-vehicle-signals','checks'=>$checks,'php'=>PHP_VERSION,
    'externalCalls'=>0,'serverWrites'=>0,'isLegacyProviderParity'=>false)).PHP_EOL;
