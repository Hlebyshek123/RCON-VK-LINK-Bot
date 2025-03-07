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
    private $allowedAccess = ["1lvl", "2lvl", "3lvl", "4lvl", "5lvl"];

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
        if (strtolower($command->getName()) === "givevk") {
            if (!$sender instanceof ConsoleCommandSender) {
                $sender->sendMessage("§l§7[§l§c!§7§l] §l§7Эту §l§fкоманду §l§7можно §l§eвыполнять §l§7только из §l§6консоли §l§7сервера.");
                return true;
            }

            if (count($args) < 3) {
                $sender->sendMessage("§9INFO: §rИспользование: /givevk [ник_игрока] [привилегия] [вк_доступ]");
                return true;
            }

            $username = strtolower($args[0]);
            $rank = $args[1];
            $access = strtolower($args[2]);

            if (!in_array($rank, $this->allowedRanks)) {
                $sender->sendMessage("§cERROR: §rнедопустимый ранг $rank. Допустимые ранги: " . implode(", ", $this->allowedRanks));
                return true;
            }
            
            if (!in_array($access, $this->allowedAccess)) {
                $sender->sendMessage(
                    "§cERROR: §rнедопустимый уровень доступа $access. \n Допустимые доступы:" . implode(", ", $this->allowedAccess));
                    return true;
            }

            // Check user in vk_links
            $stmt = $this->db->prepare("SELECT vk_id, link FROM vk_links WHERE LOWER(username) = LOWER(?)");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                $sender->sendMessage("§cERROR: §rИгрок с ником $username не найден в базе данных.");
                $this->sendVkGroupMessage("⚠️ | Выдача не произошла.\n Никнейм не найден в базе данных.\n Ник: $username\n Ранг: $rank\n ВК доступ: $access");
                return true;
            }

            $row = $result->fetch_assoc();
            if ($row['link'] !== "YES") {
                $this->sendVkGroupMessage("⚠️ | Выдача не произошла.\n Никнейм не привязан к ВК.\n Ник: $username\n Ранг: $rank\n ВК доступ: $access.");
                $sender->sendMessage("§cERROR: §rИгрок $username не привязан к ВК. Выдача не произошла.");
                return true;
            }

            // Check existing in vk_rcon
            $stmt = $this->db->prepare("SELECT nickname FROM vk_rcon WHERE LOWER(nickname) = LOWER(?)");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            
            if ($stmt->get_result()->num_rows > 0) {
                $sender->sendMessage("§cERROR: §rНик $username уже существует в таблице vk_rcon.");
                return true;
            }

            // Insert new record
            $stmt = $this->db->prepare("INSERT INTO vk_rcon (nickname, vk_id, rank) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $username, $row['vk_id'], $access);
            $stmt->execute();
            $stmt->close();

            // Dispatch command
            $this->getServer()->dispatchCommand(new ConsoleCommandSender(), "setgroup $username $rank");

            // Send messages
            $privilege = $this->getPrivilegeName($rank);
            $dostyp = $this->getAccessName($access);
            $this->sendVkMessage($row['vk_id'], "❤ | Спасибо за покупку!\n👑 | $username, вам была успешно выдана привилегия $privilege и $dostyp ВК консоли!\n".$this->getHelpMessage($access));
            $sender->sendMessage("§aSUCCESS: §rИгроку $username успешно выдана привилегия $privilege и вк доступ $access.");
            
            return true;
        }
        if (strtolower($command->getName()) === "vkcode") {
            if (!$sender instanceof \pocketmine\Player) {
                $sender->sendMessage("§cERROR: §rЭту команду может использовать только игрок.");
                return true;
            }

            $playerName = strtolower($sender->getName());
            $this->handleVKCodeCommand($playerName, $sender);
            return true;
        }
        if (strtolower($command->getName()) === "set-vk") {
        if (!$sender instanceof ConsoleCommandSender) {
            $sender->sendMessage("§l§7[§l§c!§7§l] §l§7Эту §l§fкоманду §l§7можно §l§eвыполнять §l§7только из §l§6консоли §l§7сервера.");
            return true;
        }

        if (count($args) < 2) {
            $sender->sendMessage("§9INFO: §rИспользование: /set-vk <никнейм> <Вк_айди>");
            return true;
        }

        $username = strtolower($args[0]);
        $vk_id = $args[1];

        // Проверка существующих записей
        $checkStmt = $this->db->prepare("SELECT * FROM vk_links WHERE username = ?");
        $checkStmt->bind_param("s", $username);
        $checkStmt->execute();
        
        if ($checkStmt->get_result()->num_rows > 0) {
            $sender->sendMessage("§cERROR: §rИгрок с ником $username уже существует!");
            return true;
        }

        // Вставка новой записи
        $insertStmt = $this->db->prepare("INSERT INTO vk_links (username, vk_id, vk_code, link) VALUES (?, ?, NULL, 'YES')");
        $insertStmt->bind_param("ss", $username, $vk_id);
        
        if ($insertStmt->execute()) {
            $sender->sendMessage("§aSUCCESS: §rИгрок $username с ВК ID $vk_id успешно привязан к боту!");
            $insertStmt->close();
            $checkStmt->close();
        } else {
            $sender->sendMessage("§cERROR: §rпри добавления в базу: §e" . $insertStmt->error);
        }
        
        return true;
    }
        return false;
    }

private function handleVKCodeCommand(string $playerName, \pocketmine\Player $player): void {
    $stmt = $this->db->prepare("SELECT vk_code, link FROM vk_links WHERE username = LOWER(?)");
    $stmt->bind_param("s", $playerName);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        // Если аккаунт уже привязан
        if ($row['link'] === 'YES') {
            $player->sendMessage("§f§l> §l§6Auth §l§f• §l§7Этот аккаунт уже привязан к ВК.");
            $player->sendMessage("§f§l> §l§6Auth §l§f• §l§7Чтобы привязать к новому ВК, зайдите в бота и напишите §f/отвязать");
        } 
        // Если есть код, но привязка не завершена
        else {
            $existingCode = $row['vk_code'];
            $player->sendMessage("§f§l> §l§6Auth §l§f• §l§7Ваш §e§lкод §7§lпривязки §l§fВ§l§bK:§l§f " . $existingCode);
            $player->sendMessage($this->getInstructionMessage());
        }
    } else {
        // Генерация нового кода если записей нет
        $code = $this->generateCode();
        $player->sendMessage("§f§l> §l§6Auth §f§l• §7§lВаш §e§lкод §7§lпривязки §l§fВ§l§bK:§l§f " . $code);
        $player->sendMessage($this->getInstructionMessage());
        $this->saveCode($playerName, $code);
    }

    if (isset($stmt)) {
        $stmt->close();
    }
}

    private function generateCode(): string {
        $characters = 'ABCDEFGHKMNPQRSTUVWXYZabcdefghkmnpqrstuvwxyz123456789';
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
        return "§f§l> §l§6Auth §f§l• §f§lИнструкция §7§l по привязке §l§aаккаунта §l§7к §l§fВ§l§bK §l§7сообществу! \n §l§f1. §l§7Найти в §l§fВ§l§bK §l§7сообщество §l§a@hleb_craft\n §f§l2. §l§7Написать боту §f§l/привязать [ник] [код] §l§7и всё ваш аккаунт будет привязан к §l§fВ§l§bК \n\n §8§l(§l§7P.S §l§fЕсли возникнут §l§cпроблемы §l§6В§l§fК §l§f@hleb_craft §l§8)";
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
    
    private function getAccessName (string $access): string {
        $accesses = [
            "1lvl" => "1 уровень",
            "2lvl" => "2 уровень",
            "3lvl" => "3 уровень",
            "4lvl" => "4 уровень",
            "5lvl" => "5 уровень"];
            return $accesses[$access] ?? "";
    }

    private function getHelpMessage(string $access): string {
        return in_array($access, ["2lvl", "3lvl", "4lvl", "5lvl"]) 
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