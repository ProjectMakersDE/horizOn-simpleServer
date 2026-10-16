<?php

declare(strict_types=1);
require_once __DIR__ . '/PlayerProfileController.php';

/** Bounded selected public fields only; never reads tokens, email, unlocks or saves. */
class PublicPlayerDisplayService
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }
    public function load(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if (count($ids) > 340) throw new InvalidArgumentException('Public projection exceeds its bound');
        if (!$ids) return [];
        $query = $this->pdo->prepare('SELECT id,display_name,avatar_id,frame_id,badges,last_login_millis FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $query->execute($ids); $result = [];
        foreach ($query->fetchAll() as $row) {
            $login = $row['last_login_millis'];
            $result[$row['id']] = ['display' => ['name' => $row['display_name'], 'profile' => PlayerProfileController::profileFromRow($row)],
                'lastActiveAt' => $login !== null && (int)$login > 0 ? gmdate('Y-m-d\TH:i:s', intdiv((int)$login, 1000)) . sprintf('.%03dZ', (int)$login % 1000) : null];
        }
        return $result;
    }
    public static function emptyDisplay(): array { return ['name' => null, 'profile' => PlayerProfileController::emptyProfile()]; }
}
