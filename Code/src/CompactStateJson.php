<?php
namespace FleetApi;

// Lossless encoding of the internal current-state JSON, never API payloads.
// No row, field or historical event is removed. Existing plain JSON is readable.
class CompactStateJson
{
    const MAX_BYTES=16777216;
    const PREFIX='CWJZ1:';
    public static function encode($json){
        if(!is_string($json)||strlen($json)>self::MAX_BYTES||substr($json,0,1)!=='{')throw new ApiException(503,'suite_snapshot_bound');
        if(!function_exists('gzcompress'))return $json;
        $compressed=gzcompress($json,6);if($compressed===false)throw new ApiException(503,'suite_state_encoding_failed');
        $encoded=self::PREFIX.strlen($json).':'.hash('sha256',$json).':'.base64_encode($compressed);
        return strlen($encoded)<strlen($json)?$encoded:$json;
    }
    public static function decode($stored){
        if(!is_string($stored)||strlen($stored)>self::MAX_BYTES)throw new ApiException(503,'suite_state_encoding_invalid');
        if(substr($stored,0,1)==='{')return $stored;
        if(!preg_match('/^CWJZ1:([1-9][0-9]{0,7}):([a-f0-9]{64}):([A-Za-z0-9+\/=]+)$/D',$stored,$parts)||!function_exists('gzuncompress'))throw new ApiException(503,'suite_state_encoding_invalid');
        $length=(int)$parts[1];if($length>self::MAX_BYTES)throw new ApiException(503,'suite_state_encoding_invalid');
        $packed=base64_decode($parts[3],true);if($packed===false)throw new ApiException(503,'suite_state_encoding_invalid');
        try{$json=@gzuncompress($packed,$length);}catch(\Exception $e){throw new ApiException(503,'suite_state_encoding_invalid');}
        if(!is_string($json)||strlen($json)!==$length||substr($json,0,1)!=='{'||!hash_equals($parts[2],hash('sha256',$json)))throw new ApiException(503,'suite_state_encoding_invalid');
        return $json;
    }
}
