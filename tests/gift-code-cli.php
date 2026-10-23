<?php

declare(strict_types=1);
require_once __DIR__.'/../src/Core/Database.php';
$dir=sys_get_temp_dir().'/gift-cli-'.Database::uuid();mkdir($dir);mkdir($dir.'/bin');copy(__DIR__.'/../bin/gift-codes.php',$dir.'/bin/gift-codes.php');symlink(realpath(__DIR__.'/../src'),$dir.'/src');symlink(realpath(__DIR__.'/../migrations'),$dir.'/migrations');$db=$dir.'/fixture.sqlite';$passed=0;
function gcCheck($expected,$actual,string $label):void{global$passed;if($expected!==$actual)throw new RuntimeException($label.': '.json_encode([$expected,$actual]));$passed++;}
function gcRun(string $dir,array $args,string $input=''):array{$pipes=[];$proc=proc_open(array_merge([PHP_BINARY,$dir.'/bin/gift-codes.php'],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('CLI process missing');fwrite($pipes[0],$input);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return[proc_close($proc),$out,$err];}
try{
    file_put_contents($dir.'/.env',"DB_DRIVER=sqlite\nDB_PATH=".$db."\nGIFT_CODES_CONFIG_BYTES=256\n");
    [$status,$out,$err]=gcRun($dir,['inspect-plan','MISSING',$dir.'/plan.json']);gcCheck(3,$status,'offline inspection without schema fails');gcCheck('', $out,'failed inspection has no invented plan');gcCheck("Gift-code operation rejected: INVALID_OR_FAILED_OPERATION\n",$err,'no PDO/config/secrets exposed');
    gcCheck(false,is_file($db),'inspection does not create a missing database file');
    $p=new PDO('sqlite:'.$db,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);gcCheck(0,(int)$p->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn(),'offline inspection never bootstraps tables');
    [$status,$out,$err]=gcRun($dir,['create','CLITEST'],'{"maxTotalRedemptions":null,"maxRedemptionsPerUser":null}');gcCheck(0,$status,'explicit create bootstraps V2');$view=json_decode($out,true,512,JSON_THROW_ON_ERROR);gcCheck(2,$view['protocolVersion'],'CLI starts V2');gcCheck(null,$view['exactRemainingRedemptions'],'CLI unlimited exact null');
    [$status,$out]=gcRun($dir,['configure','CLITEST',$view['configRevision']],'{"maxTotalRedemptions":5}');gcCheck(0,$status,'CLI exact revision accepted');gcCheck('5',json_decode($out,true)['exactRemainingRedemptions'],'CLI finite count stays string');
    [$status]=gcRun($dir,['configure','CLITEST','0'],'{}');gcCheck(2,$status,'stale config revision rejected');
    [$status]=gcRun($dir,['create','OVERSIZED'],json_encode(['giftData'=>str_repeat('x',256)]));gcCheck(2,$status,'operator-configurable JSON bytes enforced');gcCheck(0,(int)$p->query("SELECT COUNT(*) FROM gift_codes WHERE code='OVERSIZED'")->fetchColumn(),'oversized input stores nothing');
    [$status,$out]=gcRun($dir,['statistics']);gcCheck(0,$status,'CLI statistics');gcCheck('0',json_decode($out,true)['exactTotalRedemptions'],'aggregate is an exact decimal string');
    $p->exec('PRAGMA journal_mode=DELETE');$before=hash_file('sha256',$db);[$status]=gcRun($dir,['inspect-plan','CLITEST',$dir.'/never-created.json']);gcCheck(2,$status,'active campaign needs no legacy inspection');gcCheck($before,hash_file('sha256',$db),'read-only inspection preserves existing SQLite bytes');gcCheck('delete',$p->query('PRAGMA journal_mode')->fetchColumn(),'inspection never changes journal mode');
    echo 'PASS: '.$passed." Gift CLI assertions\n";
}finally{$p=null;foreach(['src','migrations']as$link)if(is_link($dir.'/'.$link))unlink($dir.'/'.$link);foreach(glob($dir.'/bin/*')?:[]as$file)unlink($file);rmdir($dir.'/bin');foreach(glob($dir.'/*')?:[]as$file)if(is_file($file))unlink($file);if(is_file($dir.'/.env'))unlink($dir.'/.env');rmdir($dir);}
