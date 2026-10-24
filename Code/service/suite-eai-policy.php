<?php
require_once __DIR__.'/../src/EaiCursorState.php';
// Pure policy: no file, SQL, configuration or network access.
function localParts(){return array('vehicles','canbushistory','telemetry','wiredtelemetry','divisions','positions');}
function localTables(){return array('vehicles'=>'cwReturnVehicles','canbushistory'=>'cwReturnTruckCanbusHistory','telemetry'=>'cwReturnTelemetry','wiredtelemetry'=>'cwReturnWiredTelemetry','divisions'=>'cwReturnDivisions','positions'=>'cwReturnVehiclePositions');}
function localNativeStringEnvelope($value,$depth=0){
    if($depth>8){throw new RuntimeException('NATIVE_ENVELOPE_DEPTH');}
    if(is_string($value)){return array('__native_bytes_base64__'=>base64_encode($value));}
    if(is_array($value)){$out=array();foreach($value as $key=>$item){$out[$key]=localNativeStringEnvelope($item,$depth+1);}return $out;}
    if(is_int($value)||is_float($value)||is_bool($value)||is_null($value)){return $value;}
    throw new RuntimeException('NATIVE_ENVELOPE_TYPE');
}
// Reduce the official in-memory diagnostic to fixed categories and numbers.
// Provider bodies, URLs, credentials and free-form error strings never leave it.
function localTransportDiagnostic($text){
    $result=array('category'=>'OFFICIAL_TRANSPORT_FAILURE_UNCLASSIFIED','nativeProtocolError'=>null,'curlErrno'=>null);
    if(!is_string($text)||strlen($text)>1048576){return $result;}
    if(preg_match('/EAI Native protocol error ([1-5])\b/',$text,$m)){$result['nativeProtocolError']=(int)$m[1];$result['category']='NATIVE_RESPONSE_INVALID';}
    if(preg_match('/empty response from curl:[^\r\n]*?\[([0-9]{1,3})\]/',$text,$m)){
        $result['curlErrno']=(int)$m[1];$codes=array(6=>'DNS_FAILED',7=>'CONNECTION_FAILED',28=>'TRANSPORT_TIMEOUT',35=>'TLS_HANDSHAKE_FAILED',60=>'TLS_CERTIFICATE_VALIDATION_FAILED',77=>'TLS_CA_FILE_FAILED');
        $result['category']=isset($codes[$result['curlErrno']])?$codes[$result['curlErrno']]:'CURL_TRANSPORT_FAILED';
    }elseif(strpos($text,'EAI Server:')!==false){$result['category']='SERVER_REPORTED_EAI_ERROR';}
    return $result;
}
function localCursorCanonical($v){
    if(is_array($v)){$a=array();if(count($v)>100){throw new RuntimeException('CURSOR_BOUND');}foreach($v as $k=>$item){if(!preg_match('/^(?:0|[1-9][0-9]{0,7})$/D',(string)$k)){throw new RuntimeException('CURSOR_PARTITION');}$a[(string)$k]=localCursorCanonical($item);}ksort($a);return $a;}
    if(is_float($v)){if(!is_finite($v)||$v<0||$v>9007199254740991||floor($v)!=$v){throw new RuntimeException('CURSOR_PRECISION');}$v=sprintf('%.0F',$v);}
    if(is_int($v))$v=(string)$v;
    if(!is_string($v)||!preg_match('/^(?:0|[1-9][0-9]{0,18})$/D',$v)){throw new RuntimeException('CURSOR_VALUE');}
    return $v;
}
// Match the official storeMaxIDsInTable rule: -1 means no row was picked up,
// and must never replace a saved cursor or become an outgoing resume ID.
function localSavedCursor($v){
    if($v===-1||$v===-1.0||$v==='-1'){return null;}
    if(is_array($v)){
        $out=array();foreach($v as $partition=>$id){
            if(is_array($id)){throw new RuntimeException('CURSOR_NESTING');}
            if($id===-1||$id===-1.0||$id==='-1'){continue;}
            $out[$partition]=$id;
        }
        return $out?localCursorCanonical($out):null;
    }
    return localCursorCanonical($v);
}
function localRequest($url,$p,$now,$resume=null,$historyBackfill=false){
    if($url!=='https://eaiaws1.carrierweb.com/website/eai/1.1.232/cwEAI.php'||!is_array($p)||array_keys($p)!==array('request','requestOptions','lastSeenIDs','version','transport')){throw new RuntimeException('REQUEST_ENDPOINT_OR_KEYS_REJECTED');}
    $r=$p['request'];
    $requestKeys=$historyBackfill?array('username','password'):array('username','password','skipDataBeforeTime');
    if(!is_bool($historyBackfill)||!is_array($r)||array_keys($r)!==$requestKeys||$r['username']!==$GLOBALS['serviceExpectedUsername']||!is_string($r['password'])||$r['password']===''||preg_match('/[\r\n\x00]/',$r['password'])){throw new RuntimeException('AUTH_OR_CUTOFF_REJECTED');}
    if(!$historyBackfill&&(!is_int($r['skipDataBeforeTime'])||abs($now-86400-$r['skipDataBeforeTime'])>5)){throw new RuntimeException('AUTH_OR_CUTOFF_REJECTED');}
    if($p['version']!=='ver 1.81 S.1366 B.30/03/2021 12:30'||$p['transport']!=='native'){throw new RuntimeException('OFFICIAL_NATIVE_VERSION_REQUIRED');}
    // Official schema marks both telemetry feeds as merged tables. The SDK's
    // getMaxIDFromTable returns array(0=>0) for a new empty merged table;
    // vehicles/divisions/canbushistory use the scalar string "0".
    $expectedOptions=array('FORCE_USE_MAXID_TABLE'=>'2');$expectedIds=array();foreach(localParts() as $part){$expectedOptions[$part]=null;$expectedIds[$part]=array('type'=>$part,'ID'=>in_array($part,array('positions','telemetry','wiredtelemetry'),true)?array(0=>0):'0');}
    if($resume!==null){if(!is_array($resume)){throw new RuntimeException('CURSOR_EVIDENCE_REQUIRED');}foreach($resume as $part=>$id){if(!in_array($part,array('positions','canbushistory','telemetry','wiredtelemetry'),true)){throw new RuntimeException('CURSOR_SCOPE_REJECTED');}$expectedIds[$part]['ID']=localCursorCanonical($id);}}
    if(!is_array($p['requestOptions'])||!is_array($p['lastSeenIDs'])){throw new RuntimeException('REQUEST_SCOPE_REJECTED');}
    $options=$p['requestOptions'];$ids=$p['lastSeenIDs'];ksort($options);ksort($expectedOptions);ksort($ids);ksort($expectedIds);
    if($resume!==null){
        foreach($ids as $part=>$entry){if(!is_array($entry)||array_keys($entry)!==array('type','ID')||$entry['type']!==$part){throw new RuntimeException('CURSOR_REQUEST_SHAPE');}$ids[$part]['ID']=localCursorCanonical($entry['ID']);}
        foreach($expectedIds as $part=>$entry){$expectedIds[$part]['ID']=localCursorCanonical($entry['ID']);}
    }
    if($options!==$expectedOptions||$ids!==$expectedIds){throw new RuntimeException('FIVE_FEEDS_READ_ONLY_REQUIRED');}return true;
}
function localResponse($response,$storeParts){
    if(!is_array($response)||!isset($response['rowsadded'],$response['rowspermissionerrors'])||(string)$response['rowsadded']!=='0'||(string)$response['rowspermissionerrors']!=='0'){throw new RuntimeException('REMOTE_WRITE_COUNTERS_REJECTED');}
    foreach($storeParts as $part){if(!in_array($part,localParts(),true)&&array_key_exists($part,$response)){throw new RuntimeException('UNREQUESTED_FEED_REJECTED');}}
    if((isset($response['request_cfg'])&&$response['request_cfg'])||(isset($response['request_log'])&&$response['request_log'])){throw new RuntimeException('REMOTE_CONFIG_OR_LOG_REQUEST_REFUSED');}
    $result=array();$total=0;foreach(localParts() as $part){
        if(!array_key_exists($part,$response)){$result[$part]=array('state'=>'ABSENT','rowCount'=>null);continue;}
        if(!is_array($response[$part])){$result[$part]=array('state'=>'WRONG_TYPE','rowCount'=>null);continue;}
        $rows=$response[$part];unset($rows['__TABLE_MAXID'],$rows['__ROW_MAXID']);
        if(count($rows)>50000){throw new RuntimeException('RESPONSE_ROW_BOUND');}$total+=count($rows);
        foreach($rows as $row){if(!is_array($row)||count($row)>256){throw new RuntimeException('RESPONSE_ROW_SHAPE_REJECTED');}foreach($row as $key=>$value){if(!is_string($key)||!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,80}$/D',$key)||!(is_scalar($value)||is_null($value))||(is_string($value)&&strlen($value)>65536)||(is_float($value)&&!is_finite($value))){throw new RuntimeException('RESPONSE_VALUE_REJECTED');}}}
        $result[$part]=array('state'=>count($rows)?'PRESENT_ROWS':'PRESENT_EMPTY','rowCount'=>count($rows));
    }if($total>150000){throw new RuntimeException('RESPONSE_TOTAL_BOUND');}return $result;
}

