<?php
namespace PROTECT\CID;

class DatabaseController extends \SQLite3 {
	
	public function __construct(CID $plugin) {
		$this->open($plugin->getDataFolder() . 'data/store.db');
		$this->query('CREATE TABLE IF NOT EXISTS skin ( 
			player TEXT NOT NULL,
			hash TEXT NOT NULL,
			UNIQUE(player)
		)');
		$this->query('CREATE TABLE IF NOT EXISTS cid ( 
			player TEXT NOT NULL,
			hash TEXT NOT NULL,
			UNIQUE(player)
		)');
		$this->query('CREATE TABLE IF NOT EXISTS uuid ( 
			player TEXT NOT NULL,
			hash TEXT NOT NULL,
			UNIQUE(player)
		)');
	}
	
	public function addSkinProtection(string $playerName, $skinData) : bool {
		if (!empty($playerName)) {
			$playerName = strtolower($playerName);
			$skinHash = hash('crc32', $skinData);
			
			$query = $this->prepare('INSERT OR REPLACE INTO skin VALUES (:player, :hash)');
			$query->bindValue(':player', $playerName, \SQLITE3_TEXT);
			$query->bindValue(':hash', $skinHash, \SQLITE3_TEXT);
			$query->execute();
			
			return true;
		} else {
			return false;
		}
	}
	
	public function checkSkinProtection(string $playerName, $skinData) : bool {
		if (!empty($playerName)) {
			$playerName = strtolower($playerName);
			$skinHash = hash('crc32', $skinData);
			
			$query = $this->prepare('SELECT hash FROM skin WHERE player = :player');
			$query->bindParam(':player', $playerName, \SQLITE3_TEXT);
			$result = $query->execute();
			
			return (!($data = $result->fetchArray(\SQLITE3_ASSOC)) || $data['hash'] === $skinHash);
		} else {
			return false;
		}
	}
	
	public function removeSkinProtection(string $playerName) : bool {
		if (!empty($playerName)) {
			$playerName = strtolower($playerName);
			
			$query = $this->prepare('DELETE FROM skin WHERE player = :player');
			$query->bindParam(':player', $playerName, \SQLITE3_TEXT);
			$query->execute();
			
			return true;
		} else {
			return false;
		}
	}
	
	public function addCidProtection(string $playerName, $cid) : bool {
		if (!empty($playerName)) {
			$playerName = strtolower($playerName);
			$cidHash = hash('crc32', $cid);
			
			$query = $this->prepare('INSERT OR REPLACE INTO cid VALUES (:player, :hash)');
			$query->bindValue(':player', $playerName, \SQLITE3_TEXT);
			$query->bindValue(':hash', $cidHash, \SQLITE3_TEXT);
			$query->execute();
			
			return true;
		} else {
			return false;
		}
	}
	
	public function checkCidProtection(string $playerName, $cid) : bool {
		if (!empty($playerName)) {
			$playerName = strtolower($playerName);
			$cidHash = hash('crc32', $cid);
			
			$query = $this->prepare('SELECT hash FROM cid WHERE player = :player');
			$query->bindParam(':player', $playerName, \SQLITE3_TEXT);
			$result = $query->execute();
			
			return (!($data = $result->fetchArray(\SQLITE3_ASSOC)) || $data['hash'] === $cidHash);
		} else {
			return false;
		}
	}
	
	public function removeCidProtection(string $playerName) : bool {
		if (!empty($playerName)) {
			$playerName = strtolower($playerName);
			
			$query = $this->prepare('DELETE FROM cid WHERE player = :player');
			$query->bindParam(':player', $playerName, \SQLITE3_TEXT);
			$query->execute();
			
			return true;
		} else {
			return false;
		}
	}
	
	public function addUuidProtection(string $playerName, $uuidString) : bool {
		if (!empty($playerName)) {
			$playerName = strtolower($playerName);
			$uuidHash = hash('crc32', $uuidString);
			
			$query = $this->prepare('INSERT OR REPLACE INTO uuid VALUES (:player, :hash)');
			$query->bindValue(':player', $playerName, \SQLITE3_TEXT);
			$query->bindValue(':hash', $uuidHash, \SQLITE3_TEXT);
			$query->execute();
			
			return true;
		} else {
			return false;
		}
	}
	
	public function checkUuidProtection(string $playerName, $uuidString) : bool {
		if (!empty($playerName)) {
			$playerName = strtolower($playerName);
			$uuidHash = hash('crc32', $uuidString);
			
			$query = $this->prepare('SELECT hash FROM uuid WHERE player = :player');
			$query->bindParam(':player', $playerName, \SQLITE3_TEXT);
			$result = $query->execute();
			
			return (!($data = $result->fetchArray(\SQLITE3_ASSOC)) || $data['hash'] === $uuidHash);
		} else {
			return false;
		}
	}
	
	public function removeUuidProtection(string $playerName) : bool {
		if (!empty($playerName)) {
			$playerName = strtolower($playerName);
			
			$query = $this->prepare('DELETE FROM uuid WHERE player = :player');
			$query->bindParam(':player', $playerName, \SQLITE3_TEXT);
			$query->execute();
			
			return true;
		} else {
			return false;
		}
	}
	
}
