<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../src/Core/Config.php';
require_once __DIR__.'/../src/Core/Database.php';
require_once __DIR__.'/../src/SharedObjects/SharedObjectMigration.php';
try{
    chdir(dirname(__DIR__));Config::load('.env');$migration=new SharedObjectMigration(Database::connect());$command=$argv[1]??'plan';
    $digest=$argv[2]??'';$stopped=in_array('--writers-stopped',$argv,true);$backup=in_array('--backup-restore-verified',$argv,true);$upgraded=in_array('--all-readers-writers-upgraded',$argv,true);
    switch($command){
        case 'plan':$result=$migration->plan();break;
        case 'apply':$result=$migration->apply($digest,$stopped,$backup,$upgraded);break;
        case 'discard-staging':$migration->discardStaging($digest,$stopped,$backup);$result=['discarded'=>true];break;
        case 'retire':$migration->retire($digest,$stopped,$backup);$result=['retired'=>true];break;
        default:throw new InvalidArgumentException('Usage: plan | apply SHA256 --writers-stopped --backup-restore-verified --all-readers-writers-upgraded | discard-staging SHA256 --writers-stopped --backup-restore-verified | retire SHA256 --writers-stopped --backup-restore-verified');
    }echo SharedObjectRules::json($result)."\n";
}catch(Throwable $e){fwrite(STDERR,SharedObjectRules::json(['code'=>$e instanceof SharedObjectFailure?$e->errorCode:'MIGRATION_REFUSED','message'=>$e->getMessage()])."\n");exit(1);}
