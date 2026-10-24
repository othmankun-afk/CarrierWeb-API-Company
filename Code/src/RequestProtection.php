<?php
namespace FleetApi;

// Machine-local admission control. Slots are permanent, bounded files in a
// private directory; the OS releases their locks if a PHP worker exits.
class RequestProtection
{
    private $directory;
    private $globalMaximum;
    private $tenantMaximum;
    private $timeout;
    public function __construct(array $configuration)
    {
        $keys=array('directory','globalMaxConcurrent','tenantMaxConcurrent','requestTimeoutSeconds');
        if(count($configuration)!==count($keys)){throw new ApiException(503,'configuration_invalid');}
        foreach($keys as $key){if(!array_key_exists($key,$configuration)){throw new ApiException(503,'configuration_invalid');}}
        $path=$configuration['directory'];
        if(!is_string($path)||strpos($path,"\0")!==false||strpos($path,'://')!==false||!preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#',$path)||!is_dir($path)){throw new ApiException(503,'configuration_invalid');}
        foreach(array('globalMaxConcurrent','tenantMaxConcurrent','requestTimeoutSeconds') as $key){if(!is_int($configuration[$key])||$configuration[$key]<1){throw new ApiException(503,'configuration_invalid');}}
        if($configuration['globalMaxConcurrent']>256||$configuration['tenantMaxConcurrent']>$configuration['globalMaxConcurrent']||$configuration['requestTimeoutSeconds']>300){throw new ApiException(503,'configuration_invalid');}
        $resolved=realpath($path);$public=realpath(dirname(__DIR__).'/public');
        if($resolved===false||$public===false){throw new ApiException(503,'configuration_invalid');}
        $resolved=str_replace('\\','/',$resolved);$public=str_replace('\\','/',$public);
        if(strcasecmp($resolved,$public)===0||stripos($resolved,$public.'/')===0){throw new ApiException(503,'configuration_invalid');}
        $this->directory=$resolved;$this->globalMaximum=$configuration['globalMaxConcurrent'];$this->tenantMaximum=$configuration['tenantMaxConcurrent'];$this->timeout=$configuration['requestTimeoutSeconds'];
    }
    public function begin()
    {
        $global=$this->acquire('global',$this->globalMaximum);
        return new RequestProtectionLease($this,$global,microtime(true)+$this->timeout,$this->timeout);
    }
    public function tenant($tenant)
    {
        if(!is_string($tenant)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$tenant)){throw new ApiException(503,'configuration_invalid');}
        return $this->acquire('tenant-'.hash('sha256',$tenant),$this->tenantMaximum);
    }
    private function acquire($bucket,$maximum)
    {
        for($slot=0;$slot<$maximum;$slot++){
            $path=$this->directory.DIRECTORY_SEPARATOR.$bucket.'-'.$slot.'.lock';
            $handle=@fopen($path,'c+b');
            if(!$handle){throw new ApiException(503,'service_unavailable');}
            if(@flock($handle,LOCK_EX|LOCK_NB)){return $handle;}
            fclose($handle);
        }
        // No queue, retry loop, tenant filename from untrusted URI or new slot.
        throw new ApiException(503,'request_capacity_busy');
    }
}

class RequestProtectionLease
{
    private $protection;
    private $global;
    private $tenant=null;
    private $deadline;
    private $previousTimeLimit;
    public function __construct(RequestProtection $protection,$global,$deadline,$timeout)
    {
        $this->protection=$protection;$this->global=$global;$this->deadline=$deadline;
        $this->previousTimeLimit=(int)ini_get('max_execution_time');
        // Additional PHP worker bound, not a portable wall-clock guarantee:
        // Unix excludes blocking I/O. Web-server/DB timeouts remain necessary.
        if(function_exists('set_time_limit')){@set_time_limit($timeout);}
    }
    public function tenant($tenant)
    {
        $this->check();
        if($this->tenant!==null){throw new ApiException(503,'service_unavailable');}
        $this->tenant=$this->protection->tenant($tenant);
    }
    public function check()
    {
        if(microtime(true)>=$this->deadline){throw new ApiException(503,'request_deadline_exceeded');}
    }
    public function release()
    {
        if(is_resource($this->tenant)){@flock($this->tenant,LOCK_UN);fclose($this->tenant);}$this->tenant=null;
        if(is_resource($this->global)){@flock($this->global,LOCK_UN);fclose($this->global);$this->global=null;if(function_exists('set_time_limit')){@set_time_limit($this->previousTimeLimit);}}
    }
    public function __destruct(){$this->release();}
}
