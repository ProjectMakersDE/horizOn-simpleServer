<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../src/Core/Config.php';
require_once __DIR__.'/../src/Core/Database.php';
require_once __DIR__.'/../src/SharedObjects/SharedObjectService.php';
require_once __DIR__.'/../src/SharedObjects/TrustedNoticeService.php';
try{
    chdir(dirname(__DIR__));Config::load('.env');Database::migrate();$pdo=Database::connect();$scope=new SqlTransaction($pdo);$service=new SharedObjectService($pdo,null,$scope);
    $command=$argv[1]??'limits';$revision=static function($index)use($argv){return SharedObjectRules::revision($argv[$index]??null);};$key=static function()use($argv){return SharedObjectRules::key($argv[2]??null);};
    $input=static function(){return SharedObjectRules::decode(stream_get_contents(STDIN,1500001));};
    switch($command){
        case 'collections':$result=$service->collections(null,$argv[2]??null,50,isset($argv[3])?$revision(3):null);break;
        case 'put':$result=$service->put($key(),$revision(3),$input());break;
        case 'usage':$result=$service->usage(null,$key(),SharedObjectRules::uuid($argv[3]??null));break;
        case 'get':$result=$service->get(null,$key(),SharedObjectRules::uuid($argv[3]??null));break;
        case 'query':$result=$service->query(null,$key(),$input());break;
        case 'create':$result=$service->create(null,$key(),$revision(3),$input());break;
        case 'update':$result=$service->update(null,$key(),SharedObjectRules::uuid($argv[3]??null),$revision(4),$input());break;
        case 'delete':$result=$service->delete(null,$key(),SharedObjectRules::uuid($argv[3]??null),$revision(4));break;
        case 'cleanup-expired':$result=$service->cleanupExpired();break;
        case 'cleanup-deleted':$result=$service->cleanupDeleted($argv[2]??null);break;
        case 'notice':$result=(new TrustedNoticeService($pdo,null,$scope))->send($input());break;
        case 'limits':$result=$service->limits();break;
        default:throw new InvalidArgumentException('Usage: collections [AFTER [CATALOG_REV]] | put KEY REV < JSON | usage KEY USER | get KEY ID | query KEY < JSON | create KEY USAGE_REV < JSON | update KEY ID REV < JSON | delete KEY ID REV | limits | cleanup-expired | cleanup-deleted [AFTER_USER] | notice < immutable-intent.json');
    }
    echo SharedObjectRules::json($result)."\n";
}catch(SharedObjectFailure $e){fwrite(STDERR,SharedObjectRules::json(['code'=>$e->errorCode,'message'=>$e->getMessage()])."\n");exit(1);}
catch(InboxFailure $e){fwrite(STDERR,SharedObjectRules::json(['code'=>$e->errorCode,'message'=>$e->getMessage()])."\n");exit(1);}
catch(Throwable $e){fwrite(STDERR,'Shared Objects operation rejected: '.$e->getMessage()."\n");exit(1);}
