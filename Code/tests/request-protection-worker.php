<?php
require_once __DIR__.'/../src/Bootstrap.php';
\FleetApi\Bootstrap::configureRuntime();
try{
    if($argc!==4){throw new \RuntimeException('WORKER_ARGUMENTS');}
    $configuration=json_decode(file_get_contents($argv[1]),true);$ready=$argv[2];$release=$argv[3];
    $guard=new \FleetApi\RequestProtection($configuration);$lease=$guard->begin();$lease->tenant('fixture_tenant');
    if(file_put_contents($ready,'READY')!==5){throw new \RuntimeException('WORKER_READY');}
    $deadline=microtime(true)+15;
    while(!is_file($release)&&microtime(true)<$deadline){usleep(10000);}
    $released=is_file($release);$lease->release();
    if(!$released){throw new \RuntimeException('WORKER_RELEASE_TIMEOUT');}
    echo json_encode(array('result'=>'PASS','filesDeleted'=>0))."\n";
}catch(\Exception $e){fwrite(STDERR,'WORKER_FAILED');exit(1);}
