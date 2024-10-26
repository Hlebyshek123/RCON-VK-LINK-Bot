<?php

namespace VKCode;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\event\Listener;
use SQLite3;

class VKCodePlugin extends PluginBase implements Listener {

    /** @var SQLite3 */
    private $db;

    public function onEnable() {
        // Подключаемся к уже существующей базе данных
        $this->db = new SQLite3('/root/vklink/vk_bot.db');

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info("Вк код работает");
        $this->getLogger()->info("РАФАЭЛЬ SUCK MY DICK BABY");
    }

    // Реализация onCommand для обработки команды /vkcode
    public function onCommand(CommandSender $sender, Command $command, $label, array $args): bool {
        if (strtolower($command->getName()) === "vkcode") {
            // Проверяем, является ли отправитель игроком
            if (!$sender instanceof \pocketmine\Player) {
                $sender->sendMessage("Эту команду может использовать только игрок.");
                return true;
            }

            $playerName = $sender->getName();
            $this->handleVKCodeCommand($playerName, $sender);
            return true;
        }

        return false;
    }

    public function handleVKCodeCommand(string $playerName, \pocketmine\Player $player): void {
        // Проверяем, существует ли уже код подтверждения для игрока
        $stmt = $this->db->prepare("SELECT vk_code FROM vk_links WHERE username = :username");
        $stmt->bindValue(':username', strtolower($playerName), SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row) {
            // Если код уже существует, выводим его повторно
            $existingCode = $row['vk_code'];
            $player->sendMessage("§f§l> §l§bПривязка §l§f• §l§7Ваш §e§lкод §7§lпривязки §l§fВ§l§bK:§l§f " . $existingCode);
            $player->sendMessage("§f§l> §l§bПривязка §f§l• §f§lИнструкция §7§l по привязке §l§eаккаунта §l§7к §l§fВ§l§bK §l§7боту! \n §l§f1. §l§7Найти в §l§fВ§l§bK §l§7бота §l§a@fallcraft_pe \n §f§l2. §l§7Написать боту §f§l/привязать [ник] [код] §l§7и всё ваш аккаунт будет привязан к §l§fВ§l§bК \n\n §8§l(§l§7P.S §l§fЕсли возникнут §l§cпроблемы §l§aВ§l§fК §l§f@fallcraft_pe §l§8)");
        } else {
            // Если кода нет, создаем новый и сохраняем его
            $code = $this->generateCode();
            $player->sendMessage("§f§l> §l§bПривязка §f§l• §7§lВаш §e§lкод §7§lпривязки §l§fВ§l§bK:§l§f " . $code);
            $player->sendMessage("§f§l> §l§bПривязка §f§l• §f§lИнструкция §7§l по привязке §l§eаккаунта §l§7к §l§fВ§l§bK §l§7боту! \n §l§f1. §l§7Найти в §l§fВ§l§bK §l§7бота §l§a@fallcraft_pe \n §f§l2. §l§7Написать боту §f§l/привязать [ник] [код] §l§7и все ваш аккаунт будет привязан к §l§fВ§l§bК \n\n §8§l(§l§7P.S §l§fЕсли возникнут §l§cпроблемы §l§6В§l§fК §l§f@fallcraft_pe §l§8)");

            // Сохраняем код в базе данных
            $this->saveCode($playerName, $code);
        }
    }

    public function generateCode(): string {
        $characters = 'ABCDEFGHIJKLMNPQRSTUVWXYZabcdefghijklmnpqrstuvwxyz123456789';
        $code = '';
        $length = strlen($characters);
        for ($i = 0; $i < 8; $i++) {
            $code .= $characters[mt_rand(0, $length - 1)];
        }
        return $code;
    }

    public function saveCode(string $playerName, string $code): void {
        $stmt = $this->db->prepare("INSERT INTO vk_links (username, vk_code) VALUES (:username, :vk_code)");
        $stmt->bindValue(':username', strtolower($playerName), SQLITE3_TEXT);
        $stmt->bindValue(':vk_code', $code, SQLITE3_TEXT);
        $stmt->execute();
    }

    public function onDisable(): void {
        if ($this->db) {
            $this->db->close();
        }
    }
}