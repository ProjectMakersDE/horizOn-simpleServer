<?php

declare(strict_types=1);
// Operator access is local CLI only, even when a web server serves this file directly.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../src/Core/Config.php';
require_once __DIR__.'/../src/Core/Database.php';
require_once __DIR__.'/../src/Matches/MatchService.php';
require_once __DIR__.'/../src/Matches/MatchMigration.php';
try {
    chdir(dirname(__DIR__));Config::load('.env');Database::migrate();
    $s=new MatchService(Database::connect());$command=$argv[1]??'limits';
    $revision=static function($index)use($argv){return MatchRules::revision($argv[$index]??null);};
    switch($command){
        case'compact-check':case'compact-apply':
            if($command==='compact-apply'&&array_slice($argv,5)!==['writers-stopped','backup-restored'])throw new InvalidArgumentException('Cold apply requires stopped upgraded writers and independently restored backup acknowledgements');
            $out=(new MatchMigration(Database::connect()))->page($argv[2]??'',($argv[3]??'-')==='-'?null:$argv[3],isset($argv[4])?MatchRules::revision($argv[4]):10,$command==='compact-apply');break;
        case'config':$out=$s->config();break;
        case'configure':$out=$s->configure($revision(2),MatchRules::decode(stream_get_contents(STDIN,20001)));break;
        case'limits':$out=$s->limits();break;
        case'list':$out=$s->listing(($argv[2]??'-')==='-'?null:$argv[2],isset($argv[3])?$revision(3):20,$argv[4]??null);break;
        case'get':$out=$s->get(null,MatchRules::uuid($argv[2]??null));break;
        case'cancel':$out=$s->adminCancel(MatchRules::uuid($argv[2]??null),$revision(3));break;
        case'cleanup':$out=$s->cleanup('admin');break;
        case'cleanup-scheduled':$out=$s->cleanup('scheduled');break;
        case'cleanup-deleted':$out=$s->cleanupDeleted($argv[2]??null);break;
        default:throw new InvalidArgumentException('Usage: config | configure REV < JSON | limits | list [STATUS|- [LIMIT [CURSOR]]] | get ID | cancel ID REV | cleanup | cleanup-scheduled | cleanup-deleted [AFTER_USER] | compact-check details|players [AFTER|- [1..20]] | compact-apply details|players AFTER|- 1..20 writers-stopped backup-restored');
    }
    echo MatchRules::json($out)."\n";
}catch(MatchFailure $e){fwrite(STDERR,MatchRules::json(['code'=>$e->errorCode,'message'=>$e->getMessage()])."\n");exit(1);}
catch(Throwable $e){fwrite(STDERR,'Matches operation rejected: '.$e->getMessage()."\n");exit(1);}
