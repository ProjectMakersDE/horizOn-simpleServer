<?php

declare(strict_types=1);
require_once __DIR__.'/InboxRules.php';

/** SQL JSON boundary. Binary payloads are Base64; native SQL lengths are measured separately from BSON. */
class InboxStorage
{
    public const SOURCE_BYTES = 34;
    public const ORDINARY_BYTES = 17;
    public const TYPES = ['friend_request','friend_accept','group_invite','group_request','group_accept-invite','group_accept-request','match_turn','marketplace'];
    public const KEYS = ['horizon.friends.request','horizon.friends.accept','horizon.groups.invite','horizon.groups.request','horizon.groups.accept-invite','horizon.groups.accept-request','horizon.matches.your_turn','marketplace.item_return'];
    public static function required(): void { InboxRules::fail('MIGRATION_REQUIRED','Original Inbox state needs reconciliation and is preserved'); }
    public static function uuidBytes(string $id): string { return hex2bin(str_replace('-','',InboxRules::uuid($id))); }
    public static function uuid(string $bytes): string {
        if(strlen($bytes)!==16)self::required();$h=bin2hex($bytes);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
    public static function bytes($text): string {
        if(!is_string($text))self::required();$bytes=base64_decode($text,true);
        if($bytes===false||base64_encode($bytes)!==$text)self::required();return $bytes;
    }
    private static function integer($value): int {
        if(!is_int($value)||$value<0||$value>PlayerValuesLedger::MAX)self::required();return $value;
    }
    private static function readLong(string $bytes,int $offset): int { return self::integer(unpack('P',substr($bytes,$offset,8))[1]); }
    private static function flag(bool $read,bool $claimed=false,bool $removed=false): int { return ($read?1:0)|($claimed?2:0)|($removed?4:0); }
    public static function namedUuid(string $input): string {
        $bytes=md5($input,true);$bytes[6]=chr((ord($bytes[6])&15)|48);$bytes[8]=chr((ord($bytes[8])&63)|128);return self::uuid($bytes);
    }
    public static function sourceId(array $entry,string $user): string {
        $text=$entry['kind'].':'.$entry['sourceId'].':'.$entry['issuedAtMillis'];
        if($entry['recipientBound']??true)$text.=':'.InboxRules::uuid($user);
        return 'message:'.self::namedUuid($text);
    }
    public static function sourceKind(array $content): ?int {
        $kind=array_search($content['type']??null,self::TYPES,true);
        return $kind!==false&&($content['textKey']??null)===self::KEYS[$kind]?$kind:null;
    }
    public static function emptyState(): array {
        return InboxRules::emptyState()+['_compact'=>true,'sourceEntries'=>[],'freeTextEntries'=>[],'rewardStates'=>'','ordinaryMarkers'=>[],'legacyClaims'=>[]];
    }
    public static function emptyCatalog(string $project): array {
        return ['apiKeyId'=>InboxRules::uuid($project)]+InboxRules::emptyState(false)+['_compact'=>true];
    }
    private static function list($value): array {
        if(!is_array($value)||array_keys($value)!==range(0,count($value)-1)&&$value!==[])self::required();return $value;
    }
    /** Known localization maps retain JSON object identity through associative SQL decoding. */
    private static function messages(array $rows): array {
        foreach($rows as&$row)if(is_array($row)&&isset($row['content'])&&is_array($row['content'])&&array_key_exists('parameters',$row['content'])&&is_array($row['content']['parameters']))$row['content']['parameters']=(object)$row['content']['parameters'];unset($row);return $rows;
    }
    /** Legacy reads retain their original payloads. Only explicit migration installs the compact version marker. */
    public static function state(string $raw): array {
        try{return self::decodeState($raw);}catch(InboxFailure $e){if($e->errorCode==='MIGRATION_REQUIRED')throw $e;self::required();}catch(Throwable $e){self::required();}
    }
    private static function decodeState(string $raw): array {
        $row=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($row))self::required();
        if(!array_key_exists('s',$row)){
            if(array_diff(array_keys($row),['revision','entries','markers','receipts','evictedThroughMillis'])||!isset($row['revision'],$row['entries'],$row['markers'],$row['receipts'],$row['evictedThroughMillis']))self::required();
            self::integer($row['revision']);self::integer($row['evictedThroughMillis']);
            foreach(['entries','markers','receipts']as$key)self::list($row[$key]);$row['entries']=self::messages($row['entries']);
            return $row+['_compact'=>false,'sourceEntries'=>[],'freeTextEntries'=>[],'rewardStates'=>'','ordinaryMarkers'=>[],'legacyClaims'=>[]];
        }
        if($row['s']!==1||array_diff(array_keys($row),['s','r','e','t','w','o','l','m','q','f','g']))self::required();
        $state=self::emptyState();$state['revision']=self::integer($row['r']??null);
        $packed=self::bytes($row['e']??'');if(strlen($packed)%self::SOURCE_BYTES!==0)self::required();$seen=[];
        for($offset=0;$offset<strlen($packed);$offset+=self::SOURCE_BYTES){
            $kind=ord($packed[$offset+16]);$status=ord($packed[$offset+17]);
            if(($kind&127)>7||$status>3)self::required();
            $entry=['sourceId'=>self::uuid(substr($packed,$offset,16)),'kind'=>$kind&127,'recipientBound'=>($kind&128)!==0,
                'issuedAtMillis'=>self::readLong($packed,$offset+18),'expiresAtMillis'=>self::readLong($packed,$offset+26),'read'=>($status&1)!==0,'claimed'=>($status&2)!==0];
            $identity=$entry['kind'].':'.$entry['sourceId'].':'.$entry['issuedAtMillis'];if(isset($seen[$identity]))self::required();$seen[$identity]=true;$state['sourceEntries'][]=$entry;
        }
        foreach(self::list($row['t']??[])as$text){
            if(!is_array($text)||count($text)!==5||!is_string($text[1])||!preg_match('//u',$text[1])||!is_bool($text[4]))self::required();
            $id=self::uuid(self::bytes($text[0]));if(isset($seen['text:'.$id]))self::required();$seen['text:'.$id]=true;
            $state['freeTextEntries'][]=['id'=>$id,'text'=>$text[1],'createdAtMillis'=>self::integer($text[2]),'expiresAtMillis'=>self::integer($text[3]),'read'=>$text[4]];
        }
        $state['rewardStates']=self::bytes($row['w']??'');
        $packed=self::bytes($row['o']??'');if(strlen($packed)%self::ORDINARY_BYTES!==0)self::required();
        for($offset=0;$offset<strlen($packed);$offset+=self::ORDINARY_BYTES){
            $id='broadcast:'.self::uuid(substr($packed,$offset,16));$status=ord($packed[$offset+16]);
            if($status>7||isset($seen[$id]))self::required();$seen[$id]=true;
            $state['ordinaryMarkers'][]=['id'=>$id,'read'=>($status&1)!==0,'claimed'=>($status&2)!==0,'removed'=>($status&4)!==0];
        }
        foreach(['l'=>'entries','m'=>'markers','q'=>'receipts','g'=>'legacyClaims']as$key=>$target)$state[$target]=self::list($row[$key]??[]);$state['entries']=self::messages($state['entries']);$state['legacyClaims']=self::messages($state['legacyClaims']);
        $state['evictedThroughMillis']=self::integer($row['f']??0);return $state;
    }
    public static function encodeState(array $state): string {
        if(!$state['_compact'])self::required();$row=['s'=>1,'r'=>self::integer($state['revision'])];$packed='';
        foreach($state['sourceEntries']as$entry){
            $kind=$entry['kind'];if(!is_int($kind)||$kind<0||$kind>7)self::required();
            $packed.=self::uuidBytes($entry['sourceId']).chr($kind|(($entry['recipientBound']??true)?128:0)).chr(self::flag($entry['read'],$entry['claimed']))
                .pack('P',self::integer($entry['issuedAtMillis'])).pack('P',self::integer($entry['expiresAtMillis']));
        }
        if($packed!=='')$row['e']=base64_encode($packed);
        if($state['freeTextEntries'])$row['t']=array_map(static function($v){return[base64_encode(self::uuidBytes($v['id'])),$v['text'],$v['createdAtMillis'],$v['expiresAtMillis'],$v['read']];},$state['freeTextEntries']);
        if($state['rewardStates']!=='')$row['w']=base64_encode($state['rewardStates']);
        $packed='';foreach($state['ordinaryMarkers']as$marker)$packed.=self::uuidBytes(substr($marker['id'],10)).chr(self::flag($marker['read'],$marker['claimed'],$marker['removed']));
        if($packed!=='')$row['o']=base64_encode($packed);
        foreach(['l'=>'entries','m'=>'markers','q'=>'receipts','g'=>'legacyClaims']as$key=>$source)if($state[$source])$row[$key]=array_values(in_array($source,['entries','legacyClaims'],true)?self::messages($state[$source]):$state[$source]);
        if($state['evictedThroughMillis']!==0)$row['f']=self::integer($state['evictedThroughMillis']);return InboxRules::json($row);
    }
    public static function catalog(string $raw): array {
        try{
            $row=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($row))self::required();
            if(!array_key_exists('s',$row)){
                if(array_diff(array_keys($row),['apiKeyId','revision','entries','receipts','evictedThroughMillis'])||!isset($row['apiKeyId'],$row['revision'],$row['entries'],$row['receipts'],$row['evictedThroughMillis']))self::required();
                InboxRules::uuid($row['apiKeyId']);self::integer($row['revision']);self::integer($row['evictedThroughMillis']);self::list($row['entries']);self::list($row['receipts']);return $row+['_compact'=>false];
            }
            if($row['s']!==1||array_diff(array_keys($row),['s','p','r','e','q','f']))self::required();
            $catalog=self::emptyCatalog($row['p']??'');$catalog['apiKeyId']=$row['p'];$catalog['revision']=self::integer($row['r']??null);$seen=[];
            foreach(self::list($row['e']??[])as$message){
                self::list($message);if(count($message)!==4&&count($message)!==7)self::required();
                // A finite mixed-case legacy identity stays exact; fresh identities use UUID16 only.
                $id=is_string($message[0])&&strpos($message[0],'broadcast:')===0?InboxRules::entry($message[0]):'broadcast:'.self::uuid(self::bytes($message[0]));
                if(isset($seen[$id]))self::required();$seen[$id]=true;
                $content=json_decode($message[1],true,64,JSON_THROW_ON_ERROR);if(!is_array($content)||array_key_exists('expiresAt',$content))self::required();
                $content['parameters']=(object)$content['parameters'];$content['expiresAt']=InboxRules::instant(self::integer($message[3]));$status=$message[4]??0;if(!is_int($status)||$status<0||$status>3)self::required();
                $catalog['entries'][]=['id'=>$id,'content'=>$content,'createdAt'=>InboxRules::instant(self::integer($message[2])),'read'=>($status&1)!==0,'claimed'=>($status&2)!==0,'sourceOperationId'=>$message[5]??null,'payloadHash'=>$message[6]??null];
            }
            $catalog['receipts']=self::list($row['q']??[]);$catalog['evictedThroughMillis']=self::integer($row['f']??0);return $catalog;
        }catch(InboxFailure $e){if($e->errorCode==='MIGRATION_REQUIRED')throw $e;self::required();}catch(Throwable $e){self::required();}
    }
    public static function encodeCatalog(array $catalog): string {
        if(!$catalog['_compact'])self::required();$row=['s'=>1,'p'=>$catalog['apiKeyId'],'r'=>self::integer($catalog['revision'])];$messages=[];
        foreach($catalog['entries']as$message){
            $content=$message['content'];$expiry=InboxRules::millis($content['expiresAt']);unset($content['expiresAt']);$content['parameters']=(object)$content['parameters'];
            $identity=$message['id']==='broadcast:'.InboxRules::uuid(substr($message['id'],10))?base64_encode(self::uuidBytes(substr($message['id'],10))):InboxRules::entry($message['id']);
            $value=[$identity,InboxRules::json($content),InboxRules::millis($message['createdAt']),$expiry];
            if($message['read']||$message['claimed']||$message['sourceOperationId']!==null||$message['payloadHash']!==null){$value[]=self::flag($message['read'],$message['claimed']);$value[]=$message['sourceOperationId'];$value[]=$message['payloadHash'];}
            $messages[]=$value;
        }
        if($messages)$row['e']=$messages;
        if($catalog['receipts'])$row['q']=$catalog['receipts'];if($catalog['evictedThroughMillis']!==0)$row['f']=$catalog['evictedThroughMillis'];return InboxRules::json($row);
    }
    /** Valuable records are never removed/reordered: their retained order already is the permanent slot mapping. */
    public static function rewardSlot(array $catalog,string $id): int {
        $slot=0;foreach($catalog['entries']as$message)if(InboxRules::valuable($message)){if($message['id']===$id)return $slot;$slot++;}return -1;
    }
    public static function status(string $bytes,int $slot): int { return $slot<0||intdiv($slot,4)>=strlen($bytes)?0:(ord($bytes[intdiv($slot,4)])>>(($slot%4)*2))&3; }
    public static function setStatus(string $bytes,int $slot,int $status): string {
        if($slot<0||$status<0||$status>3)throw new LogicException('Invalid reward slot');$at=intdiv($slot,4);$shift=($slot%4)*2;
        if(strlen($bytes)<=$at)$bytes.=str_repeat("\0",$at+1-strlen($bytes));$bytes[$at]=chr((ord($bytes[$at])&~(3<<$shift))|($status<<$shift));return $bytes;
    }
}
