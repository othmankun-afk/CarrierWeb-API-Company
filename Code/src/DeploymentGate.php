<?php
namespace FleetApi;

// Direct server TLS metadata is trusted, never a client-supplied forwarded
// header. An approved HTTPS web-server configuration must set HTTPS itself.
class DeploymentGate
{
    public static function authorize(array $server,$demo,$localTest,$profile=null)
    {
        $local=isset($server['REMOTE_ADDR'])&&in_array($server['REMOTE_ADDR'],array('127.0.0.1','::1'),true);
        if($demo){if(!$local)throw new ApiException(503,'release_not_approved');return 'local-demo';}
        if($local&&$localTest)return 'local-recette';
        if(!is_array($profile)||!isset($profile['version'],$profile['mode'],$profile['publicBaseUrl'],$profile['httpMode'],$profile['serverAccessEnabled'])||$profile['version']!=='EAI_API_DEPLOYMENT_V1'||$profile['mode']!=='server-recette'||$profile['httpMode']!=='external-http'||$profile['serverAccessEnabled']!==true)throw new ApiException(503,'release_not_approved');
        if(!isset($profile['approval'])||!is_array($profile['approval']))throw new ApiException(503,'release_not_approved');
        foreach(array('reference','approvedBy','approvedUtc') as $k)if(!isset($profile['approval'][$k])||!is_string($profile['approval'][$k])||$profile['approval'][$k]===''||strlen($profile['approval'][$k])>256||preg_match('/[\r\n\x00]/',$profile['approval'][$k]))throw new ApiException(503,'release_not_approved');
        $a=$profile['approval'];if(!preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D',$a['approvedUtc']))throw new ApiException(503,'release_not_approved');
        $d=\DateTime::createFromFormat('!Y-m-d H:i:s',$a['approvedUtc'],new \DateTimeZone('UTC'));if(!$d||$d->format('Y-m-d H:i:s')!==$a['approvedUtc'])throw new ApiException(503,'release_not_approved');
        if(!is_string($profile['publicBaseUrl'])||preg_match('/[\s\\\\]/',$profile['publicBaseUrl']))throw new ApiException(503,'release_not_approved');
        $url=parse_url($profile['publicBaseUrl']);if(!$url||!isset($url['scheme'],$url['host'])||$url['scheme']!=='https'||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||(isset($url['path'])&&$url['path']!=='/'&&$url['path']!=='')||in_array(strtolower($url['host']),array('localhost','127.0.0.1','[::1]'),true))throw new ApiException(503,'release_not_approved');
        if(!isset($server['HTTPS'])||!in_array(strtolower((string)$server['HTTPS']),array('on','1'),true))throw new ApiException(426,'https_required');
        return 'server-recette';
    }

    public static function profileFromEnvironment()
    {
        $p=getenv('FLEET_API_SERVER_PROFILE');if($p===false||$p==='')return null;
        $p=Bootstrap::localPath($p);$s=@fopen($p,'rb');if(!$s)throw new ApiException(503,'release_not_approved');$raw=stream_get_contents($s,16385);fclose($s);
        if(!is_string($raw)||strlen($raw)>16384)throw new ApiException(503,'release_not_approved');
        return StrictJson::decode($raw,true,16);
    }

    public static function requireServerLimits()
    {
        $p=Bootstrap::localPath(getenv('FLEET_API_CONFIG'));$s=@fopen($p,'rb');if(!$s)throw new ApiException(503,'server_request_limits_required');$raw=stream_get_contents($s,1048577);fclose($s);
        if(!is_string($raw)||strlen($raw)>1048576)throw new ApiException(503,'server_request_limits_required');
        $c=StrictJson::decode($raw,true,64);
        if(!isset($c['options']['requestConcurrency'],$c['options']['enforceMinuteRule'],$c['options']['minIntervalSeconds'])||!is_array($c['options']['requestConcurrency'])||$c['options']['enforceMinuteRule']!==true||!is_int($c['options']['minIntervalSeconds'])||$c['options']['minIntervalSeconds']<60)throw new ApiException(503,'server_request_limits_required');
        // Construct without taking a slot; validates its private path and bounds.
        new RequestProtection($c['options']['requestConcurrency']);
    }
}
