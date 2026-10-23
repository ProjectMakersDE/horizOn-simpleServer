<?php

declare(strict_types=1);
require_once __DIR__ . '/GiftCodeProtocol.php';
require_once __DIR__ . '/GiftCodeIdentity.php';
require_once __DIR__ . '/../Core/SqlTransaction.php';

/** 16-byte campaign + 16-byte player + signed Int64:40 raw payload bytes, SQL overhead separate. */
class GiftCodeCounterStore
{
    private PDO $pdo;
    private bool $mysql;
    private SqlTransaction $scope;
    public function __construct(PDO $pdo, SqlTransaction $scope)
    { if(!$scope->owns($pdo))throw new LogicException('Counter scope belongs to another connection'); $this->pdo=$pdo; $this->scope=$scope; $this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'; }
    private function active(): void { if(!$this->scope->isRunning())throw new LogicException('Counter write requires the owning transaction'); }
    private function query(string $sql,string $campaign,string $user,array $numbers=[]): PDOStatement
    {
        $q=$this->pdo->prepare($sql);
        $q->bindValue(':campaign',GiftCodeProtocol::binary($campaign),PDO::PARAM_LOB);
        $q->bindValue(':player',GiftCodeProtocol::binary($user),PDO::PARAM_LOB);
        foreach($numbers as$name=>$number)$q->bindValue(':'.$name,$number,PDO::PARAM_INT);
        $q->execute(); return $q;
    }
    public function read(string $campaign,string $user,bool $lock=false): int
    {
        if($lock)$this->active();
        $q=$this->query('SELECT count FROM gift_code_counts WHERE campaign_id=:campaign AND player_id=:player'.($lock&&$this->mysql?' FOR UPDATE':''),$campaign,$user);
        $row=$q->fetch(PDO::FETCH_ASSOC); $q->closeCursor();
        return $row===false?0:GiftCodeProtocol::stored($row['count']);
    }
    public function advance(string $campaign,string $user,int $expected): int
    {
        $this->active(); $next=GiftCodeProtocol::next($expected);
        $q=$this->query('UPDATE gift_code_counts SET count=:next WHERE campaign_id=:campaign AND player_id=:player AND count=:expected',
            $campaign,$user,['next'=>$next,'expected'=>$expected]);
        if($q->rowCount()===1)return $next;
        if($expected!==0)GiftCodeProtocol::fail('STATE_CONFLICT','Counter changed; retry the original tuple');
        $this->query('INSERT INTO gift_code_counts(campaign_id,player_id,count) VALUES(:campaign,:player,:next)',$campaign,$user,['next'=>$next]);
        return $next;
    }
    /** Offline guard installation is idempotent, never an increment or overwrite. */
    public function install(string $campaign,string $user,int $count): void
    {
        $this->active(); if($count<1)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Migration counts must be positive');
        $current=$this->read($campaign,$user,true);
        if($current!==0){if($current!==$count)GiftCodeProtocol::fail('STATE_CONFLICT','Existing pair differs from sealed source');return;}
        $this->query('INSERT INTO gift_code_counts(campaign_id,player_id,count) VALUES(:campaign,:player,:count)',$campaign,$user,['count'=>$count]);
    }
    public function all(string $campaign): Generator
    {
        $q=$this->pdo->prepare('SELECT player_id,count FROM gift_code_counts WHERE campaign_id=:campaign ORDER BY player_id');
        $q->bindValue(':campaign',GiftCodeProtocol::binary($campaign),PDO::PARAM_LOB);$q->execute();
        try{while($row=$q->fetch(PDO::FETCH_ASSOC))yield[GiftCodeIdentity::fromBytes($row['player_id']),GiftCodeProtocol::stored($row['count'])];}
        finally{$q->closeCursor();}
    }
}
