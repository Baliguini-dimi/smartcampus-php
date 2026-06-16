<?php
// =============================================================================
// SmartCampus SaaS — app/models/Database.php
// Classe PDO Singleton : une seule connexion par requête HTTP.
// Toutes les requêtes passent par cette classe (jamais de PDO direct).
// =============================================================================

declare(strict_types=1);

class Database
{
    // Instance unique (pattern Singleton)
    private static ?Database $instance = null;

    // Connexion PDO
    private PDO $pdo;

    // ---------------------------------------------------------------------
    // CONSTRUCTEUR PRIVÉ — Singleton
    // ---------------------------------------------------------------------
    private function __construct()
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // Vraies requêtes préparées
            PDO::ATTR_STRINGIFY_FETCHES  => false,   // Garder les types PHP natifs
            PDO::MYSQL_ATTR_FOUND_ROWS   => true,    // rowCount() fiable sur UPDATE
        ];

        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Ne jamais exposer les détails de connexion à l'utilisateur
            if (APP_DEBUG) {
                throw new RuntimeException('Connexion BDD échouée : ' . $e->getMessage());
            }
            error_log('[SmartCampus] Erreur PDO : ' . $e->getMessage());
            http_response_code(503);
            die('<h1>Service temporairement indisponible.</h1><p>Veuillez réessayer dans quelques instants.</p>');
        }
    }

    // Empêcher le clonage et la désérialisation (sécurité Singleton)
    private function __clone() {}
    public function __wakeup(): void { throw new RuntimeException('Cannot unserialize singleton.'); }

    // ---------------------------------------------------------------------
    // INSTANCE UNIQUE
    // ---------------------------------------------------------------------
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ---------------------------------------------------------------------
    // ACCÈS PDO BRUT (pour les cas avancés)
    // ---------------------------------------------------------------------
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    // ---------------------------------------------------------------------
    // LECTURE — Plusieurs lignes
    // ---------------------------------------------------------------------
    /**
     * Retourne un tableau de lignes ou [] si aucun résultat.
     *
     * @param  string $sql    Requête SQL avec placeholders (:param ou ?)
     * @param  array  $params Valeurs à binder
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            $this->logError($e, $sql);
            return [];
        }
    }

    // ---------------------------------------------------------------------
    // LECTURE — Une seule ligne
    // ---------------------------------------------------------------------
    /**
     * Retourne un tableau associatif ou null si non trouvé.
     *
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            return $result !== false ? $result : null;
        } catch (PDOException $e) {
            $this->logError($e, $sql);
            return null;
        }
    }

    // ---------------------------------------------------------------------
    // LECTURE — Valeur scalaire unique
    // ---------------------------------------------------------------------
    /**
     * Retourne une seule valeur (ex: COUNT(*), SUM(score)...).
     */
    public function fetchScalar(string $sql, array $params = []): mixed
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetchColumn();
            return $result !== false ? $result : null;
        } catch (PDOException $e) {
            $this->logError($e, $sql);
            return null;
        }
    }

    // ---------------------------------------------------------------------
    // ÉCRITURE — INSERT
    // ---------------------------------------------------------------------
    /**
     * Insère une ligne dans $table avec les données de $data.
     * Retourne le dernier ID inséré ou null en cas d'erreur.
     *
     * @param  string              $table Nom de la table
     * @param  array<string,mixed> $data  Colonnes => valeurs
     */
    public function insert(string $table, array $data): ?int
    {
        if (empty($data)) return null;

        $table   = $this->sanitizeIdentifier($table);
        $columns = implode(', ', array_map([$this, 'sanitizeIdentifier'], array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_values($data));
            return (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            $this->logError($e, $sql);
            return null;
        }
    }

    // ---------------------------------------------------------------------
    // ÉCRITURE — UPDATE
    // ---------------------------------------------------------------------
    /**
     * Met à jour les lignes de $table correspondant à $where.
     * Retourne le nombre de lignes affectées ou -1 en cas d'erreur.
     *
     * @param  string              $table Nom de la table
     * @param  array<string,mixed> $data  Colonnes à mettre à jour
     * @param  string              $where Clause WHERE (ex: "id = ?")
     * @param  array               $whereParams Valeurs du WHERE
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if (empty($data)) return 0;

        $table   = $this->sanitizeIdentifier($table);
        $setParts = array_map(
            fn($col) => $this->sanitizeIdentifier($col) . ' = ?',
            array_keys($data)
        );
        $set = implode(', ', $setParts);
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([...array_values($data), ...$whereParams]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError($e, $sql);
            return -1;
        }
    }

    // ---------------------------------------------------------------------
    // ÉCRITURE — DELETE
    // ---------------------------------------------------------------------
    /**
     * Supprime les lignes correspondant à $where.
     * Retourne le nombre de lignes supprimées ou -1 en cas d'erreur.
     */
    public function delete(string $table, string $where, array $params = []): int
    {
        $table = $this->sanitizeIdentifier($table);
        $sql   = "DELETE FROM {$table} WHERE {$where}";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError($e, $sql);
            return -1;
        }
    }

    // ---------------------------------------------------------------------
    // ÉCRITURE — Requête générique (INSERT ... ON DUPLICATE KEY, etc.)
    // ---------------------------------------------------------------------
    /**
     * Exécute une requête SQL libre et retourne le nombre de lignes affectées.
     */
    public function execute(string $sql, array $params = []): int
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError($e, $sql);
            return -1;
        }
    }

    // ---------------------------------------------------------------------
    // TRANSACTIONS
    // ---------------------------------------------------------------------
    public function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Exécute un callable dans une transaction.
     * Rollback automatique si une exception est levée.
     *
     * Exemple :
     *   $db->transaction(function() use ($db) {
     *       $db->insert('users', [...]);
     *       $db->insert('students', [...]);
     *   });
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    // ---------------------------------------------------------------------
    // UTILITAIRES
    // ---------------------------------------------------------------------

    /**
     * Compte les lignes d'une table selon une condition optionnelle.
     */
    public function count(string $table, string $where = '1', array $params = []): int
    {
        $table = $this->sanitizeIdentifier($table);
        $result = $this->fetchScalar("SELECT COUNT(*) FROM {$table} WHERE {$where}", $params);
        return (int)($result ?? 0);
    }

    /**
     * Vérifie si une valeur existe dans une colonne.
     */
    public function exists(string $table, string $column, mixed $value, ?int $excludeId = null): bool
    {
        $table  = $this->sanitizeIdentifier($table);
        $column = $this->sanitizeIdentifier($column);
        $sql    = "SELECT 1 FROM {$table} WHERE {$column} = ?";
        $params = [$value];
        if ($excludeId !== null) {
            $sql    .= ' AND id != ?';
            $params[] = $excludeId;
        }
        return (bool)$this->fetchScalar($sql . ' LIMIT 1', $params);
    }

    // ---------------------------------------------------------------------
    // PRIVÉ — Sécurité
    // ---------------------------------------------------------------------

    /**
     * Nettoie un nom de table ou de colonne pour éviter les injections
     * via les noms d'identifiants (les placeholders ne s'appliquent pas aux noms).
     */
    private function sanitizeIdentifier(string $name): string
    {
        // Autoriser uniquement lettres, chiffres, underscore
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Identifiant SQL invalide : '$name'");
        }
        return "`{$name}`";
    }

    /**
     * Logue une erreur PDO sans exposer les détails à l'utilisateur.
     */
    private function logError(PDOException $e, string $sql): void
    {
        $msg = sprintf(
            '[SmartCampus][DB] %s | SQL: %s | File: %s:%d',
            $e->getMessage(),
            substr($sql, 0, 200),
            $e->getFile(),
            $e->getLine()
        );
        error_log($msg);

        if (APP_DEBUG) {
            throw new RuntimeException($msg, 0, $e);
        }
    }
}
