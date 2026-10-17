<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../src/Core/Config.php';
require_once __DIR__.'/../src/Core/Database.php';
require_once __DIR__.'/../src/GiftCodes/GiftCodeMigration.php';
try{
    $command=$argv[1]??'';
    if(!in_array($command,['values','set-values','create','configure','statistics','inspect-plan','apply-plan','cleanup-plan'],true))throw new InvalidArgumentException('Unknown command');
    chdir(dirname(__DIR__));Config::load('.env');$request=[];
    if(in_array($command,['set-values','create','configure'],true)){
        $limit=Config::operatorLimit('GIFT_CODES_CONFIG_BYTES',16384);$raw=stream_get_contents(STDIN,$limit+1);
        if(strlen($raw)>$limit||substr(ltrim($raw),0,1)!=='{')throw new InvalidArgumentException('Bounded JSON object required');$request=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    }
    $migration=in_array($command,['inspect-plan','apply-plan','cleanup-plan'],true);
    // Offline inspection never bootstraps or rewrites a database. Upgrade schema separately under maintenance.
    if(!$migration)Database::migrate();$pdo=Database::connect();$service=new GiftCodesService($pdo);
    if($command==='statistics')$result=$service->statistics();
    elseif($command==='values'||$command==='set-values')$result=$service->configureValues($argv[2]??'',$request);
    elseif($command==='create'){$request['code']=$argv[2]??'';$result=$service->create($request);}
    elseif($command==='configure'){$expected=$argv[3]??null;if(!is_string($expected))throw new InvalidArgumentException('Expected revision required');$result=$service->configure($argv[2]??'',$request,$expected);}
    elseif($command==='inspect-plan'){
        $code=$argv[2]??'';$path=$argv[3]??'';if($path===''||$path[0]!=='/')throw new InvalidArgumentException('Absolute new plan path required');$flags=[];
        foreach(array_slice($argv,4)as$flag){if(!preg_match('/^--(backup|backup-sha256|restore-proof|restore-proof-sha256|provenance|provenance-sha256)=(.+)$/D',$flag,$m)||isset($flags[$m[1]]))throw new InvalidArgumentException('Unknown/duplicate migration flag');$flags[$m[1]]=$m[2];}
        $evidence=[];foreach(['backup'=>'backup','restoreProof'=>'restore-proof','provenance'=>'provenance']as$key=>$flag)$evidence[$key]=['path'=>$flags[$flag]??null,'sha256'=>$flags[$flag.'-sha256']??null];
        $plan=(new GiftCodeMigration($pdo))->inspect($code,$evidence);$bytes=GiftCodeMigration::serialize($plan);$mask=umask(0077);try{$file=fopen($path,'xb');}finally{umask($mask);}if($file===false)throw new InvalidArgumentException('Plan must not overwrite an existing file');
        try{$offset=0;while($offset<strlen($bytes)){$n=fwrite($file,substr($bytes,$offset));if($n===false||$n===0)throw new RuntimeException('Cannot write sealed plan');$offset+=$n;}if(!fflush($file))throw new RuntimeException('Cannot flush sealed plan');if(function_exists('fsync')&&!fsync($file))throw new RuntimeException('Cannot synchronize sealed plan');}finally{fclose($file);}
        $sha=hash('sha256',$bytes);if(!hash_equals($sha,hash_file('sha256',$path)))throw new RuntimeException('Plan readback differs');$result=['plan'=>$path,'sha256'=>$sha,'usedCount'=>$plan['usedCount'],'pairs'=>count($plan['inventory']['pairs']),'originalRows'=>$plan['inventory']['originalRows'],'orphanValueGuards'=>$plan['inventory']['orphanValueGuards']];
    }else{
        $path=$argv[2]??'';$sha=$argv[3]??'';$plan=GiftCodeMigration::load($path,$sha);$runner=new GiftCodeMigration($pdo);
        $size=$command==='apply-plan'?($argv[5]??'50'):($argv[4]??'50');if(!preg_match('/^[1-9][0-9]{0,2}$/D',$size))throw new InvalidArgumentException('Invalid page size');
        if($command==='apply-plan'){$after=$argv[4]??'-';$result=$runner->applyPage($plan,$sha,$after==='-'?'':$after,(int)$size);}
        else $result=$runner->cleanupPage($plan,$sha,(int)$size);
    }
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $failure){
    $code=$failure instanceof GiftCodesFailure||$failure instanceof PlayerValuesFailure?$failure->errorCode:'INVALID_OR_FAILED_OPERATION';
    fwrite(STDERR,'Gift-code operation rejected: '.$code."\n");
    if($failure instanceof GiftCodesFailure||$failure instanceof PlayerValuesFailure)exit(in_array($code,['MIGRATION_REQUIRED','STATE_CONFLICT'],true)?4:2);
    exit($failure instanceof InvalidArgumentException||$failure instanceof JsonException?2:3);
}
