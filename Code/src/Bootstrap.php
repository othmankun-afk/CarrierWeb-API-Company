<?php
namespace FleetApi;
require_once __DIR__.'/Contract.php';
require_once __DIR__.'/Repository.php';
require_once __DIR__.'/Api.php';
require_once __DIR__.'/CarrierwebResponse.php';
require_once __DIR__.'/MysqliDatabase.php';
require_once __DIR__.'/EaiTelemetryRepository.php';
require_once __DIR__.'/EaiSnapshotVehiclesRepository.php';
require_once __DIR__.'/PublishedVehiclesRepository.php';
require_once __DIR__.'/VehiclesPublisher.php';
require_once __DIR__.'/VehiclesServiceDiagnostic.php';
require_once __DIR__.'/SafeAudit.php';
require_once __DIR__.'/EaiSuiteRepository.php';
require_once __DIR__.'/SuiteLiveRepository.php';
require_once __DIR__.'/SuiteCapacityPolicy.php';

class ConfiguredRepository implements PublishedVehicleScopeRepository
{
    private $config;
    private $verifiedScope=null;
    private $verifiedTenant=null;
    private $historyMetadata=null;
    private $publicationMetadata=null;
    public function __construct(array $config) { $this->config = $config; }
    public function clearRequestMetadata(){ $this->historyMetadata=null; $this->publicationMetadata=null; $this->verifiedScope=null; $this->verifiedTenant=null; }
    public function publicationMetadata(){return $this->publicationMetadata;}
    public function historyMetadata(){ return $this->historyMetadata; }
    public function supportsVehicleAccountScope($tenant){return isset($this->config['driver'],$this->config['profile'],$this->config['tenants'][$tenant])&&(($this->config['driver']==='vehicles-published-mysqli'&&$this->config['profile']==='vehicles-published-v1')||($this->config['driver']==='eai-suite-replay'&&$this->config['profile']==='eai-suite-replay-v1')||($this->config['driver']==='eai-suite-live-mysqli'&&$this->config['profile']==='eai-suite-live-v1'));}
    public function supportsExpandedAccountScope($tenant){return isset($this->config['driver'],$this->config['tenants'][$tenant])&&in_array($this->config['driver'],array('eai-suite-replay','eai-suite-live-mysqli'),true);}
    public function verifiedVehicleScope($tenant){if($this->verifiedTenant!==$tenant||$this->verifiedScope===null){throw new ApiException(503,'publication_scope_invalid');}return $this->verifiedScope;}
    public function diagnostic($tenant,array $ids,$all){
        if(!$this->supportsVehicleAccountScope($tenant)||!isset($this->config['diagnosticEnabled'])||$this->config['diagnosticEnabled']!==true){throw new ApiException(503,'diagnostic_not_enabled');}
        $c=$this->config['tenants'][$tenant];if(!isset($c['diagnosticRuleProfile'],$c['diagnosticRuleProfileSha256'])){throw new ApiException(503,'diagnostic_not_enabled');}$db=null;
        try{$db=MysqliDatabase::open($c);$db->beginRead();$result=VehiclesServiceDiagnostic::read($db,$tenant,$c,$ids,$all);$db->commit();$db->close();return $result;}
        catch(\Exception $e){if($db){$db->rollback();$db->close();}throw $e;}
    }
    public function historyDiagnostic($tenant,$endpoint,$vehicle,$from=null,$to=null){
        if(!isset($this->config['driver'],$this->config['tenants'][$tenant])||$this->config['driver']!=='eai-suite-live-mysqli'||empty($this->config['historyDiagnosticEnabled'])){throw new ApiException(503,'diagnostic_not_enabled');}
        $c=$this->config['tenants'][$tenant]; $db=null;
        try{
            $db=MysqliDatabase::open($c); $db->beginRead();
            $repository=new SuiteLiveRepository($db,$tenant,$c['maxSnapshotAgeSeconds'],isset($c['historyPolicy'])?$c['historyPolicy']:null,isset($c['capacityPolicy'])?$c['capacityPolicy']:null,isset($c['staleReadPolicy'])?$c['staleReadPolicy']:'fail-closed');
            $result=$repository->historyDiagnostic($tenant,$endpoint,$vehicle,$from,$to);
            $db->commit(); $db->close(); return $result;
        }catch(\Exception $e){if($db){$db->rollback();$db->close();}throw $e;}
    }
    public function read($tenant, $endpoint, $vehicle, $from, $to, $limit)
    {
        $this->clearRequestMetadata();
        // Instantiation is deferred until authentication and request validation succeed.
        if ($this->config['driver'] === 'eai-suite-live-mysqli') {
            if(!isset($this->config['tenants'][$tenant]))throw new ApiException(503,'source_unavailable');$c=$this->config['tenants'][$tenant];$db=null;
            try{$db=MysqliDatabase::open($c);$db->beginRead();$repository=new SuiteLiveRepository($db,$tenant,$c['maxSnapshotAgeSeconds'],isset($c['historyPolicy'])?$c['historyPolicy']:null,isset($c['capacityPolicy'])?$c['capacityPolicy']:null,isset($c['staleReadPolicy'])?$c['staleReadPolicy']:'fail-closed');$rows=$repository->read($tenant,$endpoint,$vehicle,$from,$to,$limit);$scope=$repository->verifiedVehicleScope($tenant);$this->historyMetadata=$repository->historyMetadata();$this->publicationMetadata=$repository->publicationMetadata();$db->commit();$db->close();$this->verifiedScope=$scope;$this->verifiedTenant=$tenant;return CarrierwebResponse::eaiHistoryRows($endpoint,$rows);}
            catch(\Exception $e){if(isset($repository)){$this->historyMetadata=$repository->historyMetadata();}if($db){$db->rollback();$db->close();}throw $e;}
        } elseif ($this->config['driver'] === 'eai-suite-replay') {
            $repository=new EaiSuiteRepository($this->config);$rows=$repository->read($tenant,$endpoint,$vehicle,$from,$to,$limit);
            $this->verifiedScope=$repository->verifiedVehicleScope($tenant);$this->verifiedTenant=$tenant;return CarrierwebResponse::eaiHistoryRows($endpoint,$rows);
        } elseif ($this->config['driver'] === 'json') {
            $repository = new JsonFileRepository($this->config['files'], $this->config['maxFileBytes']);
        } elseif ($this->config['driver'] === 'eai-snapshot-json') {
            $repository = new EaiSnapshotVehiclesRepository($this->config);
        } elseif ($this->config['driver'] === 'vehicles-published-mysqli') {
            if($endpoint!=='vehicles'||!isset($this->config['tenants'][$tenant])){throw new ApiException(503,'source_unavailable');}
            $c=$this->config['tenants'][$tenant];$db=null;
            try{
                $db=MysqliDatabase::open($c);$db->beginRead();
                $publication=new PublishedVehiclesRepository($db,$tenant,$c['maxSnapshotAgeSeconds']);
                $rows=$publication->read($tenant,$endpoint,$vehicle,$from,$to,$limit);
                // Explicit-list publications remain readable for legacy accounts,
                // but can never become an all-EAI authorization implicitly.
                $scope=null;try{$scope=$publication->verifiedVehicleScope($tenant);}catch(ApiException $e){if($e->getMessage()!=='publication_scope_invalid'){throw $e;}}
                $db->commit();$db->close();$this->verifiedScope=$scope;$this->verifiedTenant=$tenant;return $rows;
            }catch(\Exception $e){if($db){$db->rollback();$db->close();}if($e instanceof ApiException){throw $e;}throw new ApiException(503,'source_unavailable');}
        } elseif ($this->config['driver'] === 'mysql') {
            $c = $this->config;
            if (!class_exists('PDO') || !in_array('mysql', \PDO::getAvailableDrivers(), true)) { throw new ApiException(503, 'source_unavailable'); }
            try {
                $pdo = new \PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'].';charset=utf8mb4', $c['username'], $c['password'], array(\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES=>false, \PDO::ATTR_TIMEOUT=>5));
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $pdo->exec('SET TRANSACTION READ ONLY');
                $pdo->beginTransaction();
                $repository = new PdoRepository($pdo);
                $rows = $repository->read($tenant, $endpoint, $vehicle, $from, $to, $limit);
                $pdo->commit(); return $rows;
            } catch (\Exception $e) {
                if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
                if ($e instanceof ApiException) { throw $e; }
                throw new ApiException(503, 'source_unavailable');
            }
        } elseif (in_array($this->config['driver'], array('mysqli','eai-mysqli'), true)) {
            $db = null;
            try {
                $c = $this->config;
                if ($c['driver'] === 'eai-mysqli') {
                    if (!isset($c['tenants'][$tenant]) || $endpoint !== 'telemetry') { throw new ApiException(503, 'mapping_unverified'); }
                    $c = $c['tenants'][$tenant];
                }
                $db = MysqliDatabase::open($c);
                if($this->config['driver']==='eai-mysqli'){$db->requireInnoDb('cwReturnTelemetry');}
                $db->beginRead();
                $repository = $this->config['driver'] === 'eai-mysqli' ? new EaiTelemetryRepository($db, $tenant) : new PdoRepository($db);
                $rows = $repository->read($tenant, $endpoint, $vehicle, $from, $to, $limit);
                $db->commit(); $db->close(); return $rows;
            } catch (\Exception $e) {
                if ($db) { $db->rollback(); $db->close(); }
                if ($e instanceof ApiException) { throw $e; }
                throw new ApiException(503, 'source_unavailable');
            }
        } else { throw new ApiException(503, 'configuration_invalid'); }
        return $repository->read($tenant, $endpoint, $vehicle, $from, $to, $limit);
    }
}

class Bootstrap
{
    public static function configureRuntime()
    {
        ini_set('display_errors', '0'); ini_set('log_errors', '0'); ini_set('expose_php', '0');
        // PHP otherwise appends its default charset to archived text/html responses.
        // JSON responses declare their own charset explicitly.
        ini_set('default_charset', '');
        ini_set('precision', '17'); ini_set('serialize_precision', PHP_VERSION_ID >= 70100 ? '-1' : '17');
        date_default_timezone_set('UTC');
    }

    public static function localPath($path)
    {
        if (!is_string($path) || strpos($path, '://') !== false || strpos($path, "\0") !== false || !preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path)) { throw new ApiException(503, 'configuration_invalid'); }
        $resolved = realpath($path);
        $public = realpath(dirname(__DIR__).'/public');
        if ($resolved === false || $public === false) { throw new ApiException(503, 'configuration_invalid'); }
        $resolved = str_replace('\\', '/', $resolved); $public = str_replace('\\', '/', $public);
        if (strcasecmp($resolved, $public) === 0 || stripos($resolved, $public.'/') === 0) { throw new ApiException(503, 'configuration_invalid'); }
        return $path;
    }

    public static function configuredApi()
    {
        $path = self::localPath(getenv('FLEET_API_CONFIG'));
        if (!is_file($path)) { throw new ApiException(503, 'configuration_invalid'); }
        $stream=@fopen($path,'rb');
        if(!$stream){throw new ApiException(503,'configuration_invalid');}
        $text=stream_get_contents($stream,1048577);fclose($stream);
        if(!is_string($text)||strlen($text)>1048576){throw new ApiException(503,'configuration_invalid');}
        try{$config=StrictJson::decode($text,true,64);}catch(JsonDecodeException $e){throw new ApiException(503,'configuration_invalid');}
        if (!is_array($config) || !isset($config['accounts'], $config['options'], $config['source'], $config['rateLimitDirectory']) || !is_array($config['accounts']) || !is_array($config['options']) || !is_array($config['source'])) { throw new ApiException(503, 'configuration_invalid'); }
        $source = $config['source'];
        if (!isset($source['driver'])) { throw new ApiException(503, 'configuration_invalid'); }
        if ($source['driver'] === 'eai-suite-live-mysqli') {
            if(!isset($source['profile'],$source['tenants'])||$source['profile']!=='eai-suite-live-v1'||!is_array($source['tenants'])||!$source['tenants'])throw new ApiException(503,'configuration_invalid');
            foreach($source['tenants'] as $tenant=>$c){if(!is_string($tenant)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$tenant)||!isset($c['maxSnapshotAgeSeconds'])||!is_int($c['maxSnapshotAgeSeconds'])||$c['maxSnapshotAgeSeconds']<1||$c['maxSnapshotAgeSeconds']>86400)throw new ApiException(503,'configuration_invalid');self::databaseConfig($c,true);if(isset($c['historyPolicy'])){if(!is_array($c['historyPolicy']))throw new ApiException(503,'configuration_invalid');SuiteHistoryPolicy::normalize($c['historyPolicy']);}if(isset($c['capacityPolicy'])){if(!is_array($c['capacityPolicy']))throw new ApiException(503,'configuration_invalid');SuiteCapacityPolicy::normalize($c['capacityPolicy']);}}
            if(isset($source['historyDiagnosticEnabled'])&&!is_bool($source['historyDiagnosticEnabled']))throw new ApiException(503,'configuration_invalid');
            $config['options']['suiteIncremental']=true;
        } elseif ($source['driver'] === 'eai-suite-replay') {
            if(!isset($source['profile'],$source['tenants'])||$source['profile']!=='eai-suite-replay-v1'||!is_array($source['tenants'])||!$source['tenants']){throw new ApiException(503,'configuration_invalid');}
            foreach($source['tenants'] as $tenant=>$c){if(!is_string($tenant)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$tenant)||!isset($c['documentPath'],$c['documentSha256'])||!is_string($c['documentSha256'])||!preg_match('/^[a-f0-9]{64}$/D',$c['documentSha256'])){throw new ApiException(503,'configuration_invalid');}self::localPath($c['documentPath']);}
            $config['options']['recetteReplay']=true;
        } elseif ($source['driver'] === 'json') {
            if (!isset($source['files'], $source['maxFileBytes']) || !is_array($source['files']) || !is_int($source['maxFileBytes']) || $source['maxFileBytes'] < 1 || $source['maxFileBytes'] > 67108864) { throw new ApiException(503, 'configuration_invalid'); }
            foreach ($source['files'] as $file) { self::localPath($file); }
        } elseif ($source['driver'] === 'eai-snapshot-json') {
            EaiSnapshotVehiclesRepository::validate($source);
        } elseif (in_array($source['driver'], array('mysql','mysqli'), true)) {
            self::databaseConfig($source, $source['driver'] === 'mysqli');
        } elseif ($source['driver'] === 'vehicles-published-mysqli') {
            if(!isset($source['profile'],$source['tenants'])||$source['profile']!=='vehicles-published-v1'||!is_array($source['tenants'])||!$source['tenants']){throw new ApiException(503,'configuration_invalid');}
            foreach($source['tenants'] as $tenant=>$connection){
                if(!is_string($tenant)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$tenant)||!is_array($connection)||!isset($connection['maxSnapshotAgeSeconds'])||!is_int($connection['maxSnapshotAgeSeconds'])||$connection['maxSnapshotAgeSeconds']<1||$connection['maxSnapshotAgeSeconds']>86400){throw new ApiException(503,'configuration_invalid');}
                self::databaseConfig($connection,true);
            }
        } elseif ($source['driver'] === 'eai-mysqli') {
            if (!isset($source['profile'], $source['tenants']) || $source['profile'] !== 'observed-telemetry-seven-local-v1' || !is_array($source['tenants'])) { throw new ApiException(503, 'configuration_invalid'); }
            foreach ($source['tenants'] as $tenant => $connection) {
                if (!is_string($tenant) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $tenant) || !is_array($connection)) { throw new ApiException(503, 'configuration_invalid'); }
                self::databaseConfig($connection, true);
            }
        } else { throw new ApiException(503, 'configuration_invalid'); }
        $limitDirectory = self::localPath($config['rateLimitDirectory']);
        return new Api($config['accounts'], new ConfiguredRepository($source), new FileRateLimiter($limitDirectory), $config['options']);
    }

    public static function databaseConfig(array $source, $bounded)
    {
        foreach (array('host','database','username','password') as $key) { if (!isset($source[$key]) || !is_string($source[$key])) { throw new ApiException(503, 'configuration_invalid'); } }
        if (!preg_match('/^[A-Za-z0-9_.-]{1,253}$/D', $source['host']) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $source['database']) || !isset($source['port']) || !is_int($source['port']) || $source['port'] < 1 || $source['port'] > 65535) { throw new ApiException(503, 'configuration_invalid'); }
        if ($bounded && (!isset($source['queryTimeoutMs']) || !is_int($source['queryTimeoutMs']) || $source['queryTimeoutMs'] < 1 || $source['queryTimeoutMs'] > 30000)) { throw new ApiException(503, 'configuration_invalid'); }
    }
}
