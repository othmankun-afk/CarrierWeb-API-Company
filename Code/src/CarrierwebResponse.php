<?php
namespace FleetApi;

// Restricted to observed cases. No JSON error contract is exported on /cweai.
// Unknown cases fail closed for local development; they are release blockers.
class CarrierwebResponse
{
    const DATE_ERROR = 'ERR: , Conversion failed when converting date and/or time from character string.[]';
    const CADENCE_ERROR = 'API ERROR: getPositionsHistory function limit request per 1 minute reached[]';
    public static function eaiHistoryRows($endpoint,array $rows){
        if(!Contract::history($endpoint))return $rows;
        // Official SDK fixType('', char/varchar) stores one ASCII space.
        // Apply at the read boundary, never rewrite stored events or hashes.
        foreach($rows as &$row){foreach(Contract::fields($endpoint) as $field=>$type){if($type==='s'&&$field!=='ID'&&$field!=='MeasuredTime'&&array_key_exists($field,$row)&&$row[$field]==='')$row[$field]=' ';}}unset($row);
        return $rows;
    }
    public static function raw($status, $body, $mime)
    {
        return array('status'=>$status,'body'=>$body,'headers'=>array('Content-Type'=>$mime,'Cache-Control'=>'no-cache','Pragma'=>'no-cache','Expires'=>'-1','Access-Control-Allow-Origin'=>'*','X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer'));
    }
    public static function unavailable()
    {
        // Not a claimed supplier response. Never disguise unavailable data as [].
        return self::raw(503, '', 'text/plain; charset=utf-8');
    }
    public static function adapt($uri, $internal)
    {
        if ($internal['status'] === 200) { return self::raw(200,$internal['body'],'application/json; charset=utf-8'); }
        $parts=explode('?', $uri, 2);
        if (!preg_match('#^/cweai/[A-Za-z0-9][A-Za-z0-9_.-]{0,63}/positions$#D',$parts[0])) { return self::unavailable(); }
        try { $q=Api::parseQuery(isset($parts[1])?$parts[1]:''); } catch (ApiException $e) { return self::unavailable(); }
        $error=json_decode($internal['body'],true);
        $code=isset($error['error']['code'])?$error['error']['code']:'';
        $missing=false;
        foreach (array('password','vehicleid','ymdfrom','ymdto') as $key) { if (!array_key_exists($key,$q)) { $missing=true; } }
        // Only omission (not invalid credentials) and parsed, incomplete requests.
        if ($missing && in_array($code,array('unauthorized','missing_parameter'),true)) {
            // A supplied invalid password must not be interpreted as a missing parameter case.
            if ($code==='unauthorized' && isset($q['password'])) { return self::unavailable(); }
            $documentFile = isset($q['password']) ? 'documentation.json' : 'auth-missing.json';
            $docs=json_decode(file_get_contents(dirname(__DIR__).'/compat/'.$documentFile),true);
            if (!is_array($docs) || !isset($docs['positions'])) { return self::unavailable(); }
            return self::raw(200,$docs['positions'],'text/html');
        }
        if ($code==='missing_parameter' && isset($q['ymdfrom'],$q['vehicleid'],$q['ymdto'],$q['password']) && $q['ymdfrom']==='' && Contract::vehicleId($q['vehicleid'])) {
            try { Contract::inputDate($q['ymdto']); } catch (ApiException $e) { return self::unavailable(); }
            return self::raw(200,'[]','application/json; charset=utf-8');
        }
        if ($code==='invalid_date' && isset($q['ymdfrom'],$q['ymdto'])) {
            try { Contract::inputDate($q['ymdto']); } catch (ApiException $e) { return self::unavailable(); }
            return self::raw(200,self::DATE_ERROR,'application/json; charset=utf-8');
        }
        if ($code==='rate_limited') { return self::raw(200,self::CADENCE_ERROR,'application/json; charset=utf-8'); }
        // Auth-invalid body, denied vehicle, other invalid inputs and other endpoints
        // have no complete archived oracle. No invented compatibility mapping.
        return self::unavailable();
    }
}
