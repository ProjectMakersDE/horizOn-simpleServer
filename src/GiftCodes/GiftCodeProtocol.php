<?php

declare(strict_types=1);

class GiftCodesFailure extends RuntimeException
{
    public string $errorCode;
    public int $httpStatus;
    public function __construct(string $message, string $code = 'INVALID_GIFT_CODE', int $status = 400)
    { parent::__construct($message); $this->errorCode = $code; $this->httpStatus = $status; }
}

/** Physical signed Int64 bounds; wire values are always canonical decimal strings. */
class GiftCodeProtocol
{
    public const MAX = '9223372036854775807';
    public static function fail(string $code, string $message, int $status = 409): void
    { throw new GiftCodesFailure($message, $code, $status); }
    public static function count($value): int
    {
        if (PHP_INT_SIZE !== 8 || !is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', $value) ||
            strlen($value) > 19 || (strlen($value) === 19 && strcmp($value, self::MAX) > 0))
            self::fail('INVALID_REQUEST', 'Expected a canonical nonnegative Int64 decimal string', 400);
        return (int)$value;
    }
    public static function stored($value): int
    {
        try { return self::count(is_int($value) ? (string)$value : $value); }
        catch (GiftCodesFailure $error) { self::fail('MIGRATION_REQUIRED', 'Stored counter requires reconciliation'); }
    }
    public static function next(int $value): int
    { if ($value < 0 || $value === PHP_INT_MAX) self::fail('COUNTER_EXHAUSTED', 'Counter or revision exhausted'); return $value + 1; }
    public static function uuid($value): string
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value))
            self::fail('INVALID_REQUEST', 'Expected a campaign/player UUID', 400);
        return strtolower($value);
    }
    public static function binary(string $uuid): string { return hex2bin(str_replace('-', '', self::uuid($uuid))); }
    public static function intent(array $input): array
    {
        if (($input['protocolVersion'] ?? null) !== 2) self::fail('UPGRADE_REQUIRED', 'V1 redemption and replay are closed; resolve old outcomes before preparing V2');
        if (($input['idempotencyId'] ?? null) !== null) self::fail('INVALID_REQUEST', 'Do not mix V1 idempotencyId and V2', 400);
        $campaign = self::uuid($input['campaignId'] ?? null);
        $expected = self::count($input['expectedCount'] ?? null); $revision = self::count($input['configRevision'] ?? null);
        return ['campaignId'=>$campaign, 'expectedCount'=>$expected, 'configRevision'=>$revision];
    }
    /** Decimal sums may exceed one physical Int64; no float/BCMath dependency. */
    public static function sum(string $left, string $right): string
    {
        $out=''; $carry=0; $a=strlen($left)-1; $b=strlen($right)-1;
        while ($a>=0 || $b>=0 || $carry) {
            $n=($a>=0?(int)$left[$a--]:0)+($b>=0?(int)$right[$b--]:0)+$carry;
            $out=($n%10).$out; $carry=intdiv($n,10);
        }
        return $out===''?'0':$out;
    }
}
