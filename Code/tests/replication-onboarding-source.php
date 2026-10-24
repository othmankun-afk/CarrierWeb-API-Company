<?php
// Generated fictitious EAI-shaped records, not a saved customer response.
require_once __DIR__.'/../src/Bootstrap.php';
try{
    $p=json_decode(stream_get_contents(STDIN),true);$tenant=$p['tenant'];$id=(string)$p['vehicleId'];
    if(!preg_match('/^[a-z0-9_-]{1,64}$/D',$tenant)||!preg_match('/^[1-9][0-9]{0,8}$/D',$id))throw new RuntimeException('FICTITIOUS_SOURCE_IDENTITY_REQUIRED');
    $stamp=gmdate('Y-m-d H:i:s',time()-2);$vehicle=array();
    foreach(FleetApi\Contract::fields('vehicles') as $field=>$type)$vehicle[$field]=$type==='n'?'0':' ';
    foreach(FleetApi\EaiVehicleMapping::derivedFields() as $field)unset($vehicle[$field]);
    $vehicle['CWVehicleID']=$id;$vehicle['Speed']='42';$vehicle['ZipCode']=' ';$vehicle['LastLocation']='FICTITIOUS TEST LOCATION';unset($vehicle['CurrentSpeed'],$vehicle['Zipcode']);
    $vehicle['Latitude']='1234567';$vehicle['Longitude']='2345678';$vehicle['DivisionID']='3';$vehicle['Country']='ZZ';
    foreach(array('LastLocationTime','LastReportTime','HOSActivityTime','LastCanbusTime','FuelLevelTime') as $field)$vehicle[$field]=$stamp;
    $feeds=array('vehicles'=>array($vehicle),'divisions'=>array(array('ID'=>'3','Description'=>'FICTITIOUS DIVISION')),'wiredtelemetry'=>array());
    foreach(array('positions'=>'positions','canbus'=>'canbushistory','telemetry'=>'telemetry') as $endpoint=>$part){
        $row=array();foreach(FleetApi\Contract::fields($endpoint) as $field=>$type)$row[$field]=$type==='n'?'0':' ';
        $row['ID']='90071992547409930'.$id;$row['CWVehicleID']=$id;$row['MeasuredTime']=$stamp;
        if($endpoint==='positions'){$row['Latitude']='1234567';$row['Longitude']='2345678';$row['Country']='ZZ';}
        if($endpoint==='telemetry'){$row['TelemetryType']='9999';$row['TransponderID']='17';$row['TransponderDescription']='FICTITIOUS SENSOR';}
        $feeds[$part]=array($row);
    }
    $capture=array('result'=>'PASS','tenant'=>$tenant,'transportAttempts'=>1,'serverWrites'=>0,'nativeSha256'=>str_repeat('a',64),'bundle'=>array('tenant'=>$tenant,'timeBasis'=>'UTC','capturedUtc'=>$stamp,'feeds'=>$feeds),'cursors'=>array('positions'=>array('0'=>'1'),'canbushistory'=>'1','telemetry'=>array('0'=>'1'),'wiredtelemetry'=>array('0'=>'0')));
    echo json_encode(array('result'=>'PASS','capture'=>$capture));
}catch(Exception $e){echo '{"result":"FAIL","category":"FICTITIOUS_SOURCE_INVALID"}';exit(1);}
