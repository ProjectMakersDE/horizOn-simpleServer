<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../src/Core/Config.php';
require_once __DIR__.'/../src/Core/Database.php';
require_once __DIR__.'/../src/ItemGifts/GiftService.php';
// Local operator equivalents of the hosted /api/v1/admin/features/item-gifts routes. No public PHP admin route exists.
try {
    $command=$argv[1]??'config';
    if(!in_array($command,['config','configure','limits','list','get','retry-return','recover','player','cleanup','cleanup-deleted'],true))
        throw new InvalidArgumentException('Usage: config | configure REV < JSON | limits | list [STATUS|- [LIMIT [CURSOR]]] | get GIFT_ID | retry-return GIFT_ID | recover GIFT_ID REV < JSON | player USER_ID | cleanup | cleanup-deleted');
    $revision=static function(int $i)use($argv){return GiftRules::revision($argv[$i]??null);};
    $id=static function(int $i)use($argv){return GiftRules::uuid($argv[$i]??null);};
    $body=static function(){return GiftRules::decode(stream_get_contents(STDIN,16385));};
    chdir(dirname(__DIR__));Config::load('.env');Database::migrate();$s=new GiftService(Database::connect());
    switch($command){
        case'config':$out=$s->adminConfig();break;
        case'configure':$out=$s->configure($revision(2),$body());break;
        case'limits':$out=['limits'=>$s->limits()];break;
        case'list':$out=$s->adminGifts(($argv[2]??'-')==='-'?null:$argv[2],isset($argv[3])?$revision(3):20,$argv[4]??null);break;
        case'get':$out=$s->get(null,$id(2));break;
        case'retry-return':$out=$s->retryReturn($id(2));break;
        case'recover':$r=GiftRules::fields($body(),['recipientId','note']);$out=$s->recover($id(2),$revision(3),GiftRules::uuid($r['recipientId']),GiftRules::note($r['note']));break;
        case'player':$out=$s->state($id(2));break;
        case'cleanup':$out=$s->cleanup();break;
        case'cleanup-deleted':$out=$s->cleanupDeleted();break;
    }
    echo GiftRules::json($out)."\n";
} catch(GiftFailure $e) { fwrite(STDERR,GiftRules::json(['code'=>$e->errorCode,'message'=>$e->getMessage()])."\n");exit(1); }
catch(PlayerValuesFailure $e) { fwrite(STDERR,GiftRules::json(['code'=>$e->errorCode,'message'=>$e->getMessage()])."\n");exit(1); }
catch(InboxFailure $e) { fwrite(STDERR,GiftRules::json(['code'=>$e->errorCode,'message'=>$e->getMessage()])."\n");exit(1); }
catch(Throwable $e) { fwrite(STDERR,'Item gift operation rejected: '.$e->getMessage()."\n");exit(1); }
