<?php
namespace FleetApi;
require_once __DIR__.'/RequestProtection.php';

interface RateLimiter { public function acquire($key, $interval); }

class FileRateLimiter implements RateLimiter
{
    private $directory;
    private $clock;
    public function __construct($directory,$clock=null) { $this->directory = $directory; $this->clock=$clock; }
    public function acquire($key, $interval)
    {
        if ($interval === 0) { return 0; }
        // Permission probes can disagree with real CreateFile access on Windows.
        // The checked open/write/flush operations below are the authoritative test.
        if (!is_dir($this->directory)) { throw new ApiException(503, 'service_unavailable'); }
        $path = $this->directory.DIRECTORY_SEPARATOR.hash('sha256', $key).'.limit';
        $file = @fopen($path, 'c+b');
        if (!$file) { throw new ApiException(503, 'service_unavailable'); }
        if (!flock($file, LOCK_EX | LOCK_NB)) { fclose($file); return $interval; }
        $stored = stream_get_contents($file, 32); $nowMicro=$this->clock===null?microtime(true):call_user_func($this->clock);$now=(int)floor($nowMicro);
        if ($stored !== '' && (!ctype_digit($stored) || strlen($stored) > 10)) { flock($file, LOCK_UN); fclose($file); throw new ApiException(503, 'service_unavailable'); }
        $remaining = $stored === '' ? 0 : (int)ceil((float)$stored + $interval - $now);
        if ($remaining > 0) { flock($file, LOCK_UN); fclose($file); return min($remaining, $interval); }
        // Round reservation UP, not down: second-resolution files must never
        // admit a physical interval shorter than 60 seconds at the boundary.
        $value = (string)(int)ceil($nowMicro);
        $ok = rewind($file) && ftruncate($file, 0) && fwrite($file, $value) === strlen($value) && fflush($file);
        flock($file, LOCK_UN); fclose($file);
        if (!$ok) { throw new ApiException(503, 'service_unavailable'); }
        return 0;
    }
}

class Api
{
    private $accounts;
    private $vehicleAccess;
    private $repository;
    private $limiter;
    private $options;
    private $requestProtection=null;
    private $requestLease=null;
    public function __construct(array $accounts, Repository $repository, RateLimiter $limiter, array $options)
    {
        $this->accounts = $accounts; $this->vehicleAccess = array(); $this->repository = $repository; $this->limiter = $limiter; $this->options = $options;
        if(array_key_exists('requestConcurrency',$options)){
            if(!is_array($options['requestConcurrency'])){throw new ApiException(503,'configuration_invalid');}
            $this->requestProtection=new RequestProtection($options['requestConcurrency']);
        }
        if (!isset($options['maxRows'], $options['maxResponseBytes'], $options['minIntervalSeconds']) || !is_int($options['maxRows']) || $options['maxRows'] < 1 || $options['maxRows'] > 100000 || !is_int($options['maxResponseBytes']) || $options['maxResponseBytes'] < 256 || !is_int($options['minIntervalSeconds']) || $options['minIntervalSeconds'] < 0 || $options['minIntervalSeconds'] > 86400) { throw new ApiException(503, 'configuration_invalid'); }
        // Test fixtures may disable cadence. Installed services opt in explicitly
        // and cannot accidentally publish a zero-second API configuration.
        if (isset($options['enforceMinuteRule']) && (!is_bool($options['enforceMinuteRule']) || ($options['enforceMinuteRule'] && $options['minIntervalSeconds'] < 60))) { throw new ApiException(503, 'configuration_invalid'); }
        foreach ($accounts as $name => $account) {
            if (!is_string($name) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $name) || !is_array($account) || !isset($account['tenant'], $account['tokenSha256'], $account['vehicleIds'], $account['endpoints']) || !is_string($account['tenant']) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $account['tenant']) || !is_string($account['tokenSha256']) || !preg_match('/^[a-f0-9]{64}$/D', $account['tokenSha256']) || !is_array($account['vehicleIds']) || !is_array($account['endpoints'])) { throw new ApiException(503, 'configuration_invalid'); }
            $access = array();
            foreach ($account['vehicleIds'] as $id) { if (!Contract::vehicleId($id)) { throw new ApiException(503, 'configuration_invalid'); } $access['id:'.$id] = true; }
            $this->vehicleAccess[$name] = $access;
            foreach ($account['endpoints'] as $endpoint) { if (!is_string($endpoint) || !array_key_exists($endpoint,Contract::schemas())) { throw new ApiException(503, 'configuration_invalid'); } }
            $scopeMode=isset($account['scopeMode'])?$account['scopeMode']:'explicit-list';
            if(!in_array($scopeMode,array('explicit-list','eai-account'),true)){throw new ApiException(503,'configuration_invalid');}
            $expanded=$repository instanceof ConfiguredRepository&&$repository->supportsExpandedAccountScope($account['tenant']);
            if($scopeMode==='eai-account'&&((!$expanded&&$account['endpoints']!==array('vehicles'))||!($repository instanceof PublishedVehicleScopeRepository)||!$repository->supportsVehicleAccountScope($account['tenant']))){throw new ApiException(503,'configuration_invalid');}
        }
    }

    public static function response($status, $value, array $extraHeaders = array())
    {
        $body = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) { $status = 503; $body = '{"error":{"code":"source_invalid"}}'; }
        return array('status'=>$status, 'body'=>$body, 'headers'=>array_merge(array('Content-Type'=>'application/json; charset=utf-8', 'Cache-Control'=>'no-store', 'X-Content-Type-Options'=>'nosniff', 'Referrer-Policy'=>'no-referrer'), $extraHeaders));
    }

    public static function parseQuery($raw)
    {
        if (!is_string($raw) || strlen($raw) > 8192) { throw new ApiException(400, 'invalid_query'); }
        $result = array(); if ($raw === '') { return $result; }
        $pairs = explode('&', $raw);
        if (count($pairs) > 32) { throw new ApiException(400, 'invalid_query'); }
        foreach ($pairs as $pair) {
            if ($pair === '' || preg_match('/%(?![0-9A-Fa-f]{2})/', $pair)) { throw new ApiException(400, 'invalid_query'); }
            $parts = explode('=', $pair, 2); $key = urldecode($parts[0]); $value = isset($parts[1]) ? urldecode($parts[1]) : '';
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $key) || preg_match('/[\x00-\x1f\x7f]/', $value) || preg_match('//u', $value) !== 1) { throw new ApiException(400, 'invalid_query'); }
            if (array_key_exists($key, $result)) {
                // Only the identical ymdto duplicate was acquired in the evidence.
                if ($key !== 'ymdto' || $result[$key] !== $value) { throw new ApiException(400, 'ambiguous_query'); }
            }
            $result[$key] = $value;
        }
        return $result;
    }

    public function handle($method, $uri, array $headers = array())
    {
        if ($this->repository instanceof ConfiguredRepository) { $this->repository->clearRequestMetadata(); }
        if(is_string($uri)&&strpos($uri,'/diagnostic/')===0){return $this->handleDiagnostic($method,$uri,$headers);}
        $result=$this->handleInternal($method,$uri,$headers);
        $response=CarrierwebResponse::adapt(is_string($uri)?$uri:'',$result);
        if(isset($this->options['recetteReplay'])&&$this->options['recetteReplay']===true){$response['headers']['X-Data-Mode']='ARCHIVED-EAI-REPLAY';$response['headers']['X-History-Coverage']='PARTIAL-NOT-VERIFIED';$response['headers']['Cache-Control']='no-store';}
        if(isset($this->options['suiteIncremental'])&&$this->options['suiteIncremental']===true){$response['headers']['X-Data-Mode']='INCREMENTAL-EAI-COLLECTED';$response['headers']['X-History-Coverage']='COLLECTED-PAGES-NOT-COMPLETE-HISTORY';$response['headers']['Cache-Control']='no-store';}
        if (!empty($this->options['enforceMinuteRule'])) {
            $response['headers']['X-Request-Min-Interval'] = (string)$this->options['minIntervalSeconds'];
            if (isset($result['headers']['Retry-After']) && ctype_digit($result['headers']['Retry-After'])) { $response['headers']['Retry-After'] = $result['headers']['Retry-After']; }
        }
        if ($this->repository instanceof ConfiguredRepository) {
            $publication=$this->repository->publicationMetadata();
            if($result['status']===200&&is_array($publication)){
                $response['headers']['X-Data-Freshness']=$publication['publicationFreshness'];
                $response['headers']['X-Data-Captured-At']=str_replace(' ','T',$publication['publicationCapturedUtc']).'Z';
                $response['headers']['X-Data-Age-Seconds']=(string)$publication['publicationAgeSeconds'];
                $response['headers']['X-Collection-State']=$publication['collectionState'];
            }
            $metadata = $this->repository->historyMetadata();
            if (is_array($metadata)) {
                // The existing response body stays compatible; coverage is never
                // upgraded to complete merely because a local query succeeded.
                $response['headers']['X-History-Completeness'] = 'NOT-VERIFIED';
                if (isset($metadata['policy']['lookbackDays'], $metadata['policy']['maxQuerySpanDays'])) {
                    $response['headers']['X-History-Lookback-Days'] = (string)$metadata['policy']['lookbackDays'];
                    $response['headers']['X-History-Max-Span-Days'] = (string)$metadata['policy']['maxQuerySpanDays'];
                }
            }
        }
        $error = $result['status']===200 ? null : json_decode($result['body'], true);
        if (isset($error['error']['code']) && in_array($error['error']['code'], array('history_lookback_exceeded','history_query_span_exceeded','history_future_query','history_reversed_window'), true)) { $response['headers']['X-History-Policy-Reason'] = $error['error']['code']; }
        return $response;
    }

    private function handleDiagnostic($method,$uri,array $headers){
        try{
            $this->beginRequest();
            return $this->handleDiagnosticRequest($method,$uri,$headers);
        }catch(ApiException $e){return self::response($e->status,array('error'=>array('code'=>$e->getMessage())));}
        catch(\Exception $e){return self::response(503,array('error'=>array('code'=>'service_unavailable')));}
        finally{$this->endRequest();}
    }
    private function handleDiagnosticRequest($method,$uri,array $headers){
        try{
            if($method!=='GET'){return self::response(405,array('error'=>array('code'=>'method_not_allowed')),array('Allow'=>'GET'));}
            $parts=explode('?',$uri,2);if(!preg_match('#^/diagnostic/([A-Za-z0-9][A-Za-z0-9_.-]{0,63})/(vehicles|history)$#D',$parts[0],$m)){throw new ApiException(404,'not_found');}
            $q=self::parseQuery(isset($parts[1])?$parts[1]:'');$name=$m[1];$exists=isset($this->accounts[$name]);$expected=$exists?$this->accounts[$name]['tokenSha256']:str_repeat('0',64);$token=isset($q['password'])?$q['password']:'';
            if(isset($headers['authorization'])||!$exists||$token===''||strlen($token)>512||!hash_equals($expected,hash('sha256',$token))){throw new ApiException(401,'unauthorized');}
            $a=$this->accounts[$name];
            $this->admitTenant($a['tenant']);
            if(!($this->repository instanceof ConfiguredRepository)){throw new ApiException(503,'diagnostic_not_enabled');}
            if ($m[2] === 'history') {
                if (!isset($q['endpoint'],$q['vehicleid']) || !in_array($q['endpoint'],array('positions','canbus','telemetry'),true) || !Contract::vehicleId($q['vehicleid'])) { throw new ApiException(400,'invalid_history_diagnostic'); }
                $endpoint=$q['endpoint']; $vehicle=$q['vehicleid'];
                if (!in_array($endpoint,$a['endpoints'],true)) { throw new ApiException(403,'forbidden'); }
                $dynamic=isset($a['scopeMode']) && $a['scopeMode']==='eai-account';
                if (!$dynamic && !isset($this->vehicleAccess[$name]['id:'.$vehicle])) { throw new ApiException(403,'forbidden'); }
                if (isset($q['ymdfrom']) !== isset($q['ymdto'])) { throw new ApiException(400,'missing_parameter'); }
                $from=isset($q['ymdfrom'])?Contract::inputDate($q['ymdfrom']):null;
                $to=isset($q['ymdto'])?Contract::inputDate($q['ymdto']):null;
                // Diagnostic and data reads share the SAME minute budget.
                $wait=$this->limiter->acquire($a['tenant'].'/'.$endpoint,$this->options['minIntervalSeconds']);
                if($wait>0){return self::response(429,array('error'=>array('code'=>'rate_limited')),array('Retry-After'=>(string)$wait));}
                $result=$this->repository->historyDiagnostic($a['tenant'],$endpoint,$vehicle,$from,$to);
                $this->checkDeadline();
                $response=self::response(200,$result);
                if(strlen($response['body'])>$this->options['maxResponseBytes']){throw new ApiException(413,'result_too_large');}
                return $response;
            }
            if(!in_array('vehicles',$a['endpoints'],true)){throw new ApiException(403,'forbidden');}
            $wait=$this->limiter->acquire($a['tenant'].'/diagnostic-vehicles',$this->options['minIntervalSeconds']);if($wait>0){return self::response(429,array('error'=>array('code'=>'rate_limited')),array('Retry-After'=>(string)$wait));}
            $result=$this->repository->diagnostic($a['tenant'],$a['vehicleIds'],isset($a['scopeMode'])&&$a['scopeMode']==='eai-account');$this->checkDeadline();$response=self::response(200,$result);if(strlen($response['body'])>$this->options['maxResponseBytes']){throw new ApiException(413,'result_too_large');}$this->checkDeadline();return $response;
        }catch(ApiException $e){return self::response($e->status,array('error'=>array('code'=>$e->getMessage())));}
        catch(\Exception $e){return self::response(503,array('error'=>array('code'=>'diagnostic_unavailable')));}
    }

    // Internal typed outcomes, not an HTTP JSON error API or approved migration.
    public function handleInternal($method, $uri, array $headers = array())
    {
        try{
            $this->beginRequest();
            return $this->handleInternalRequest($method,$uri,$headers);
        }catch(ApiException $e){return self::response($e->status,array('error'=>array('code'=>$e->getMessage())));}
        catch(\Exception $e){return self::response(503,array('error'=>array('code'=>'service_unavailable')));}
        finally{$this->endRequest();}
    }
    private function beginRequest()
    {
        if($this->requestProtection!==null){$this->requestLease=$this->requestProtection->begin();}
    }
    private function endRequest()
    {
        if($this->requestLease!==null){$this->requestLease->release();$this->requestLease=null;}
    }
    private function admitTenant($tenant){if($this->requestLease!==null){$this->requestLease->tenant($tenant);}}
    private function checkDeadline(){if($this->requestLease!==null){$this->requestLease->check();}}
    private function handleInternalRequest($method, $uri, array $headers)
    {
        try {
            if ($method !== 'GET') { return self::response(405, array('error'=>array('code'=>'method_not_allowed')), array('Allow'=>'GET')); }
            if (!is_string($uri) || strlen($uri) > 9000) { throw new ApiException(400, 'invalid_request'); }
            $parts = explode('?', $uri, 2);
            if (!preg_match('#^/cweai/([A-Za-z0-9][A-Za-z0-9_.-]{0,63})/(vehiclesBase|vehicles|positions|canbus|telemetry)$#D', $parts[0], $match)) { throw new ApiException(404, 'not_found'); }
            $accountName = $match[1]; $endpoint = $match[2]; $query = self::parseQuery(isset($parts[1]) ? $parts[1] : '');
            $authorization = isset($headers['authorization']) ? $headers['authorization'] : null;
            $token = isset($query['password']) ? $query['password'] : null;
            if ($authorization !== null) {
                // No new authentication channel without an agreed migration.
                throw new ApiException(401, 'authentication_channel_unverified');
            }
            $exists = isset($this->accounts[$accountName]);
            $expectedHash = $exists ? $this->accounts[$accountName]['tokenSha256'] : str_repeat('0', 64);
            $providedHash = hash('sha256', is_string($token) ? $token : '');
            $verified = hash_equals($expectedHash, $providedHash);
            if (!$exists || !is_string($token) || $token === '' || strlen($token) > 512 || !$verified) { throw new ApiException(401, 'unauthorized'); }
            $account = $this->accounts[$accountName];
            if (!in_array($endpoint, $account['endpoints'], true)) { throw new ApiException(403, 'forbidden'); }
            $vehicle = null; $from = null; $to = null;
            if (Contract::history($endpoint)) {
                foreach (array('vehicleid','ymdfrom','ymdto') as $key) { if (!isset($query[$key]) || $query[$key] === '') { throw new ApiException(400, 'missing_parameter'); } }
                $vehicle = $query['vehicleid'];
                if (!Contract::vehicleId($vehicle)) { throw new ApiException(400, 'invalid_vehicle'); }
                if ((!isset($account['scopeMode'])||$account['scopeMode']!=='eai-account')&&!isset($this->vehicleAccess[$accountName]['id:'.$vehicle])) { throw new ApiException(403, 'forbidden'); }
                $from = Contract::inputDate($query['ymdfrom']); $to = Contract::inputDate($query['ymdto']);
            }
            $this->admitTenant($account['tenant']);
            $wait = $this->limiter->acquire($account['tenant'].'/'.$endpoint, $this->options['minIntervalSeconds']);
            if ($wait > 0) { return self::response(429, array('error'=>array('code'=>'rate_limited')), array('Retry-After'=>(string)$wait)); }
            $reversed=$from!==null&&strcmp($from,$to)>0;
            $dynamic=isset($account['scopeMode'])&&$account['scopeMode']==='eai-account';
            $rows = $reversed&&!$dynamic ? array() : $this->repository->read($account['tenant'], $endpoint, $vehicle, $from, $to, $this->options['maxRows']);
            $this->checkDeadline();
            $rows = Contract::rows($endpoint, $rows);
            $access=$this->vehicleAccess[$accountName];
            if(isset($account['scopeMode'])&&$account['scopeMode']==='eai-account'){
                $scope=$this->repository->verifiedVehicleScope($account['tenant']);
                if(!is_array($scope)||count($scope)>10000){throw new ApiException(503,'publication_scope_invalid');}
                $access=array();foreach($scope as $id){if(!Contract::vehicleId($id)||isset($access['id:'.$id])){throw new ApiException(503,'publication_scope_invalid');}$access['id:'.$id]=true;}
                $actual=array();foreach($rows as $row){$key='id:'.(string)$row['CWVehicleID'];if(!isset($access[$key])||(!Contract::history($endpoint)&&isset($actual[$key]))){throw new ApiException(503,'publication_scope_invalid');}$actual[$key]=true;}
                if(!Contract::history($endpoint)&&count($actual)!==count($access)){throw new ApiException(503,'publication_scope_invalid');}
            }
            $visible = array();
            foreach ($rows as $row) {
                if (isset($access['id:'.(string)$row['CWVehicleID']])) {
                    if ($vehicle !== null && (string)$row['CWVehicleID'] !== $vehicle) { throw new ApiException(503, 'source_invalid'); }
                    $visible[] = $row;
                } elseif ($vehicle !== null) { throw new ApiException(503, 'source_invalid'); }
            }
            if (count($visible) > $this->options['maxRows']) { throw new ApiException(413, 'result_too_large'); }
            $response = self::response(200, $visible);
            if (strlen($response['body']) > $this->options['maxResponseBytes']) { throw new ApiException(413, 'result_too_large'); }
            $this->checkDeadline();
            return $response;
        } catch (ApiException $e) { return self::response($e->status, array('error'=>array('code'=>$e->getMessage()))); }
        catch (\Exception $e) { return self::response(503, array('error'=>array('code'=>'service_unavailable'))); }
    }
}
