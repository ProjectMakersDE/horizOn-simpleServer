<?php

declare(strict_types=1);
require_once __DIR__.'/TrustedNoticeService.php';
/** Example-only durable backend outbox. Does not validate combat or create a replacement operation. */
class TrustedNoticeIntent
{
    private string $path;private $send;private $clock;
    public function __construct(string $path,TrustedNoticeService $send,?callable $clock=null)
    {
        if($path===''||$path[0]!=='/'||!is_dir(dirname($path))||is_link($path))SharedObjectRules::fail('INTENT_STORAGE_ERROR');
        $this->path=$path;$this->send=$send;$this->clock=$clock??static function(){return Database::nowMillis();};
    }
    private function locked(callable $work): array
    {
        if(!@mkdir($this->path.'.lock',0700))SharedObjectRules::fail('INTENT_STORAGE_ERROR');
        try{if(file_exists($this->path.'.next')||is_link($this->path))SharedObjectRules::fail('INTENT_STORAGE_ERROR');return $work();}finally{@rmdir($this->path.'.lock');}
    }
    private function read(): ?array
    {
        if(!file_exists($this->path))return null;$raw=file_get_contents($this->path,false,null,0,32769);if($raw===false||strlen($raw)>32768)SharedObjectRules::fail('INTENT_STORAGE_ERROR');
        try{$value=SharedObjectRules::fields(SharedObjectRules::decode($raw),['status','intent','binding','attempts','result','lastError']);}catch(Throwable $e){SharedObjectRules::fail('INTENT_STORAGE_ERROR');}
        if(!in_array($value['status']??null,['pending','delivered','expired','unconfirmed'],true)||!isset($value['intent'],$value['binding'])||!is_int($value['attempts']??null)||$value['attempts']<0||!is_string($value['lastError']??null))SharedObjectRules::fail('INTENT_STORAGE_ERROR');
        $value['intent']=SharedObjectRules::fields($value['intent'],['apiKeyId','userId','operationId','issuedAt','expiresAt','textKey','reference','parameters']);$value['binding']=SharedObjectRules::fields($value['binding'],['defenseObjectId','defenseRevision','runId']);
        try{
            $this->validate($value['intent'],$value['binding']);SharedObjectRules::integer($value['attempts']);
            if(!array_key_exists('result',$value))SharedObjectRules::invalid();
            if($value['status']==='delivered'){$result=SharedObjectRules::fields($value['result'],['entryId','replayed']);if(count($result)!==2||$value['attempts']<1||!is_bool($result['replayed']??null)||strpos((string)($result['entryId']??''),'message:')!==0)SharedObjectRules::invalid();InboxRules::entry($result['entryId']);$value['result']=$result;}
            elseif($value['result']!==null)SharedObjectRules::invalid();
        }catch(Throwable $error){SharedObjectRules::fail('INTENT_STORAGE_ERROR');}return $value;
    }
    private function validate(array $intent,array $binding): void
    {
        TrustedNoticeService::validateInput($intent);
        SharedObjectRules::uuid($binding['defenseObjectId']??null);SharedObjectRules::integer($binding['defenseRevision']??null);SharedObjectRules::uuid($binding['runId']??null);
    }
    private static function same($first,$second): bool
    {
        if($first instanceof stdClass&&$second instanceof stdClass){$first=get_object_vars($first);$second=get_object_vars($second);}
        if(is_array($first)&&is_array($second)){$keys=array_keys($first);$other=array_keys($second);sort($keys,SORT_STRING);sort($other,SORT_STRING);if($keys!==$other)return false;foreach($keys as $key)if(!self::same($first[$key],$second[$key]))return false;return true;}
        return $first===$second;
    }
    private function write(array $state): void
    {
        $mask=umask(0077);try{$file=@fopen($this->path.'.next','x+b');}finally{umask($mask);}if($file===false)SharedObjectRules::fail('INTENT_STORAGE_ERROR');@chmod($this->path.'.next',0600);$raw=SharedObjectRules::json($state);$written=0;
        try{while($written<strlen($raw)){$bytes=fwrite($file,substr($raw,$written));if($bytes===false||$bytes===0)SharedObjectRules::fail('INTENT_STORAGE_ERROR');$written+=$bytes;}if(!fflush($file))SharedObjectRules::fail('INTENT_STORAGE_ERROR');}finally{fclose($file);}
        if(!@rename($this->path.'.next',$this->path))SharedObjectRules::fail('INTENT_STORAGE_ERROR');
    }
    public function stage($raw): array
    {
        $input=SharedObjectRules::fields($raw,['intent','binding']);$intent=SharedObjectRules::fields($input['intent']??null,['apiKeyId','userId','operationId','issuedAt','expiresAt','textKey','reference','parameters']);$binding=SharedObjectRules::fields($input['binding']??null,['defenseObjectId','defenseRevision','runId']);$this->validate($intent,$binding);
        return $this->locked(function()use($intent,$binding){$before=$this->read();if($before!==null){if(!self::same($before['intent'],$intent)||!self::same($before['binding'],$binding))SharedObjectRules::fail('INTENT_CONFLICT');return $before;}$state=['status'=>'pending','intent'=>$intent,'binding'=>$binding,'attempts'=>0,'result'=>null,'lastError'=>''];$this->write($state);return $state;});
    }
    public function deliver(): array
    {
        return $this->locked(function(){
            $state=$this->read();if($state===null)SharedObjectRules::fail('INTENT_STORAGE_ERROR');if($state['status']!=='pending')return $state;
            if(($this->clock)()>=InboxRules::millis($state['intent']['expiresAt'])){$state['status']='expired';$this->write($state);return $state;}
            $state['attempts']=SharedObjectRules::next($state['attempts']);$state['lastError']='';$this->write($state); // Durable before any delivery attempt.
            try{
                $result=$this->send->sendCommitted($state['intent']);if(!is_array($result)||!is_string($result['entryId']??null)||strpos($result['entryId'],'message:')!==0||!is_bool($result['replayed']??null))throw new RuntimeException('Invalid delivery result');InboxRules::entry($result['entryId']);
                $state['status']='delivered';$state['result']=$result;
            }catch(InboxFailure $failure){$state['lastError']=$failure->errorCode;if(in_array($failure->errorCode,['OPERATION_EXPIRED','OPERATION_CONFLICT'],true))$state['status']='unconfirmed';}
            catch(SharedObjectFailure $failure){$state['lastError']=$failure->errorCode;}
            catch(Throwable $failure){$state['lastError']='UNCONFIRMED_ATTEMPT';}
            if($state['status']==='pending'&&($this->clock)()>=InboxRules::millis($state['intent']['expiresAt']))$state['status']='expired';
            $this->write($state);return $state;
        });
    }
}
