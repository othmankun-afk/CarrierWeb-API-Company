<?php
require_once __DIR__.'/../src/Bootstrap.php';
require_once __DIR__.'/../src/DeploymentGate.php';
use FleetApi\DeploymentGate;
use FleetApi\ApiException;
$checks=0;
function ok($value){global $checks;if(!$value)throw new Exception('DEPLOYMENT_GATE_TEST_FAILED');$checks++;}
function rejects($call,$code){global $checks;try{$call();}catch(ApiException $e){if($e->getMessage()===$code){$checks++;return;}throw $e;}throw new Exception('EXPECTED_DEPLOYMENT_REJECTION');}
$local=array('REMOTE_ADDR'=>'127.0.0.1');$remote=array('REMOTE_ADDR'=>'192.0.2.8','HTTPS'=>'on');
$profile=array('version'=>'EAI_API_DEPLOYMENT_V1','mode'=>'server-recette','httpMode'=>'external-http','publicBaseUrl'=>'https://api.company.example','serverAccessEnabled'=>true,'approval'=>array('reference'=>'FICTITIOUS_TEST_APPROVAL','approvedBy'=>'fixture operator','approvedUtc'=>'2026-10-20 12:00:00'));
ok(DeploymentGate::authorize($local,false,true)==='local-recette');ok(DeploymentGate::authorize($local,true,false)==='local-demo');
rejects(function()use($remote){DeploymentGate::authorize($remote,false,true);},'release_not_approved');
rejects(function()use($remote){DeploymentGate::authorize($remote,true,true);},'release_not_approved');
rejects(function()use($local){DeploymentGate::authorize($local,false,false);},'release_not_approved');
ok(DeploymentGate::authorize($remote,false,false,$profile)==='server-recette');
$bad=$profile;$bad['serverAccessEnabled']=false;rejects(function()use($remote,$bad){DeploymentGate::authorize($remote,false,false,$bad);},'release_not_approved');
foreach(array('approval','version','mode','httpMode','publicBaseUrl') as $key){$bad=$profile;unset($bad[$key]);rejects(function()use($remote,$bad){DeploymentGate::authorize($remote,false,false,$bad);},'release_not_approved');}
foreach(array('http://api.company.example','https://user:password@api.company.example','https://api.company.example/path','https://api.company.example?password=example','https://api.company.example#x','https://127.0.0.1','https://api.company.example\\evil') as $url){$bad=$profile;$bad['publicBaseUrl']=$url;rejects(function()use($remote,$bad){DeploymentGate::authorize($remote,false,false,$bad);},'release_not_approved');}
$plain=array('REMOTE_ADDR'=>'192.0.2.8','HTTP_X_FORWARDED_PROTO'=>'https');rejects(function()use($plain,$profile){DeploymentGate::authorize($plain,false,false,$profile);},'https_required');
$bad=$profile;$bad['approval']['approvedUtc']='2026-02-30 00:00:00';rejects(function()use($remote,$bad){DeploymentGate::authorize($remote,false,false,$bad);},'release_not_approved');
echo json_encode(array('result'=>'PASS','suite'=>'explicit-server-release-gate','checks'=>$checks,'eaiCalls'=>0,'filesDeleted'=>0,'actualTlsDeploymentTested'=>false)).PHP_EOL;
