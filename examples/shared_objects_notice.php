<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../src/Core/Database.php';
require_once __DIR__.'/../src/Core/Config.php';
require_once __DIR__.'/../src/SharedObjects/TrustedNoticeIntent.php';
require_once __DIR__.'/../src/SharedObjects/TrustedNoticeService.php';
// TRUSTED GAME BACKEND ONLY. Independently verify the original run/outcome before staging.
// Neither this recipe nor the ordinary notice endpoint verifies combat or awards a reward.
try{
    chdir(dirname(__DIR__));Config::load('.env');Database::migrate();$sender=new TrustedNoticeService(Database::connect());
    $path=$argv[2]??'';$intent=new TrustedNoticeIntent($path,$sender);
    if(($argv[1]??'')==='stage')$result=$intent->stage(SharedObjectRules::decode(stream_get_contents(STDIN,16385)));
    elseif(($argv[1]??'')==='deliver')$result=$intent->deliver();
    else throw new InvalidArgumentException('Usage: stage ABSOLUTE_STATE_PATH < verified-immutable-intent.json | deliver ABSOLUTE_STATE_PATH');
    echo SharedObjectRules::json($result)."\n";
}catch(Throwable $error){fwrite(STDERR,($error instanceof SharedObjectFailure?$error->errorCode:'NOTICE_RECIPE_ERROR').": preserve the original state and reconcile\n");exit(1);}
