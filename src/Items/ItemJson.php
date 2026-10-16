<?php

declare(strict_types=1);
require_once __DIR__ . '/ItemRules.php';

/** Native grammar/Unicode plus decoded duplicate keys and strict numerical lexemes. */
class ItemJson
{
    public static function decode(string $json,int $maxBytes=65536): stdClass
    {
        if (strlen($json)>$maxBytes) ItemRules::invalid();
        try { $value=json_decode($json,false,32,JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { ItemRules::invalid(); }
        if (!$value instanceof stdClass) ItemRules::invalid();
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]|true|false|null|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/s',$json,$matches);
        $tokens=$matches[0]; $stack=[];
        foreach ($tokens as $index=>$token) {
            if ($token==='{') $stack[]=[];
            elseif ($token==='[') $stack[]=null;
            elseif ($token==='}' || $token===']') array_pop($stack);
            elseif ($token[0]==='"' && ($tokens[$index+1] ?? '')===':') {
                $key=json_decode($token,false,4,JSON_THROW_ON_ERROR); $last=count($stack)-1;
                if ($last<0 || !is_array($stack[$last]) || array_key_exists($key,$stack[$last])) ItemRules::invalid();
                $stack[$last][$key]=true;
            } elseif ($token[0]==='-' || ctype_digit($token[0])) {
                if (!preg_match('/^-?(0|[1-9][0-9]*)$/D',$token)) ItemRules::invalid();
                ItemRules::integer(json_decode($token,false,4,JSON_THROW_ON_ERROR),-ItemRules::MAX);
            }
        }
        return $value;
    }
}
