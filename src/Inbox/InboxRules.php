<?php

declare(strict_types=1);
require_once __DIR__ . '/../PlayerValues/PlayerValuesLedger.php';

class InboxFailure extends RuntimeException
{
    public string $errorCode;
    public int $status;
    public function __construct(string $code, string $message, int $status) { parent::__construct($message); $this->errorCode = $code; $this->status = $status; }
}

class InboxRules
{
    public const RETENTION = 2592000000;
    public const RECEIPTS = 50;
    public const TARGETED = 50;
    public const BROADCASTS = 500;
    public static function fail(string $code, string $message, int $status = 409): void { throw new InboxFailure($code, $message, $status); }
    public static function invalid(string $message): void { self::fail('INVALID_REQUEST', $message, 400); }
    public static function json($value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    public static function uuid($value): string
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value)) self::invalid('Invalid user UUID');
        return strtolower($value);
    }
    public static function entry($value): string
    {
        if (!is_string($value) || !preg_match('/^(message|broadcast):[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value)) self::invalid('Invalid entryId');
        // Entry identity is case-sensitive, as on the hosted server.
        return $value;
    }
    public static function text($value, int $max, bool $blank = true): string
    {
        if (!is_string($value) || !preg_match('//u', $value)) self::invalid('Invalid UTF-8 string');
        $units = preg_match_all('/./us', $value) + preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $value);
        if ($units > $max || (!$blank && preg_match('/^[\p{Z}\s]*$/u', $value))) self::invalid('Text exceeds its bound or is blank');
        return $value;
    }
    public static function instant(int $millis): string { return gmdate('Y-m-d\TH:i:s', intdiv($millis, 1000)) . sprintf('.%03dZ', $millis % 1000); }
    public static function millis($value): int
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) self::invalid('Expiry must be an ISO instant');
        try {
            $date = new DateTimeImmutable($value); $errors = DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) self::invalid('Invalid expiry');
            return $date->getTimestamp() * 1000 + (int)$date->format('v');
        } catch (Exception $error) { self::invalid('Invalid expiry'); }
    }
    public static function issued($operation): int
    {
        if (!is_string($operation) || !preg_match('/^([0-9]{1,16}):[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $operation, $match)) self::invalid('Invalid operationId');
        return (int)$match[1];
    }
    public static function content(array $input,bool $trustedReturn=false): array
    {
        $type = $input['type'] ?? null;
        if (!is_string($type) || !preg_match('/^[A-Za-z0-9._-]{1,32}$/D', $type)) self::invalid('Invalid type');
        $reference = self::text(array_key_exists('reference', $input) ? $input['reference'] : '', 128); $text = self::text($input['textKey'] ?? null, 128, false);
        $parameters = array_key_exists('parameters', $input) ? $input['parameters'] : [];
        if ($parameters instanceof stdClass) $parameters = (array)$parameters;
        if (!is_array($parameters) || count($parameters) > 8) self::invalid('At most eight parameters');
        foreach ($parameters as $key => $value) {
            if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/D', (string)$key) || !is_string($value) || !preg_match('//u', $value) || strlen($value) > 120) self::invalid('Invalid localization parameter');
        }
        ksort($parameters, SORT_STRING);
        $attachment = $input['attachment'] ?? null;
        if ($attachment !== null) {
            if (!is_array($attachment) || array_diff(array_keys($attachment), $trustedReturn ? ['values','itemReturn'] : ['values'])) self::invalid('Unsupported attachment');
            $itemReturn=$attachment['itemReturn']??null;
            if ($itemReturn!==null) {
                if (!$trustedReturn || !is_array($itemReturn) || array_diff(array_keys($itemReturn),['listingId','instanceId','itemKey']) || count($itemReturn)!==3) self::invalid('Trusted return source required');
                foreach (['listingId','instanceId'] as $field) if (self::uuid($itemReturn[$field])!==$itemReturn[$field]) self::invalid('Canonical return identity required');
                if (!is_string($itemReturn['itemKey']) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$itemReturn['itemKey'])) self::invalid('Invalid return item');
            }
            $values = PlayerValuesLedger::list(array_key_exists('values', $attachment) ? $attachment['values'] : [], 8); $keys = [];
            foreach ($values as &$value) {
                if (!is_array($value)) self::invalid('Invalid attachment');
                $key = PlayerValuesLedger::key($value['key'] ?? null); $amount = PlayerValuesLedger::integer($value['amount'] ?? null, 1);
                if (isset($keys[$key])) self::invalid('Duplicate value reward');
                $keys[$key] = true; $value = ['key' => $key, 'amount' => $amount];
            }
            unset($value); usort($values, static function ($a, $b) { return strcmp($a['key'], $b['key']); });
            if ($itemReturn!==null && $values) self::invalid('Item returns cannot contain value rewards');
            $attachment = ['values' => $values];
            if ($itemReturn!==null) $attachment['itemReturn']=$itemReturn;
        }
        return ['type' => $type, 'reference' => $reference, 'textKey' => $text, 'parameters' => (object)$parameters,
            'expiresAt' => self::instant(self::millis($input['expiresAt'] ?? null)), 'attachment' => $attachment];
    }
    public static function attachmentValuable(?array $attachment): bool { return !empty($attachment['values']) || isset($attachment['itemReturn']); }
    public static function valuable(array $message): bool { return self::attachmentValuable($message['content']['attachment']); }
    public static function retained(array $message, int $now): bool { return self::millis($message['content']['expiresAt']) > $now || (self::valuable($message) && !$message['claimed']); }
    public static function validate(array $content, int $now, bool $pending = false): void
    {
        $expiry = self::millis($content['expiresAt']);
        if (($expiry <= $now && !($pending && self::attachmentValuable($content['attachment']))) || $expiry > $now + self::RETENTION) self::invalid('Expiry must be in the next30 days');
        $worst = self::message('broadcast:00000000-0000-0000-0000-000000000000', $content, $now, str_repeat('x', 128), str_repeat('0', 64));
        if (strlen(self::json($worst)) > 1024) self::invalid('Message exceeds1024 UTF-8 bytes');
    }
    public static function storedMessage(array $message): void
    {
        // Provenance may contain Unicode or JSON escapes; the fixed reservation alone is insufficient.
        if (strlen(self::json($message)) > 1024) self::invalid('Message exceeds1024 UTF-8 bytes');
    }
    public static function message(string $id, array $content, int $now, string $operation, string $hash): array
    {
        return ['id' => $id, 'content' => $content, 'createdAt' => self::instant($now), 'read' => false, 'claimed' => false,
            'sourceOperationId' => $operation, 'payloadHash' => $hash];
    }
    public static function hash(array $content, string $source, int $issued): string { return hash('sha256', self::json([$source, $issued, $content])); }
    public static function replay(array $state, string $operation, string $hash): ?array
    {
        foreach ($state['entries'] as $message) if ($message['sourceOperationId'] === $operation) {
            if (!hash_equals($message['payloadHash'], $hash)) self::fail('OPERATION_CONFLICT', 'Operation ID already has another payload');
            return ['entryId' => $message['id']];
        }
        foreach ($state['receipts'] as $receipt) if ($receipt['operationId'] === $operation) {
            if (!hash_equals($receipt['payloadHash'], $hash)) self::fail('OPERATION_CONFLICT', 'Operation ID already has another payload');
            return $receipt;
        }
        return null;
    }
    public static function window(array $state, int $issued, int $now): void
    {
        if ($issued > $now + 30000) self::invalid('Operation is in the future');
        if ($issued <= $state['evictedThroughMillis'] || $issued < $now - self::RETENTION) self::fail('OPERATION_EXPIRED', 'Original operation is expired or evicted');
    }
    public static function record(array $state, string $operation, string $hash, int $issued, string $entry): array
    {
        $state['receipts'][] = ['operationId' => $operation, 'payloadHash' => $hash, 'issuedAtMillis' => $issued, 'entryId' => $entry];
        while (count($state['receipts']) > self::RECEIPTS) { $old = array_shift($state['receipts']); $state['evictedThroughMillis'] = max($state['evictedThroughMillis'], $old['issuedAtMillis']); }
        return $state;
    }
    public static function emptyState(bool $player = true): array { return ['revision' => 0, 'entries' => [], 'receipts' => [], 'evictedThroughMillis' => 0] + ($player ? ['markers' => []] : []); }
    public static function bump(array $state): array { $state['revision'] = PlayerValuesLedger::integer($state['revision'], 0, PlayerValuesLedger::MAX - 1) + 1; return $state; }
    public static function viewEntry(array $message, int $now, ?array $marker = null): array
    {
        return ['id' => $message['id'], 'kind' => explode(':', $message['id'])[0]] + $message['content'] +
            ['createdAt' => $message['createdAt'], 'overdue' => self::millis($message['content']['expiresAt']) <= $now,
            'read' => $marker['read'] ?? $message['read'], 'claimed' => $marker['claimed'] ?? $message['claimed']];
    }
}
