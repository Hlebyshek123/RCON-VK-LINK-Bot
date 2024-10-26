<?php

namespace Snow;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\level\generator\biome\Biome;
use pocketmine\Player;
use pocketmine\level\Level;
use pocketmine\level\format\Chunk;

class Hleb extends PluginBase {

    private $snowActive = false; // Флаг для активации/деактивации изменения биомов

    public function onEnable() {
        $this->getLogger()->info("Snow плагин включен!");
    }

    public function onDisable() {
        $this->getLogger()->info("Snow плагин отключен!");
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args): bool {
        if ($command->getName() === "snow") {
            if (!$sender instanceof Player) {
                $sender->sendMessage("Эту команду можно использовать только в игре.");
                return false;
            }

            if (count($args) < 1) {
                #$sender->sendMessage("Использование: /snow <on|off>");
                return false;
            }

            switch (strtolower($args[0])) {
                case "on":
                    if (!$this->snowActive) {
                        $this->snowActive = true;
                        $sender->sendMessage("§7> §7(§f§lСнежок§7) §fБиомы всех загруженных чанков изменены на §3ICE_PLAINS§f.\n§f(чтоб включить снег /weather rain чтоб выключить /weather world clear)");
                        $this->changeLoadedChunksToIcePlains($sender->getLevel());
                        $this->setSnowing(true); // Включаем снег
                    } else {
                        $sender->sendMessage("§7> §7(§l§fСнежок§7) §fИзменение биомов уже §aактивно§f.");
                    }
                    break;
                case "off":
                    if ($this->snowActive) {
                        $this->snowActive = false;
                        $sender->sendMessage("§7> §7(§l§fСнежок§7) §fИзменение биомов §cотключено§f.");
                        $this->setSnowing(false); // Выключаем снег
                    } else {
                        $sender->sendMessage("§7> §7(§l§fСнежок§7) §fИзменение биомов уже §cотключено§f.");
                    }
                    break;
                default:
                    #$sender->sendMessage("Использование: /snow <on|off>");
                    break;
            }

            return true;
        }

        return false;
    }

    // Функция для изменения биомов всех загруженных чанков на ICE_PLAINS
    private function changeLoadedChunksToIcePlains(Level $level) {
        foreach ($level->getChunks() as $chunk) {
            $this->setChunkToIcePlains($chunk);
        }
    }

    // Функция для изменения биома одного чанка на ICE_PLAINS
    private function setChunkToIcePlains(Chunk $chunk) {
        for ($x = 0; $x < 16; $x++) {
            for ($z = 0; $z < 16; $z++) {
                $chunk->setBiomeId($x, $z, Biome::ICE_PLAINS); // Устанавливаем биом ICE_PLAINS
            }
        }
    }

    // Функция для включения/выключения дождя и снега
    public function setSnowing(bool $enable) {
    $server = $this->getServer();
    $level = $server->getDefaultLevel(); // Получаем мир
    
    if ($enable) {
        #$server->dispatchCommand(new \pocketmine\command\ConsoleCommandSender(), "weather $level rain"); // Включаем дождь
        #
    } else {
        #$server->dispatchCommand(new \pocketmine\command\ConsoleCommandSender(), "weather $level clear"); // Останавливаем дождь
    }
}
}