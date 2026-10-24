<?php
namespace FleetApi;

// Publication freshness is not sensor freshness or upstream availability.
// Missing/invalid/future/corrupt publications remain errors in every mode.
class SnapshotReadPolicy
{
    public static function normalize($mode){
        if(!is_string($mode)||!in_array($mode,array('fail-closed','last-known-with-warning'),true))throw new ApiException(503,'configuration_invalid');
        return $mode;
    }
    public static function evaluate($captured,$maxAge,$mode,$now=null){
        self::normalize($mode);
        if(!is_int($maxAge)||$maxAge<1||$maxAge>86400)throw new ApiException(503,'configuration_invalid');
        $captured=Contract::eventTime('vehicles',$captured);$now=$now===null?time():$now;
        if(!is_int($now))throw new ApiException(503,'configuration_invalid');
        $stamp=strtotime($captured.' UTC');if($stamp===false)throw new ApiException(503,'source_invalid');
        $age=$now-$stamp;if($age<0)throw new ApiException(503,'source_from_future');
        $fresh=$age<=$maxAge;
        if(!$fresh&&$mode==='fail-closed')throw new ApiException(503,'source_stale');
        return array('publicationFreshness'=>$fresh?'FRESH':'STALE','publicationCapturedUtc'=>$captured,'publicationAgeSeconds'=>$age,'publicationReadPolicy'=>$mode);
    }
}
