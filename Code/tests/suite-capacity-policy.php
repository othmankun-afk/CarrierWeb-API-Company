<?php
require_once __DIR__.'/../src/Bootstrap.php';
require_once __DIR__.'/../src/SuiteCapacityPolicy.php';
FleetApi\Bootstrap::configureRuntime();
$checks=0;
function cpcheck($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function cpreject($fn,$code){try{$fn();}catch(FleetApi\ApiException $e){cpcheck($e->status===503&&$e->getMessage()===$code,'expected_'.$code);return;}throw new RuntimeException('UNEXPECTED_ACCEPTANCE');}
function cppolicy(){return array('version'=>'EAI_SUITE_CAPACITY_V1','maxTenantEvents'=>120000,'maxTenantPayloadBytes'=>120000000,'maxPublicationEvents'=>1000,'warningPercent'=>80,'evidence'=>array('reference'=>'FICTITIOUS-CAPACITY-FIXTURE-NOT-DEPLOYMENT','sha256'=>str_repeat('b',64),'approvedBy'=>'synthetic-test-owner','approvedUtc'=>'2026-10-20 00:00:00','measuredHistoryBytesPerEvent'=>1500,'historyBudgetBytes'=>225000000,'safetyMarginPercent'=>20));}
try{
    $legacy=FleetApi\SuiteCapacityPolicy::describe(null,99999,999);cpcheck(!$legacy['configured']&&$legacy['maxTenantEvents']===100000,'legacy_unchanged');cpcheck($legacy['maxTenantPayloadBytes']===null&&$legacy['maxPublicationEvents']===null,'no_invented_byte_or_page_limit');cpcheck(!$legacy['deletesStoredData']&&!$legacy['retentionActivated']&&!$legacy['physicalDiskAvailabilityVerified'],'no_false_operational_guarantee');
    cpcheck(FleetApi\SuiteCapacityPolicy::enforce(null,100000,0)['remainingEvents']===0,'exact_100000_accepted');cpreject(function(){FleetApi\SuiteCapacityPolicy::enforce(null,100001,0);},'suite_history_capacity_review_required');cpreject(function(){FleetApi\SuiteCapacityPolicy::preflight(null,100000,0);},'suite_history_capacity_review_required');cpcheck(!FleetApi\SuiteCapacityPolicy::preflight(null,99999,0)['full'],'preflight_before_transport_when_headroom_exists');
    $p=cppolicy();cpcheck(FleetApi\SuiteCapacityPolicy::normalize($p)===$p,'explicit_approval_normalized');cpcheck(FleetApi\SuiteCapacityPolicy::enforce($p,100001,100000000)['remainingEvents']===19999,'explicit_evidence_allows_higher_budget_not_global_raise');cpcheck(FleetApi\SuiteCapacityPolicy::enforce($p,120000,120000000)['full'],'approved_row_and_byte_bound_inclusive');
    cpreject(function()use($p){FleetApi\SuiteCapacityPolicy::enforce($p,120001,100);},'suite_history_capacity_review_required');cpreject(function()use($p){FleetApi\SuiteCapacityPolicy::enforce($p,1,120000001);},'suite_history_payload_capacity_review_required');cpreject(function()use($p){FleetApi\SuiteCapacityPolicy::preflight($p,119999,120000000);},'suite_history_capacity_review_required');
    cpcheck(!FleetApi\SuiteCapacityPolicy::describe($p,95999,95999999)['warning'],'below_both_warning_thresholds');cpcheck(FleetApi\SuiteCapacityPolicy::describe($p,96000,0)['warning'],'row_warning_inclusive');cpcheck(FleetApi\SuiteCapacityPolicy::describe($p,0,96000000)['warning'],'byte_warning_inclusive');
    cpcheck(FleetApi\SuiteCapacityPolicy::enforcePage($p,1000)===1000,'page_bound_inclusive');cpreject(function()use($p){FleetApi\SuiteCapacityPolicy::enforcePage($p,1001);},'suite_publication_capacity_review_required');cpcheck(FleetApi\SuiteCapacityPolicy::enforcePage(null,1000001)===1000001,'legacy_page_behavior_unchanged');
    $bad=array();foreach(array('version','maxTenantEvents','maxTenantPayloadBytes','maxPublicationEvents','warningPercent','evidence') as $key){$v=$p;unset($v[$key]);$bad[]=$v;}$v=$p;$v['extra']=true;$bad[]=$v;
    foreach(array('0',0,-1,1.5,true,2147483648.0) as $value){$v=$p;$v['maxTenantEvents']=$value;$bad[]=$v;}
    foreach(array('maxTenantPayloadBytes','maxPublicationEvents') as $key){$v=$p;$v[$key]=0;$bad[]=$v;}$v=$p;$v['maxPublicationEvents']=120001;$bad[]=$v;
    foreach(array(0,100,'80',80.0) as $value){$v=$p;$v['warningPercent']=$value;$bad[]=$v;}
    foreach(array('reference','sha256','approvedBy','approvedUtc','measuredHistoryBytesPerEvent','historyBudgetBytes','safetyMarginPercent') as $key){$v=$p;unset($v['evidence'][$key]);$bad[]=$v;}
    foreach(array('reference'=>"bad\nreference",'sha256'=>str_repeat('a',63),'approvedBy'=>'','approvedUtc'=>'2026-02-30 00:00:00','measuredHistoryBytesPerEvent'=>0,'historyBudgetBytes'=>1000,'safetyMarginPercent'=>100) as $key=>$value){$v=$p;$v['evidence'][$key]=$value;$bad[]=$v;}
    $v=$p;$v['maxTenantEvents']=120001;$bad[]=$v; // Physical budget supports exactly 120000 records.
    $v=$p;$v['maxTenantPayloadBytes']=180000001;$bad[]=$v; // Explicit reserved budget cannot be reused.
    foreach($bad as $v)cpreject(function()use($v){FleetApi\SuiteCapacityPolicy::normalize($v);},'capacity_policy_invalid');
    foreach(array(array(-1,0),array(1,-1),array('1',0),array(1.5,0),array(1,INF),array(1,NAN)) as $usage)cpreject(function()use($usage){FleetApi\SuiteCapacityPolicy::describe(null,$usage[0],$usage[1]);},'suite_capacity_usage_invalid');
    cpreject(function()use($p){FleetApi\SuiteCapacityPolicy::enforce($p,1,3000000000.0);},'suite_history_payload_capacity_review_required');cpcheck(FleetApi\SuiteCapacityPolicy::describe(null,1,3000000000.0)['storedTenantPayloadBytes']===3000000000.0,'32bit_usage_not_truncated');
    cpreject(function()use($p){FleetApi\SuiteCapacityPolicy::enforcePage($p,-1);},'suite_capacity_usage_invalid');
    echo json_encode(array('result'=>'PASS','suite'=>'explicit-evidence-capacity-policy','checks'=>$checks,'php'=>PHP_VERSION,'legacy100000BoundaryTested'=>true,'approvedHigherBudgetFixtureTested'=>true,'sizingValuesAreFictitiousNotDeployed'=>true,'externalCalls'=>0,'databaseConnections'=>0,'rowsDeleted'=>0,'filesDeleted'=>0)).PHP_EOL;
}catch(Exception $e){echo json_encode(array('result'=>'FAIL','suite'=>'explicit-evidence-capacity-policy','category'=>$e->getMessage(),'checks'=>$checks)).PHP_EOL;exit(1);}
