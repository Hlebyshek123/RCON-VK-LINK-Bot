<?php

namespace vkplaytime;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;

class PT extends PluginBase implements Listener {

    private $db;

    public function __construct(){}

    public function onEnable(){
        $this->initDatabase();
        $this->getLogger()->info("VKплейтайме успешно запущен!");

        $this->getServer()->getScheduler()->scheduleRepeatingTask(new \pocketmine\scheduler\CallbackTask([$this, "updateTimer"]), 20 * 1);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    private function initDatabase(){
        $this->db = new \SQLite3($this->getDataFolder() . "playtime.db");
        $this->db->exec("CREATE TABLE IF NOT EXISTS players (
            username TEXT PRIMARY KEY, 
            date TEXT, 
            last_session INTEGER, 
            time INTEGER
        )");
    }

    public function onPreJoin(PlayerPreLoginEvent $e){
        $name = strtolower($e->getPlayer()->getName());
        $result = $this->db->querySingle("SELECT username FROM players WHERE username = '$name'");
        if(!$result){
            $this->db->exec("INSERT INTO players (username, date, last_session, time) VALUES ('$name', '', 0, 0)");
        }
    }

    public function onJoin(PlayerJoinEvent $e){
        $name = strtolower($e->getPlayer()->getName());
        // При входе обнуляем поле last_session
        $this->db->exec("UPDATE players SET last_session = 0 WHERE username = '$name'");
    }

    public function onQuit(PlayerQuitEvent $e){
        $name = strtolower($e->getPlayer()->getName());
        // Получаем время, которое игрок провел на сервере в текущей сессии
        $joinedTime = $this->db->querySingle("SELECT last_session FROM players WHERE username = '$name'");
        
        if($joinedTime !== false){
            // Вычисляем время пребывания на сервере в текущей сессии
            $currentTime = time();
            $timeSpent = $joinedTime; // last_session уже содержит текущее количество секунд

            // Сохраняем общее время, проведенное на сервере, и обновляем last_session
            $this->db->exec("UPDATE players SET time = time + $timeSpent WHERE username = '$name'");
            
            // Сохраняем дату выхода игрока
            $date = date("d.m.Y:H:i", $currentTime);
            $this->db->exec("UPDATE players SET date = '$date' WHERE username = '$name'");
        }
    }

    public function onCommand(CommandSender $p, Command $c, $label, array $args): bool {
        if($c->getName() == "ptime"){
            $name = strtolower($p->getName());
            $timeResult = $this->db->querySingle("SELECT time, last_session FROM players WHERE username = '$name'", true);
            
            if($timeResult !== null){
                $totalTime = $timeResult['time'];
                $lastSession = $timeResult['last_session'];

                // Общее игровое время (в секундах -> часы, минуты)
                $hours = floor($totalTime / 3600);
                $minutes = floor(($totalTime % 3600) / 60);

                $p->sendMessage("§l§cF§l§fC §l§8| §7§lВаше общее игровое время составляет: §6$hours §l§aЧасов, §6$minutes §l§fМинут\n"
                               ."§7§lПоследняя сессия: §6$lastSession §l§fСекунд");
            } else {
                $p->sendMessage("§l§cF§l§fC §l§8| §7§lДанных о времени не найдено.");
            }
            return true;
        }
        return false;
    }

    public function updateTimer(){
        foreach($this->getServer()->getOnlinePlayers() as $p){
            $name = strtolower($p->getName());
            // Увеличиваем счетчик времени последней сессии
            $this->db->exec("UPDATE players SET last_session = last_session + 1 WHERE username = '$name'");
        }
    }
}
?>