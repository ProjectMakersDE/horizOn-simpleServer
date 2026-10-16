<?php

declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require_once __DIR__.'/../src/Core/Config.php';
require_once __DIR__.'/../src/Core/Database.php';
require_once __DIR__.'/../src/Items/ItemService.php';
require_once __DIR__.'/../src/Items/ItemJson.php';
try {
    $command=$argv[1] ?? '';
    if (!in_array($command,['catalog','put','player','limits'],true)) { fwrite(STDERR,"Usage: php bin/items.php catalog [AFTER_KEY] [REVISION] | put KEY EXPECTED_REVISION < template.json | player UUID | limits\n"); exit(2); }
    chdir(dirname(__DIR__)); Config::load('.env'); Database::migrate(); $service=new ItemService(Database::connect());
    $revision=static function ($value): int { if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,15})$/D',$value)) ItemRules::invalid(); return ItemRules::integer((int)$value); };
    if ($command==='put') $result=$service->put(ItemRules::key($argv[2] ?? null),$revision($argv[3] ?? null),ItemJson::decode(stream_get_contents(STDIN,65537)));
    elseif ($command==='catalog') $result=$service->catalog(null,$argv[2] ?? null,50,isset($argv[3]) ? $revision($argv[3]) : null);
    elseif ($command==='player') $result=$service->inspect(ItemRules::uuid($argv[2] ?? null));
    else $result=$service->limits();
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
} catch (ItemFailure $failure) { fwrite(STDERR,json_encode(['code'=>$failure->errorCode,'message'=>$failure->getMessage()])."\n"); exit(1); }
catch (Throwable $error) { fwrite(STDERR,'Items command failed: '.$error->getMessage()."\n"); exit(1); }
