<?php
require_once __DIR__.'/../src/Bootstrap.php';
require_once __DIR__.'/../src/EaiCursorState.php';
require_once __DIR__.'/../demo/fixtures.php';
$checks=0;
function pc($ok,$name){global $checks;if(!$ok)throw new RuntimeException($name);$checks++;}
function pr($fn){try{$fn();}catch(FleetApi\ApiException $e){pc(true,'rejected');return;}throw new RuntimeException('UNEXPECTED_ACCEPTANCE');}
try{
 foreach(array(array('0','1','1'),array('20','3','20'),array('7','7','7'),array('999999999999999998','999999999999999999','999999999999999999'),array('1000000000000000000','999999999999999999','1000000000000000000')) as $t)pc(FleetApi\EaiCursorState::advance($t[0],$t[1])===$t[2],'exact_decimal_high_water');
 $old=array('0'=>'0','20260101'=>'202601019999999','20260102'=>'202601020000123');$metadata=array('20260101'=>'202601019999999','20260102'=>'202601010000456');
 pc(FleetApi\EaiCursorState::advance($old,$metadata)===$old,'earlier_partition_metadata_does_not_regress_current_day');
 $next=FleetApi\EaiCursorState::advance($old,array('20260102'=>'202601020000124','20260103'=>'202601030000001'));
 pc($next['20260102']==='202601020000124'&&$next['20260103']==='202601030000001'&&$next['20260101']===$old['20260101'],'advance_and_preserve_other_partitions');
 pc(FleetApi\EaiCursorState::advance(array('0'=>'9','2'=>'12'),'10')===array('0'=>'10','2'=>'12'),'legacy_scalar_updates_slot_zero_only');
 foreach(array('01','-1','1.0','1e3',1,1.0,true,null,str_repeat('9',20),array('bad'=>'1'),array('0'=>array('0'=>'1'))) as $bad)pr(function()use($bad){FleetApi\EaiCursorState::advance('0',$bad);});
 $before=array('positions'=>array('0'=>'9'),'telemetry'=>$old);$received=array('positions'=>array('0'=>'10'),'telemetry'=>$metadata+array('0'=>'0'));
 $reviewed=FleetApi\EaiCursorState::reviewedPage($received,$before);pc($reviewed['positions']['0']==='10'&&$reviewed['telemetry']===$old,'reviewed_page_retains_every_high_water');
 pr(function()use($before){FleetApi\EaiCursorState::reviewedPage(array('positions'=>array('0'=>'10')),$before);});
 pr(function()use($before){FleetApi\EaiCursorState::reviewedPage(array('positions'=>array('0'=>'10'),'telemetry'=>array('0'=>'0')),$before);});
 $fixtures=fleet_demo_rows();
 foreach(array('positions','canbus','telemetry') as $endpoint){
  $rows=$fixtures[$endpoint];$fields=FleetApi\Contract::fields($endpoint);foreach($fields as $f=>$type)if($type==='s'&&!in_array($f,array('ID','MeasuredTime'),true))$rows[0][$f]='';
  $unchanged=$rows;$wire=FleetApi\CarrierwebResponse::eaiHistoryRows($endpoint,$rows);pc($rows===$unchanged,'stored_rows_not_mutated');
  foreach($fields as $f=>$type){if($type==='s'&&!in_array($f,array('ID','MeasuredTime'),true))pc($wire[0][$f]===' ','empty_uses_original_sdk_space');else pc($wire[0][$f]===$rows[0][$f],'identity_time_and_numeric_values_preserved');}
  foreach(array(' ','  ','0','text  ') as $value){$field=$endpoint==='positions'?'Zipcode':($endpoint==='canbus'?'DriverName':'TransponderDescription');$r=$rows;$r[0][$field]=$value;pc(FleetApi\CarrierwebResponse::eaiHistoryRows($endpoint,$r)[0][$field]===$value,'nonempty_text_exact');}
 }
 pc(FleetApi\CarrierwebResponse::eaiHistoryRows('vehicles',$fixtures['vehicles'])===$fixtures['vehicles'],'current_snapshot_not_normalized');
 echo json_encode(array('result'=>'PASS','suite'=>'sdk_cursor_and_history_text_compatibility','checks'=>$checks,'php'=>PHP_VERSION,'eaiCalls'=>0,'databaseConnections'=>0,'storedRowsChanged'=>0)).PHP_EOL;
}catch(Exception $e){echo json_encode(array('result'=>'FAIL','checks'=>$checks,'category'=>$e->getMessage())).PHP_EOL;exit(1);}
