<?php
namespace FleetApi;
require_once __DIR__.'/Contract.php';

// Match official CwEAIStorageNull::updateMaxID's monotonic high-water behavior,
// without PHP numeric-string coercion or loss of large decimal identifiers.
class EaiCursorState
{
    private static function invalid(){throw new ApiException(503,'suite_cursor_invalid');}
    private static function value($v){
        if(is_string($v)){if(!preg_match('/^(?:0|[1-9][0-9]{0,18})$/D',$v))self::invalid();return $v;}
        if(!is_array($v)||count($v)>100)self::invalid();
        foreach($v as $key=>$id){if(!preg_match('/^(?:0|[1-9][0-9]{0,7})$/D',(string)$key)||!is_string($id)||!preg_match('/^(?:0|[1-9][0-9]{0,18})$/D',$id))self::invalid();}return $v;
    }
    private static function higher($a,$b){return strlen($b)>strlen($a)||(strlen($b)===strlen($a)&&strcmp($b,$a)>0)?$b:$a;}
    public static function advance($previous,$advertised){
        $old=self::value($previous);$next=self::value($advertised);
        if(is_string($old)){if(!is_string($next))self::invalid();return self::higher($old,$next);}
        // Official legacy scalar metadata belongs to merged-table slot zero.
        if(is_string($next))$next=array(0=>$next);
        $out=$old;foreach($next as $partition=>$id)$out[$partition]=isset($out[$partition])?self::higher($out[$partition],$id):$id;
        if(count($out)>100)self::invalid();return $out;
    }
    public static function reviewedPage(array $received,array $previous){
        $allowed=array('positions','canbushistory','telemetry','wiredtelemetry');
        foreach($received as $part=>$value){if(!in_array($part,$allowed,true))self::invalid();self::value($value);}
        foreach($previous as $part=>$old){
            if(!in_array($part,$allowed,true)||!array_key_exists($part,$received))throw new ApiException(503,'suite_cursor_lost');
            self::value($old);
            if(is_array($old)&&(!is_array($received[$part])||array_diff_key($old,$received[$part])))throw new ApiException(503,'suite_cursor_lost');
            $received[$part]=self::advance($old,$received[$part]);
        }
        return $received;
    }
}
