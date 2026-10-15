
<?php
class Database {
    private $host = "localhost";
    private $db_name = "event_ticketing_db";
    private $username = "root";
    private $password = "";

    public function connect() {
        try {
            $pdo = new PDO(
                "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4",
                $this->username,
                $this->password
            );

            $pdo->setAttribute(
                PDO::ATTR_ERRMODE,
                PDO::ERRMODE_EXCEPTION
            );

            $pdo->setAttribute(
                PDO::ATTR_DEFAULT_FETCH_MODE,
                PDO::FETCH_ASSOC
            );

            $pdo->setAttribute(
                PDO::ATTR_EMULATE_PREPARES,
                false
            );

            return $pdo;

        } catch (PDOException $e) {
            error_log($e->getMessage());
            throw new RuntimeException(
                "Database connection failed."
            );
        }
    }
}
