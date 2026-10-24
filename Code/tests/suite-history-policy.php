<?php
require_once __DIR__.'/../src/Bootstrap.php';
FleetApi\Bootstrap::configureRuntime();
$checks=0;
function hpcheck($value,$label){global $checks;if(!$value)throw new RuntimeException($label);$checks++;}
function hpreject($policy,$from,$to,$now,$code,$status=422){try{FleetApi\SuiteHistoryPolicy::enforce($policy,$from,$to,$now);}catch(FleetApi\ApiException $e){hpcheck($e->getMessage()===$code&&$e->status===$status,'expected_'.$code);return;}throw new RuntimeException('UNEXPECTED_ACCEPTANCE');}
try{
    $now=strtotime('2026-10-18 12:00:00 UTC');
    // Synthetic values test the configurable mechanism only; they are not a
    // chosen policy, vendor limit, or current installation configuration.
    $p=array('lookbackDays'=>30,'maxQuerySpanDays'=>7);
    hpcheck(FleetApi\SuiteHistoryPolicy::defaults()===null,'default_policy_not_invented');
    $d=FleetApi\SuiteHistoryPolicy::describe($p,$now);hpcheck($d['earliestPermittedUtc']==='2026-09-18 12:00:00','rolling_utc_boundary');
    hpcheck(!$d['deletesStoredData']&&!$d['automaticEaiBackfill']&&!$d['upstreamRetentionVerified'],'no_false_guarantees');
    hpcheck(FleetApi\SuiteHistoryPolicy::enforce($p,'2026-09-18 12:00:00','2026-09-25 12:00:00',$now)['inPolicy'],'both_limits_inclusive');
    hpreject($p,'2026-09-18 11:59:59','2026-09-18 12:00:00',$now,'history_lookback_exceeded');
    hpreject($p,'2026-10-01','2026-10-08 00:00:01',$now,'history_query_span_exceeded');
    hpreject($p,'2026-10-18 12:00:01','2026-10-19',$now,'history_future_query');
    hpreject($p,'2026-10-10','2026-10-09',$now,'history_reversed_window');
    hpcheck(FleetApi\SuiteHistoryPolicy::enforce($p,'2026-10-18','2026-10-18 23:59:59',$now)['inPolicy'],'today_full_day_allowed_collected_only');
    hpcheck(FleetApi\SuiteHistoryPolicy::enforce($p,'2026-10-18 12:00:00','2026-10-18 12:00:00',$now)['inPolicy'],'instant_inclusive');
    hpcheck(FleetApi\SuiteHistoryPolicy::enforce(null,'2000-01-01','2099-01-01',$now)['inPolicy'],'legacy_absent_policy_no_new_limit');
    hpcheck(FleetApi\SuiteHistoryPolicy::enforce(null,'2099-01-01','2000-01-01',$now)['inPolicy'],'legacy_reversed_unmodified');
    hpcheck(FleetApi\SuiteHistoryPolicy::window($p,null,null,$now)['provided']===false,'diagnostic_window_optional');
    hpcheck(FleetApi\SuiteHistoryPolicy::window($p,'2000-01-01','2000-01-02',$now)['rejectionCode']==='history_lookback_exceeded','diagnostic_reports_rejection_without_throwing');
    hpreject($p,'2026-02-30','2026-03-01',$now,'invalid_date',400);
    hpreject($p,null,'2026-10-18',$now,'missing_parameter',400);
    foreach(array(array(),array('lookbackDays'=>'30','maxQuerySpanDays'=>7),array('lookbackDays'=>0,'maxQuerySpanDays'=>1),array('lookbackDays'=>30,'maxQuerySpanDays'=>31),array('lookbackDays'=>24856,'maxQuerySpanDays'=>7),array('lookbackDays'=>30,'maxQuerySpanDays'=>7,'other'=>1)) as $bad){try{FleetApi\SuiteHistoryPolicy::normalize($bad);}catch(FleetApi\ApiException $e){hpcheck($e->getMessage()==='history_policy_invalid','bad_config');continue;}throw new RuntimeException('BAD_POLICY_ACCEPTED');}
    echo json_encode(array('result'=>'PASS','suite'=>'local-history-query-policy','checks'=>$checks,'php'=>PHP_VERSION,'externalCalls'=>0,'databaseConnections'=>0,'filesDeleted'=>0)).PHP_EOL;
}catch(Exception $e){echo json_encode(array('result'=>'FAIL','suite'=>'local-history-query-policy','category'=>$e->getMessage(),'checks'=>$checks)).PHP_EOL;exit(1);}
