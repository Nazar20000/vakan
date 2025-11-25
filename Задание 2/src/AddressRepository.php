<?php

class AddressRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function saveUnique(string $address): void
    {
        $sql = 'INSERT IGNORE INTO requests (address) VALUES (:address)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['address' => $address]);
    }

    public function getLast(int $limit = 10): array
    {
        $sql = 'SELECT address, created_at
                FROM requests
                ORDER BY created_at DESC
                LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}

