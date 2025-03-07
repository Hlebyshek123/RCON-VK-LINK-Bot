<?php
namespace PROTECT\CID;

use mysqli;

class DatabaseController {
    
    private $db;
    private $plugin;

    public function __construct($plugin) {
        $this->plugin = $plugin;
        $this->connect();
        $this->createTables();
    }

    private function connect() {
        $this->db = new mysqli(
            "sql7.freesqldatabase.com", 
            "sql7762171", 
            "pBezl7BsfF", 
            "sql7762171"
        );

        if ($this->db->connect_error) {
            $this->plugin->getLogger()->error("Ошибка подключения к MySQL: " . $this->db->connect_error);
            return;
        }
    }
    
    public function close(): void {
       if ($this->db) {
           $this->db->close();
           $this->db = null;
       }
   }

    private function createTables() {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS skin (
                player VARCHAR(32) NOT NULL,
                hash VARCHAR(8) NOT NULL,
                UNIQUE KEY player_unique (player)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS cid (
                player VARCHAR(32) NOT NULL,
                hash VARCHAR(8) NOT NULL,
                UNIQUE KEY player_unique (player)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS uuid (
                player VARCHAR(32) NOT NULL,
                hash VARCHAR(8) NOT NULL,
                UNIQUE KEY player_unique (player)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8
        ");
    }

    public function addSkinProtection(string $playerName, $skinData) : bool {
        $playerName = strtolower($playerName);
        $skinHash = hash('crc32', $skinData);

        $stmt = $this->db->prepare("
            INSERT INTO skin (player, hash) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE hash = ?
        ");
        $stmt->bind_param("sss", $playerName, $skinHash, $skinHash);
        return $stmt->execute();
    }

    public function checkSkinProtection(string $playerName, $skinData) : bool {
        $playerName = strtolower($playerName);
        $skinHash = hash('crc32', $skinData);

        $stmt = $this->db->prepare("
            SELECT hash FROM skin 
            WHERE player = ?
        ");
        $stmt->bind_param("s", $playerName);
        $stmt->execute();
        $result = $stmt->get_result();

        if($result->num_rows === 0) return true;
        
        $data = $result->fetch_assoc();
        return $data['hash'] === $skinHash;
    }

    public function removeSkinProtection(string $playerName) : bool {
        $playerName = strtolower($playerName);
        
        $stmt = $this->db->prepare("
            DELETE FROM skin 
            WHERE player = ?
        ");
        $stmt->bind_param("s", $playerName);
        return $stmt->execute();
    }

    // Аналогичные методы для CID и UUID

    public function addCidProtection(string $playerName, $cid) : bool {
        $playerName = strtolower($playerName);
        $cidHash = hash('crc32', $cid);

        $stmt = $this->db->prepare("
            INSERT INTO cid (player, hash) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE hash = ?
        ");
        $stmt->bind_param("sss", $playerName, $cidHash, $cidHash);
        return $stmt->execute();
    }

    public function checkCidProtection(string $playerName, $cid) : bool {
        $playerName = strtolower($playerName);
        $cidHash = hash('crc32', $cid);

        $stmt = $this->db->prepare("
            SELECT hash FROM cid 
            WHERE player = ?
        ");
        $stmt->bind_param("s", $playerName);
        $stmt->execute();
        $result = $stmt->get_result();

        if($result->num_rows === 0) return true;
        
        $data = $result->fetch_assoc();
        return $data['hash'] === $cidHash;
    }

    public function removeCidProtection(string $playerName) : bool {
        $playerName = strtolower($playerName);
        
        $stmt = $this->db->prepare("
            DELETE FROM cid 
            WHERE player = ?
        ");
        $stmt->bind_param("s", $playerName);
        return $stmt->execute();
    }

    public function addUuidProtection(string $playerName, $uuidString) : bool {
        $playerName = strtolower($playerName);
        $uuidHash = hash('crc32', $uuidString);

        $stmt = $this->db->prepare("
            INSERT INTO uuid (player, hash) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE hash = ?
        ");
        $stmt->bind_param("sss", $playerName, $uuidHash, $uuidHash);
        return $stmt->execute();
    }

    public function checkUuidProtection(string $playerName, $uuidString) : bool {
        $playerName = strtolower($playerName);
        $uuidHash = hash('crc32', $uuidString);

        $stmt = $this->db->prepare("
            SELECT hash FROM uuid 
            WHERE player = ?
        ");
        $stmt->bind_param("s", $playerName);
        $stmt->execute();
        $result = $stmt->get_result();

        if($result->num_rows === 0) return true;
        
        $data = $result->fetch_assoc();
        return $data['hash'] === $uuidHash;
    }

    public function removeUuidProtection(string $playerName) : bool {
        $playerName = strtolower($playerName);
        
        $stmt = $this->db->prepare("
            DELETE FROM uuid 
            WHERE player = ?
        ");
        $stmt->bind_param("s", $playerName);
        return $stmt->execute();
    }

    public function __destruct() {
       $this->close();
   }
}