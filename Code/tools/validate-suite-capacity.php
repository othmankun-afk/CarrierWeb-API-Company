<?php
// STDIN policy validation only: no database, filesystem writes or transport.
require_once __DIR__.'/../src/Contract.php';
require_once __DIR__.'/../src/SuiteCapacityPolicy.php';
date_default_timezone_set('UTC');
try{
    $bytes=stream_get_contents(STDIN,65537);if(strlen($bytes)>65536)throw new FleetApi\ApiException(503,'capacity_policy_invalid');
    $policy=FleetApi\StrictJson::decode($bytes,true,16);if(!is_array($policy))throw new FleetApi\ApiException(503,'capacity_policy_invalid');
    $policy=FleetApi\SuiteCapacityPolicy::normalize($policy);
    echo json_encode(array('result'=>'PASS','policy'=>$policy,'eaiCalls'=>0,'databaseConnections'=>0,'filesDeleted'=>0));
}catch(Exception $e){echo json_encode(array('result'=>'FAIL','category'=>'CAPACITY_POLICY_INVALID','eaiCalls'=>0,'databaseConnections'=>0,'filesDeleted'=>0));exit(1);}
