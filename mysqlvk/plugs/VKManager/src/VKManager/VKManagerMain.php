<?php

namespace VKManager;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\event\Listener;
use pocketmine\utils\Config;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\Player;
use mysqli;

class VKManagerMain extends PluginBase implements Listener {

    private $db;
    private $banCache = [];
    private $blacklistCommands = ["ban-list", "ban", "kick", "pardon", "mute", "unmute", "addmoney"];
    private $allowedRanks = ["Console", "GlConsole", "Developer", "Administrator", "SeniorAdmin"];

    public function onEnable(): void {
        $this->saveResource("config.yml");
        $config = new Config($this->getDataFolder() . "config.yml", Config::YAML);

        // MySQL connection
        $this->db = new mysqli(
            $config->get("host", "localhost"),
            $config->get("user", "root"),
            $config->get("password", ""),
            $config->get("database", "vk_bot"),
            $config->get("port", 3306)
        );

        if ($this->db->connect_error) {
            $this->getLogger()->error("MySQL connection failed: " . $this->db->connect_error);
            return;
        }

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onDisable(): void {
        if ($this->db) {
            $this->db->close();
        }
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args): bool {
        if ($command->getName() === "givevk") {
            if (!$sender instanceof ConsoleCommandSender) {
                $sender->sendMessage("§l§7[§l§c!§7§l] §l§7Эту §l§fкоманду §l§7можно §l§eвыполнять §l§7только из §l§6консоли §l§7сервера.");
                return true;
            }

            if (count($args) < 2) {
                $sender->sendMessage("Использование: /givevk [ник_игрока] [ранг]");
                return true;
            }

            $username = strtolower($args[0]);
            $rank = $args[1];

            if (!in_array($rank, $this->allowedRanks)) {
                $sender->sendMessage("Ошибка: недопустимый ранг $rank. Допустимые ранги: " . implode(", ", $this->allowedRanks));
                return true;
            }

            // Check user in vk_links
            $stmt = $this->db->prepare("SELECT vk_id, link FROM vk_links WHERE LOWER(username) = LOWER(?)");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                $sender->sendMessage("Игрок с ником $username не найден в базе данных.");
                $this->sendVkGroupMessage("⚠️ | Выдача не произошла.\n Никнейм не найден в базе данных.\n Ник: $username\n Доступ: $rank");
                return true;
            }

            $row = $result->fetch_assoc();
            if ($row['link'] !== "YES") {
                $this->sendVkGroupMessage("⚠️ | Выдача не произошла.\n Никнейм не привязан к ВК.\n Ник: $username\n Доступ: $rank.");
                $sender->sendMessage("Игрок $username не привязан к ВК. Выдача не произошла.");
                return true;
            }

            // Check existing in vk_rcon
            $stmt = $this->db->prepare("SELECT nickname FROM vk_rcon WHERE LOWER(nickname) = LOWER(?)");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            
            if ($stmt->get_result()->num_rows > 0) {
                $sender->sendMessage("Ошибка: Ник $username уже существует в таблице vk_rcon.");
                return true;
            }

            // Insert new record
            $stmt = $this->db->prepare("INSERT INTO vk_rcon (nickname, vk_id, rank) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $username, $row['vk_id'], $rank);
            $stmt->execute();

            // Dispatch command
            $this->getServer()->dispatchCommand(new ConsoleCommandSender(), "setgroup $username $rank");

            // Send messages
            $privilege = $this->getPrivilegeName($rank);
            $this->sendVkMessage($row['vk_id'], "❤ | Спасибо за покупку!\n👑 | $username, вам была успешно выдана привилегия $privilege и ранг $rank!\n".$this->getHelpMessage($rank));
            $sender->sendMessage("Игроку $username успешно выдан ранг $rank и привилегия $privilege.");
            
            return true;
        }
        if (strtolower($command->getName()) === "vkcode") {
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

private function handleVKCodeCommand(string $playerName, \pocketmine\Player $player): void {
        $stmt = $this->db->prepare("SELECT vk_code FROM vk_links WHERE LOWER(username) = LOWER(?)");
        $stmt->bind_param("s", $playerName);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $existingCode = $row['vk_code'];
            $player->sendMessage("§f§l> §l§6Привязка §l§f• §l§7Ваш §e§lкод §7§lпривязки §l§fВ§l§bK:§l§f " . $existingCode);
            $player->sendMessage($this->getInstructionMessage());
        } else {
            $code = $this->generateCode();
            $player->sendMessage("§f§l> §l§6Привязка §f§l• §7§lВаш §e§lкод §7§lпривязки §l§fВ§l§bK:§l§f " . $code);
            $player->sendMessage($this->getInstructionMessage());
            $this->saveCode($playerName, $code);
        }

        if (isset($stmt)) {
            $stmt->close();
        }
    }

    private function generateCode(): string {
        $characters = 'ABCDEFGHKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz123456789';
        $code = '';
        $length = strlen($characters);
        for ($i = 0; $i < 8; $i++) {
            $code .= $characters[mt_rand(0, $length - 1)];
        }
        return $code;
    }

    private function saveCode(string $playerName, string $code): void {
        $stmt = $this->db->prepare("INSERT INTO vk_links (username, vk_code) VALUES (LOWER (?), ?)");
        $stmt->bind_param("ss", $playerName, $code);
        $stmt->execute();
        $stmt->close();
    }

    private function getInstructionMessage(): string {
        return "§f§l> §l§6Привязка §f§l• §f§lИнструкция §7§l по привязке §l§aаккаунта §l§7к §l§fВ§l§bK §l§7сообществу! \n §l§f1. §l§7Найти в §l§fВ§l§bK §l§7сообщество §l§a@hleb_craft\n §f§l2. §l§7Написать боту §f§l/привязать [ник] [код] §l§7и всё ваш аккаунт будет привязан к §l§fВ§l§bК \n\n §8§l(§l§7P.S §l§fЕсли возникнут §l§cпроблемы §l§6В§l§fК §l§f@hleb_craft §l§8)";
    }

    public function onPlayerCommandPreprocess(PlayerCommandPreprocessEvent $event): void {
        $player = $event->getPlayer();
        $username = strtolower($player->getName());
        $message = $event->getMessage();

        if (!isset($this->banCache[$username])) {
            $stmt = $this->db->prepare("SELECT banned, ban_reason FROM vk_rcon WHERE LOWER(nickname) = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $this->banCache[$username] = $result->num_rows > 0 ? $result->fetch_assoc() : null;
        }

        if ($this->banCache[$username] && $this->banCache[$username]['banned'] === "YES") {
            $command = strtolower(explode(" ", substr($message, 1))[0]);
            
            if (in_array($command, $this->blacklistCommands)) {
                $event->setCancelled(true);
                $player->sendMessage("§l§f> §l§7[§l§c!§7§l] §l§7Ваши §l§eдонатерские возможности §l§7были §l§cограничены из-за нарушения §l§fправил сервера§l§7. §l§8| §l§7причина: §f{$this->banCache[$username]['ban_reason']}§l§7");
            }
        }
    }

    private function getPrivilegeName(string $rank): string {
        $privileges = [
            "SeniorAdmin" => "Главный Администратор",
            "Administrator" => "Администратор",
            "Developer" => "Разработчик",
            "GlConsole" => "Главная Консоль",
            "Console" => "Консоль"
        ];
        return $privileges[$rank] ?? "";
    }

    private function getHelpMessage(string $rank): string {
        return in_array($rank, ["SeniorAdmin", "Administrator", "Developer"]) 
            ? "📰 | Помощь\n💠 | Помощь админ" 
            : "📰 | Помощь";
    }

    private function sendVkMessage($vk_id, $message) {
        $accessToken = "vk1.a.bCYPtQuE2YA3sP-9amAP1Emkfbkxf9mzu013-SI8JVUw8yPpT4CAYaQ7rJlooznBCvXUI5CEXBeEIkCxjwDxWHchGZMa97lLmSH5yhvjbYf8g_dOBBKErFvBMMi1wzIYIdezn2d_eokYVYgyIW6Svjr_6qAa996AXJrlYj7zufEaVYr2_0rRlFdSwcs9qSWD4y93hDQcobygLFr1B6Wv1w"; // Укажите ваш токен доступа
        $randomId = rand(100000, 1e6); // Генерация случайного числа для random_id

        $requestParams = [
            'user_id' => $vk_id,
            'message' => $message,
            'random_id' => $randomId,
            'access_token' => $accessToken,
            'v' => '5.131'
        ];

        $url = 'https://api.vk.com/method/messages.send';

        // Инициализация cURL
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($requestParams));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Включить проверку SSL сертификатов
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); // Проверка хоста

        $response = curl_exec($ch);

        if ($response === false) {
            $this->getLogger()->error('Ошибка cURL при отправке сообщения пользователю: ' . curl_error($ch));
        } else {
            $this->getLogger()->info('Ответ ВК (пользователю): ' . $response);
        }

        curl_close($ch);
    }

    private function sendVkGroupMessage($message) {
        $accessToken = "vk1.a.bCYPtQuE2YA3sP-9amAP1Emkfbkxf9mzu013-SI8JVUw8yPpT4CAYaQ7rJlooznBCvXUI5CEXBeEIkCxjwDxWHchGZMa97lLmSH5yhvjbYf8g_dOBBKErFvBMMi1wzIYIdezn2d_eokYVYgyIW6Svjr_6qAa996AXJrlYj7zufEaVYr2_0rRlFdSwcs9qSWD4y93hDQcobygLFr1B6Wv1w"; // Укажите ваш токен доступа
        $ownerId = "789886979"; // Замените на фактический ID администратора или владельца сообщества

        $randomId = rand(100000, 1e6); // Генерация случайного числа для random_id

        $requestParams = [
            'user_id' => $ownerId,  // Отправляем сообщение владельцу сообщества
            'message' => $message,
            'random_id' => $randomId,
            'access_token' => $accessToken,
            'v' => '5.131'
        ];

        $url = 'https://api.vk.com/method/messages.send';

        // Инициализация cURL
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($requestParams));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Включить проверку SSL сертификатов
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); // Проверка хоста

        $response = curl_exec($ch);

        if ($response === false) {
            $this->getLogger()->error('Ошибка cURL при отправке сообщения владельцу сообщества: ' . curl_error($ch));
        } else {
            $this->getLogger()->info('Ответ ВК (владелецу): ' . $response);
        }

        curl_close($ch);
    }
}