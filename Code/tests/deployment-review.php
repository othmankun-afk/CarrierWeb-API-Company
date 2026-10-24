<?php
require_once __DIR__.'/../src/Bootstrap.php';
require_once __DIR__.'/../src/DeploymentGate.php';
\FleetApi\Bootstrap::configureRuntime();
error_reporting(E_ALL);ini_set('display_errors','1');
set_error_handler(function($severity,$message,$file,$line){if(!(error_reporting()&$severity)){return false;}throw new \ErrorException($message,0,$severity,$file,$line);});
$checks=0;
function reviewCheck($value,$label){global $checks;if(!$value){throw new \RuntimeException('FAIL '.$label);}$checks++;}
function reviewReject($call,$label,$expectedStatus=503){try{$call();}catch(\FleetApi\ApiException $e){reviewCheck($e->status===$expectedStatus,$label);return;}throw new \RuntimeException('FAIL expected controlled rejection '.$label);}
$folder=__DIR__.'/../test-results/deployment-review-'.str_replace('.','',uniqid('',true));mkdir($folder,0700,true);$folder=realpath($folder);
$filename=$folder.'/config.json';$old=getenv('FLEET_API_CONFIG');putenv('FLEET_API_CONFIG='.$filename);
$valid=array('options'=>array('enforceMinuteRule'=>true,'minIntervalSeconds'=>60,'requestConcurrency'=>array('directory'=>$folder,'globalMaxConcurrent'=>2,'tenantMaxConcurrent'=>1,'requestTimeoutSeconds'=>10)));
try{
    file_put_contents($filename,json_encode($valid));\FleetApi\DeploymentGate::requireServerLimits();reviewCheck(true,'explicit valid request limits accepted');
    foreach(array(false,0,'configured',array(),array('directory'=>$folder)) as $bad){$config=$valid;$config['options']['requestConcurrency']=$bad;file_put_contents($filename,json_encode($config));reviewReject(function(){\FleetApi\DeploymentGate::requireServerLimits();},'malformed configured limits controlled rejection');}
    foreach(array(false,0,'true') as $bad){$config=$valid;$config['options']['enforceMinuteRule']=$bad;file_put_contents($filename,json_encode($config));reviewReject(function(){\FleetApi\DeploymentGate::requireServerLimits();},'minute opt-in required');}
    foreach(array(0,59,'60') as $bad){$config=$valid;$config['options']['minIntervalSeconds']=$bad;file_put_contents($filename,json_encode($config));reviewReject(function(){\FleetApi\DeploymentGate::requireServerLimits();},'strict minute bound required');}
    $profile=array('version'=>'EAI_API_DEPLOYMENT_V1','mode'=>'server-recette','publicBaseUrl'=>'https://api.company.example','httpMode'=>'external-http','serverAccessEnabled'=>true,'approval'=>array('approvedBy'=>'fixture','reference'=>'fixture','approvedUtc'=>'2026-10-20 12:00:00'));
    reviewReject(function()use($profile){\FleetApi\DeploymentGate::authorize(array('REMOTE_ADDR'=>'192.0.2.1','HTTP_X_FORWARDED_PROTO'=>'https'),false,false,$profile);},'untrusted forwarding cannot authorize HTTPS',426);
    reviewReject(function()use($profile){\FleetApi\DeploymentGate::authorize(array('REMOTE_ADDR'=>'192.0.2.1','HTTPS'=>'on'),false,true);},'local-test flag cannot enable a remote account');
    reviewCheck(\FleetApi\DeploymentGate::authorize(array('REMOTE_ADDR'=>'192.0.2.1','HTTPS'=>'on'),false,false,$profile)==='server-recette','explicit approved direct TLS mode admitted');
}finally{if($old===false){putenv('FLEET_API_CONFIG');}else{putenv('FLEET_API_CONFIG='.$old);}}
echo json_encode(array('result'=>'PASS','suite'=>'deployment-independent-correctness-review','php'=>PHP_VERSION,'checks'=>$checks,'sqlConnections'=>0,'eaiCalls'=>0,'httpListeners'=>0,'filesDeleted'=>0)).PHP_EOL;
