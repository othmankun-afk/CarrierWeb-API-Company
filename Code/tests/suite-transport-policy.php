<?php
require __DIR__.'/../service/suite-eai-policy.php';
$checks=0;function tc($v){global $checks;if(!$v)throw new RuntimeException('POLICY_CHECK_FAILED');$checks++;}function tr($fn){try{$fn();}catch(RuntimeException $e){tc(true);return;}throw new RuntimeException('POLICY_ACCEPTED_BAD_REQUEST');}
$GLOBALS['serviceExpectedUsername']='fictitious.eai';$now=time();$p=array('request'=>array('username'=>'fictitious.eai','password'=>'fixture-only-not-real','skipDataBeforeTime'=>$now-86400),'requestOptions'=>array('FORCE_USE_MAXID_TABLE'=>'2'),'lastSeenIDs'=>array(),'version'=>'ver 1.81 S.1366 B.30/03/2021 12:30','transport'=>'native');
foreach(localParts() as $part){$p['requestOptions'][$part]=null;$p['lastSeenIDs'][$part]=array('type'=>$part,'ID'=>in_array($part,array('positions','telemetry','wiredtelemetry'),true)?array(0=>0):'0');}
$url='https://eaiaws1.carrierweb.com/website/eai/1.1.232/cwEAI.php';tc(localRequest($url,$p,$now));tc(count(localParts())===6);tc(isset(localTables()['positions']));
foreach(array('positions','telemetry','wiredtelemetry') as $part){$bad=$p;$bad['lastSeenIDs'][$part]['ID']='0';tr(function()use($url,$bad,$now){localRequest($url,$bad,$now);});}
$bad=$p;$bad['requestOptions']['Forward']=array();tr(function()use($url,$bad,$now){localRequest($url,$bad,$now);});$bad=$p;unset($bad['requestOptions']['positions']);tr(function()use($url,$bad,$now){localRequest($url,$bad,$now);});
$bad=$p;$bad['request']['password']="bad\nconfig";tr(function()use($url,$bad,$now){localRequest($url,$bad,$now);});$bad=$p;$bad['request']['skipDataBeforeTime']=$now;tr(function()use($url,$bad,$now){localRequest($url,$bad,$now);});
tr(function()use($p,$now){localRequest('https://example.test/',$p,$now);});tr(function(){localResponse(array('rowsadded'=>1,'rowspermissionerrors'=>0),localParts());});tr(function(){localResponse(array('rowsadded'=>0,'rowspermissionerrors'=>0,'request_cfg'=>true),localParts());});
tc(localSavedCursor('-1')===null);tc(localSavedCursor(array('0'=>'-1','20261018'=>'123'))===array(20261018=>'123'));
$backfill=$p;unset($backfill['request']['skipDataBeforeTime']);
tc(localRequest($url,$backfill,$now,null,true));
tr(function()use($url,$backfill,$now){localRequest($url,$backfill,$now);});
tr(function()use($url,$p,$now){localRequest($url,$p,$now,null,true);});
$badBackfill=$backfill;$badBackfill['request']['ymdfrom']='2000-01-01';
tr(function()use($url,$badBackfill,$now){localRequest($url,$badBackfill,$now,null,true);});
$badBackfill=$backfill;$badBackfill['requestOptions']['Forward']=array();
tr(function()use($url,$badBackfill,$now){localRequest($url,$badBackfill,$now,null,true);});
$diagnosticCases=array(6=>'DNS_FAILED',7=>'CONNECTION_FAILED',28=>'TRANSPORT_TIMEOUT',35=>'TLS_HANDSHAKE_FAILED',60=>'TLS_CERTIFICATE_VALIDATION_FAILED',77=>'TLS_CA_FILE_FAILED',99=>'CURL_TRANSPORT_FAILED');
foreach($diagnosticCases as $errno=>$category){$d=localTransportDiagnostic('empty response from curl: fixture-secret https://example.test/?password=fixture-secret ['.$errno.']');tc($d['category']===$category);tc($d['curlErrno']===$errno);tc(strpos(json_encode($d),'fixture-secret')===false);}
tc(localTransportDiagnostic('EAI Native protocol error 3')['nativeProtocolError']===3);
tc(localTransportDiagnostic('EAI Server: fixture-sensitive-error')['category']==='SERVER_REPORTED_EAI_ERROR');
tc(localTransportDiagnostic(str_repeat('x',1048577))['category']==='OFFICIAL_TRANSPORT_FAILURE_UNCLASSIFIED');
tc(localTransportDiagnostic(null)['category']==='OFFICIAL_TRANSPORT_FAILURE_UNCLASSIFIED');
echo json_encode(array('result'=>'PASS','suite'=>'six-feed-read-only-request-policy','checks'=>$checks,'php'=>PHP_VERSION,'transportAttempts'=>0,'serverWrites'=>0)).PHP_EOL;
