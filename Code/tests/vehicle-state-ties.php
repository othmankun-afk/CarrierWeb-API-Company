<?php
require_once __DIR__.'/../src/EaiVehicleState.php';
require_once __DIR__.'/../src/EaiCurrentVehicleSignals.php';
error_reporting(E_ALL);set_error_handler(function($n,$m){throw new ErrorException($m,0,$n);});
$checks=0;function sc($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function sr($fn,$code){try{$fn();}catch(FleetApi\ApiException $e){sc($e->getMessage()===$code,$code);return;}throw new RuntimeException('accepted_'.$code);}
function sb($rows){return array('tenant'=>'fixture','timeBasis'=>'UTC','capturedUtc'=>'2040-01-01 00:00:05','feeds'=>array('vehicles'=>array(array('CWVehicleID'=>'7')),'telemetry'=>$rows));}
function st($id,$value='0',$time='2040-01-01 00:00:01'){return array('ID'=>$id,'CWMergeTableID'=>'20400101','CWVehicleID'=>'7','MeasuredTime'=>$time,'TransponderID'=>'17','TelemetryType'=>'60','Value0'=>$value);}
$rows=array();for($i=1;$i<=9;$i++)$rows[]=st('9007199254740993000'.$i);
$first=FleetApi\EaiVehicleState::merge(sb(array_slice($rows,0,8)));$merged=FleetApi\EaiVehicleState::merge(sb(array_slice($rows,8)),$first);
sc(count($merged['feeds']['telemetry'])===2,'nine_equal_payloads_do_not_stop_tenant');
$rules=FleetApi\EaiCurrentVehicleSignals::rules('fixture');$signal=FleetApi\EaiCurrentVehicleSignals::resolve('Door',$merged['feeds']['vehicles'][0],$merged,$rules['Door']);
sc($signal['status']==='UNKNOWN'&&$signal['reason']==='latest_event_ambiguous'&&$signal['value']==='-','ambiguity_never_becomes_known');
sc($merged['feeds']['telemetry'][1]['ID']==='90071992547409930009','large_ids_kept_without_rounding');
for($i=10;$i<=100;$i++)$merged=FleetApi\EaiVehicleState::merge(sb(array(st('9007199254740993'.str_pad((string)$i,4,'0',STR_PAD_LEFT)))),$merged);
sc(count($merged['feeds']['telemetry'])===2,'repeated_pages_do_not_grow_state');
$reversed=FleetApi\EaiVehicleState::merge(sb(array_reverse($rows)));$ordered=FleetApi\EaiVehicleState::merge(sb($rows));
sc($reversed['feeds']===$ordered['feeds'],'input_order_does_not_choose_witnesses');
$different=$rows;$different[]=st('90071992547409939999','1');$d=FleetApi\EaiVehicleState::merge(sb($different));
sc(count($d['feeds']['telemetry'])===3,'different_payload_preserved');
$unknown=FleetApi\EaiCurrentVehicleSignals::resolve('Door',$d['feeds']['vehicles'][0],$d,$rules['Door']);sc($unknown['status']==='UNKNOWN','different_payload_not_selected');
$conflict=$rows;$conflict[]=st($rows[0]['ID'],'1');sr(function()use($conflict){FleetApi\EaiVehicleState::merge(sb($conflict));},'state_event_conflict');
$newer=FleetApi\EaiVehicleState::merge(sb(array(st('1','1','2040-01-01 00:00:02'))),$d);
sc(count($newer['feeds']['telemetry'])===1,'unique_newer_event_clears_old_ties');
$known=FleetApi\EaiCurrentVehicleSignals::resolve('Door',$newer['feeds']['vehicles'][0],$newer,$rules['Door']);sc($known['status']==='KNOWN_LAST_REPORTED'&&$known['value']==='OPENED','newer_value_is_real');
$one=FleetApi\EaiVehicleState::merge(sb(array($rows[0],$rows[0])));sc(count($one['feeds']['telemetry'])===1,'same_event_replay_deduplicated');
$missing=sb(array());unset($missing['feeds']['telemetry']);$retained=FleetApi\EaiVehicleState::merge($missing,$d);sc($retained['feeds']['telemetry']===$d['feeds']['telemetry'],'absent_feed_keeps_state');
$outside=sb($rows);$outside['feeds']['vehicles']=array();sc(FleetApi\EaiVehicleState::merge($outside,$d)['feeds']['telemetry']===array(),'removed_scope_not_retained');
$future=sb(array(st('1','0','2040-01-01 00:00:06')));sr(function()use($future){FleetApi\EaiVehicleState::merge($future);},'state_event_after_capture');
$tooMany=sb(array_fill(0,50001,$rows[0]));sr(function()use($tooMany){FleetApi\EaiVehicleState::merge($tooMany);},'state_feed_bound');
foreach(array('wiredtelemetry','canbushistory') as $part){$b=sb(array());$b['feeds'][$part]=array();foreach($rows as $row){$row['TelemetrySource']='0';$row['TelemetryType0']='60';$row['Value0']='0';$b['feeds'][$part][]=$row;}$out=FleetApi\EaiVehicleState::merge($b);sc(count($out['feeds'][$part])===2,'equivalent_ties_'.$part);sc(count($b['feeds'][$part])===9,'raw_history_page_untouched_'.$part);}
echo json_encode(array('result'=>'PASS','suite'=>'vehicle-state-equal-payload-ties','checks'=>$checks,'php'=>PHP_VERSION,'externalCalls'=>0)).PHP_EOL;
