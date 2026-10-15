<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../src/Core/Config.php';
require_once __DIR__.'/../src/Core/Database.php';
require_once __DIR__.'/../src/Achievements/AchievementService.php';
try{
    $command=$argv[1]??'';
    if(!in_array($command,['definitions','configure','player'],true)){fwrite(STDERR,"Usage: php bin/achievements.php definitions | configure REVISION < definitions.json | player UUID\n");exit(2);}
    if($command==='configure'&&(!isset($argv[2])||!preg_match('/^[0-9]{1,16}$/D',$argv[2])||(int)$argv[2]>PlayerValuesLedger::MAX))AchievementRules::invalid('Expected numeric revision');
    $body=null;
    if($command==='configure'){
        $raw=stream_get_contents(STDIN,1024*1024+1);if(strlen($raw)>1024*1024||substr(ltrim($raw),0,1)!=='{')AchievementRules::invalid('Bounded JSON object required');
        $body=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    }
    chdir(dirname(__DIR__));Config::load('.env');Database::migrate();$service=new AchievementService(Database::connect());
    $result=$command==='definitions'?$service->definitions():($command==='configure'?$service->configure($body['definitions']??null,(int)$argv[2]):$service->get(InboxRules::uuid($argv[2]??null)));
    echo InboxRules::json($result)."\n";
}catch(Throwable $failure){fwrite(STDERR,'Achievement operation rejected: '.$failure->getMessage()."\n");exit(1);}
