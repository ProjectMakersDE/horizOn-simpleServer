<?php

declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require_once __DIR__.'/../src/Core/Config.php';
require_once __DIR__.'/../src/Core/Database.php';
require_once __DIR__.'/../src/Items/ItemService.php';
require_once __DIR__.'/../src/Items/ItemMigration.php';
require_once __DIR__.'/../src/Items/ItemJson.php';
try {
    $command=$argv[1] ?? '';
    if (!in_array($command,['catalog','put','player','limits','compact-inspect','compact-apply','collect-basis'],true)) { fwrite(STDERR,"Usage: php bin/items.php catalog [AFTER_KEY] [REVISION] | put KEY EXPECTED_REVISION < template.json | player UUID | limits | compact-inspect/compact-apply KIND [CURSOR|-] [LIMIT] | collect-basis VERSION\n"); exit(2); }
    if(in_array($command,['compact-apply','collect-basis'],true)&&(!in_array('--writes-stopped',$argv,true)||!in_array('--backup-verified',$argv,true)))throw new RuntimeException('Stop all writers and verify a backup before conversion or collection');
    chdir(dirname(__DIR__)); Config::load('.env'); Database::migrate(); $service=new ItemService(Database::connect());
    $revision=static function ($value): int { if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,15})$/D',$value)) ItemRules::invalid(); return ItemRules::integer((int)$value); };
    if($command==='compact-inspect'||$command==='compact-apply')$result=(new ItemMigration(Database::connect()))->page($argv[2]??'',isset($argv[3])&&$argv[3]!=='-'?$argv[3]:null,isset($argv[4])?$revision($argv[4]):20,$command==='compact-apply');
    elseif($command==='collect-basis')$result=['removed'=>(new ItemMigration(Database::connect()))->collect($revision($argv[2]??''))];
    elseif ($command==='put') $result=$service->put(ItemRules::key($argv[2] ?? null),$revision($argv[3] ?? null),ItemJson::decode(stream_get_contents(STDIN,65537)));
    elseif ($command==='catalog') $result=$service->catalog(null,$argv[2] ?? null,50,isset($argv[3]) ? $revision($argv[3]) : null);
    elseif ($command==='player') $result=$service->inspect(ItemRules::uuid($argv[2] ?? null));
    else $result=$service->limits();
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
} catch (ItemFailure $failure) { fwrite(STDERR,json_encode(['code'=>$failure->errorCode,'message'=>$failure->getMessage()])."\n"); exit(1); }
catch (Throwable $error) { fwrite(STDERR,'Items command failed: '.$error->getMessage()."\n"); exit(1); }
