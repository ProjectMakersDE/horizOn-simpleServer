<?php
/** Finite historical envelopes used to saturate the shared targeted quota in source-owner regressions. */
require_once __DIR__.'/../src/Inbox/InboxStorage.php';
function fillInboxFixture(PDO $pdo,string $user,int $now,int $limit=50): void {
    $q=$pdo->prepare('SELECT data FROM inbox_state WHERE user_id=?');$q->execute([$user]);$raw=$q->fetchColumn();$state=$raw===false?InboxStorage::emptyState():InboxStorage::state($raw);
    $count=count($state['entries'])+count($state['sourceEntries'])+count($state['freeTextEntries']);
    for($i=$count;$i<$limit;$i++)$state['entries'][]=InboxRules::message('message:'.Database::uuid(),InboxRules::content(['type'=>'fixture','textKey'=>'fixture','expiresAt'=>InboxRules::instant($now+InboxRules::RETENTION)]),$now,'historical:'.Database::uuid(),str_repeat('0',64));
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$sql=$mysql?'INSERT INTO inbox_state(user_id,data) VALUES(?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)':'INSERT INTO inbox_state(user_id,data) VALUES(?,?) ON CONFLICT(user_id) DO UPDATE SET data=excluded.data';
    $pdo->prepare($sql)->execute([$user,InboxStorage::encodeState($state)]);
}

/** Authentic old writer format for migration tests after the rich-delivery API was intentionally closed. */
function legacyInboxFixture(PDO $pdo,string $user,array $input,string $source,int $issued,bool $compact=true): string {
    $content=InboxRules::content($input);$id='message:'.Database::uuid();$hash=InboxRules::hash($content,$source,$issued);
    $state=$compact?InboxStorage::emptyState():InboxRules::emptyState();$state['entries'][]=InboxRules::message($id,$content,$issued,$source,$hash);
    $state=InboxRules::record($state,$source,$hash,$issued,$id);$state=InboxRules::bump($state);
    $pdo->prepare('INSERT INTO inbox_state(user_id,data) VALUES(?,?)')->execute([$user,$compact?InboxStorage::encodeState($state):InboxRules::json($state)]);return $id;
}
