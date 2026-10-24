<?php
// One original SDK read. No SDK edits, SQL writes, forwarding or credential file.
ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(E_ALL);
require __DIR__.'/suite-eai-policy.php';
require __DIR__.'/sdk-date-normalization.php';
$finished=false;$calls=0;ob_start(function($x){return '';},4096);
function serviceEmit($value,$code){global $finished;$finished=true;while(ob_get_level()){ob_end_clean();}echo json_encode($value).PHP_EOL;exit($code);}
register_shutdown_function(function(){global $finished,$calls;if(!$finished){while(ob_get_level()){ob_end_clean();}echo json_encode(array('result'=>'FAIL','category'=>'EAI_CHILD_FATAL','transportAttempts'=>$calls)).PHP_EOL;}});
function serviceJson($path,$max){if(!is_file($path)||is_link($path)||filesize($path)>$max){throw new RuntimeException('LOCAL_PLAN_REQUIRED');}$v=json_decode(file_get_contents($path),true);if(!is_array($v)){throw new RuntimeException('LOCAL_PLAN_INVALID');}return $v;}
function servicePins($manifest){foreach($manifest['bridgeRootSha256'] as $file=>$hash){if(basename($file)!==$file||hash_file('sha256',$manifest['sourceDirectory'].'/'.$file)!==$hash){throw new RuntimeException('OFFICIAL_SOURCE_CHANGED');}}}
try{
    if(PHP_SAPI!=='cli'||$argc!==3||!in_array($argv[1],array('--preflight','--collect'),true)){throw new RuntimeException('COLLECT_ARGUMENTS');}
    $plan=serviceJson($argv[2],2097152);$manifest=serviceJson($plan['manifestPath'],2097152);
    $servicePurpose=isset($plan['purpose'])?$plan['purpose']:'INCREMENTAL';if(!in_array($servicePurpose,array('INCREMENTAL','HISTORY_BACKFILL_SINGLE_PAGE'),true)){throw new RuntimeException('COLLECTION_PURPOSE_INVALID');}$serviceHistoryBackfill=$servicePurpose==='HISTORY_BACKFILL_SINGLE_PAGE';
    if($manifest['eaiUsername']!==$plan['username']||$manifest['archiveSha256']!=='99b07b3b0cb4b18c03ee0ae9d79ad0b14f7f179ad262e8b3ff0de19fb6174065'||strtolower($manifest['customer'])!==$plan['tenant']){throw new RuntimeException('ACCOUNT_SOURCE_MISMATCH');}
    $serviceExpectedUsername=$plan['username'];$source=realpath($manifest['sourceDirectory']);$scratch=realpath(dirname($argv[2]).'/scratch');
    if(!$source||!$scratch){throw new RuntimeException('PRIVATE_RUNTIME_REQUIRED');}servicePins($manifest);
    $resume=array('canbushistory'=>'0','telemetry'=>array(0=>'0'),'wiredtelemetry'=>array(0=>'0'),'positions'=>array(0=>'0'));
    foreach($plan['cursors'] as $part=>$cursor){if(!array_key_exists($part,$resume)){throw new RuntimeException('CURSOR_SCOPE_REJECTED');}$resume[$part]=localCursorCanonical($cursor);}
    $GLOBAL_SKIP_MAIN=true;require $source.'/cwEAIClient.php';$LOG_FILE_NAME='php://memory';$LOG_FILE=fopen('php://memory','w+');$LOG_ALLOW_ECHO=false;
    $_SERVER['argv']=array('cwEAIClient.php','-c','php://stdin','--only-run-once');$backend=initEAI();
    // initEAI resets logging options. Keep its diagnostic in memory only;
    // expose a fixed category, never the provider body or request credentials.
    $LOG_FILE_NAME='php://memory';$LOG_FILE=fopen('php://memory','w+');$LOG_ALLOW_ECHO=false;$LOG_CLOSE_LOG_FILE=false;$LOG_FILE_MAX_SIZE=1073741824;
    if($EAI_BACKEND!=='null'||!($backend instanceof CwEAIStorageNull)||$EAI_USERNAME!==$serviceExpectedUsername||$EAI_ACTIONS!==array('storeData')||$PLUGINS!==array()||$ALLOW_SERVER_LOG_REQUEST||$ALLOW_SERVER_CFG_REQUEST||$RUNNING_AS_A_WINDOWS_SERVICE||$FORCE_PURGE_NOW||!$ONLY_RUN_ONCE||$EAI_TIME_FORMAT!=='gmt'||$EAI_TRANSPORT!=='native'||$CWSYNC_URL_BASE!==array('https://eaiaws1.carrierweb.com/website/eai')||$CWSYNC_URL_VERSION!=='1.1.232'||isset($PRE_SYNC_COMMAND)||isset($POST_SYNC_COMMAND)||$DB_PURGE_RETAIN_DAYS||realpath($EAI_FILE_IDS_DIRECTORY)!==$scratch||realpath($EAI_FILE_IN_DIRECTORY)!==$scratch||realpath($EAI_FILE_OUT_DIRECTORY)!==$scratch||isset($DRIVER_CARD_ESM_FILE_DIRECTORY)||isset($DIGITAL_TACHO_ESM_FILE_DIRECTORY)||isset($MESSAGE_ATTACHMENTS_DIRECTORY)){throw new RuntimeException('READ_ONLY_SCOPE_REJECTED');}
    $active=array();foreach($backend->format->getStoreList() as $part){$format=$backend->getStoreFormat($part);if(isset($format['table'])&&$format['table']!=-1){$active[]=$part;}}$expected=localParts();sort($active);sort($expected);if($active!==$expected||is_action_enabled('loadData')){throw new RuntimeException('FIVE_FEEDS_ONLY');}
    // Public null-backend state: original SDK stores its own cursors/timezone in
    // memory. The SQL transaction, not a partially written file, owns the resume.
    $backend->maxIDs=array_merge(array('vehicles'=>'0','divisions'=>'0'),$resume);$backend->maxIDsFile='php://memory';$backend->timezoneFile='php://memory';$backend->setRequestSkipOldData(!$serviceHistoryBackfill);
    $EAI_HTTP_CONNECT_TIMEOUT=10;$EAI_HTTP_RESPONSE_TIMEOUT=40;
    class ServiceCommunicator extends ServerCommunicator{
        private $mode;private $resume;private $tenant;private $historyBackfill;public $captured;
        function __construct($mode,$resume,$tenant,$historyBackfill){$this->mode=$mode;$this->resume=$resume;$this->tenant=$tenant;$this->historyBackfill=$historyBackfill;}
        function doCall($url,$p){global $calls;localRequest($url,$p,time(),$this->resume,$this->historyBackfill);if($this->mode==='--preflight'){throw new RuntimeException('NO_TRANSPORT_PREFLIGHT');}if($calls!==0){throw new RuntimeException('SECOND_CALL_REFUSED');}$calls++;
            $response=parent::doCall($url,$p);if(is_error()||!is_array($response)){global $LOG_FILE;rewind($LOG_FILE);$diagnostic=localTransportDiagnostic(stream_get_contents($LOG_FILE,1048576));throw new RuntimeException($diagnostic['category']==='OFFICIAL_TRANSPORT_FAILURE_UNCLASSIFIED'?'EAI_TRANSPORT_FAILED':'EAI_'.$diagnostic['category']);}$summary=localResponse($response,localParts());
            $feeds=array();$next=$this->resume;foreach(localParts() as $part){if(!isset($response[$part]))continue;if(!is_array($response[$part])){throw new RuntimeException('FEED_TYPE_INVALID');}$feeds[$part]=$response[$part];
                if(isset($next[$part])&&isset($response[$part]['__ROW_MAXID'])){$cursor=localSavedCursor($response[$part]['__ROW_MAXID']);if($cursor!==null){$next[$part]=\FleetApi\EaiCursorState::advance($next[$part],$cursor);}}
            }
            $native=json_encode(localNativeStringEnvelope($feeds));if($native===false){throw new RuntimeException('NATIVE_ENCODING');}$nativeHash=hash('sha256',$native);$native=null;
            $feeds=convertCharset($feeds,'LATIN1-WITH-HTML','UTF-8');foreach($feeds as $part=>&$rows){unset($rows['__ROW_MAXID'],$rows['__TABLE_MAXID']);$rows=array_values($rows);}unset($rows);global $backend;$feeds=serviceSdkNormalizeDates($feeds,$backend);
            $this->captured=array('result'=>'PASS','tenant'=>$this->tenant,'transportAttempts'=>1,'purpose'=>$this->historyBackfill?'HISTORY_BACKFILL_SINGLE_PAGE':'INCREMENTAL','requestedSkipBeforeUtc'=>$this->historyBackfill?null:gmdate('Y-m-d H:i:s',$p['request']['skipDataBeforeTime']),'bundle'=>array('tenant'=>$this->tenant,'capturedUtc'=>gmdate('Y-m-d H:i:s'),'timeBasis'=>'UTC','feeds'=>$feeds),'cursors'=>$next,'summary'=>$summary,'nativeSha256'=>$nativeHash,'serverWrites'=>0,'productionReady'=>false);return $response;
        }
    }
    $comm=new ServiceCommunicator($argv[1],$resume,$plan['tenant'],$serviceHistoryBackfill);$sync=new CwEAISync();$urls=new CwMirrorURLs();
    try{$ok=$sync->doSync($comm,$backend,$urls->getSyncURL(),false);}catch(RuntimeException $e){if($argv[1]==='--preflight'&&$e->getMessage()==='NO_TRANSPORT_PREFLIGHT'){serviceEmit(array('result'=>'PASS','transportAttempts'=>0,'serverWrites'=>0),0);}throw $e;}
    if($ok!==true||is_error()||!isset($comm->captured)||$calls!==1){throw new RuntimeException('EAI_CYCLE_UNVERIFIED');}servicePins($manifest);$EAI_PASSWORD=null;serviceEmit($comm->captured,0);
}catch(Exception $e){serviceEmit(array('result'=>'FAIL','category'=>preg_match('/^[A-Z][A-Z0-9_]+$/D',$e->getMessage())?$e->getMessage():'EAI_COLLECTION_REFUSED','transportAttempts'=>$calls,'automaticRetry'=>false),1);}
