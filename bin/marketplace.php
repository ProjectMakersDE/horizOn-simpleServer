<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../src/Core/Config.php';
require_once __DIR__.'/../src/Core/Database.php';
require_once __DIR__.'/../src/Marketplace/MarketService.php';
try {
    $command=$argv[1]??'limits';
    if(!in_array($command,['config','configure','limits','rules','rule','list','get','cancel','retry-return','recover','player','moderation','moderate','cleanup','cleanup-scheduled','cleanup-deleted'],true))throw new InvalidArgumentException('Unknown marketplace command');
    $revision=static function(int $i)use($argv){return MarketRules::revision($argv[$i]??null);};
    $id=static function(int $i)use($argv){return MarketRules::uuid($argv[$i]??null);};
    $body=static function(){return MarketRules::decode(stream_get_contents(STDIN,16385));};
    chdir(dirname(__DIR__));Config::load('.env');Database::migrate();$s=new MarketService(Database::connect());
    switch($command){
        case'config':$out=$s->config();break;
        case'configure':$out=$s->configure($revision(2),$body());break;
        case'limits':$out=$s->limits();$out=['limits'=>$out['limits'],'serverTime'=>$out['serverTime']];break;
        case'rules':$out=$s->rules(null,($argv[2]??'-')==='-'?null:$argv[2],isset($argv[3])?$revision(3):20);break;
        case'rule':$out=$s->putRule(MarketRules::itemKey($argv[2]??null),$revision(3),$body());break;
        case'list':$out=$s->adminListings(($argv[2]??'-')==='-'?null:$argv[2],isset($argv[3])?$revision(3):20,$argv[4]??null);break;
        case'get':$out=$s->get(null,$id(2));break;
        case'cancel':$r=MarketRules::fields($body(),['note']);$out=$s->adminCancel($id(2),$revision(3),MarketRules::note($r['note']));break;
        case'retry-return':$out=$s->retryReturn(null,$id(2));break;
        case'recover':$r=MarketRules::fields($body(),['recipientId','note']);$out=$s->recover($id(2),$revision(3),MarketRules::uuid($r['recipientId']),MarketRules::note($r['note']));break;
        case'player':$out=$s->state($id(2));break;
        case'moderation':$out=$s->moderation($id(2));break;
        case'moderate':$out=$s->moderate($id(2),$revision(3),$body());break;
        case'cleanup':$out=$s->cleanup('admin');break;
        case'cleanup-scheduled':$out=$s->cleanup('scheduled');break;
        case'cleanup-deleted':$out=$s->cleanupDeleted($argv[2]??null);break;
    }
    echo MarketRules::json($out)."\n";
} catch(MarketFailure $e) { fwrite(STDERR,MarketRules::json(['code'=>$e->errorCode,'message'=>$e->getMessage()])."\n");exit(1); }
catch(PlayerValuesFailure $e) { fwrite(STDERR,MarketRules::json(['code'=>$e->errorCode,'message'=>$e->getMessage()])."\n");exit(1); }
catch(InboxFailure $e) { fwrite(STDERR,MarketRules::json(['code'=>$e->errorCode,'message'=>$e->getMessage()])."\n");exit(1); }
catch(Throwable $e) { fwrite(STDERR,'Marketplace operation rejected: '.$e->getMessage()."\n");exit(1); }
