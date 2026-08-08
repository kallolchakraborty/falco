<?php // src/Data/Connection.php
namespace Falco\Data;

final class Connection
{
    private \PDO $pdo;

    public function __construct(string $dsn, string $user = '', string $pass = '', array $options = [])
    {
        $defaults = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC];
        $this->pdo = new \PDO($dsn, $user, $pass, $defaults + $options);
    }

    public static function fromDsn(string $dsn, string $user = '', string $pass = ''): self
    {
        return new self($dsn, $user, $pass);
    }

    public function pdo(): \PDO { return $this->pdo; }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function exec(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}

