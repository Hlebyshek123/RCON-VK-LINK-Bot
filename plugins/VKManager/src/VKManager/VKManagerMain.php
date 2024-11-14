<?php

namespace VKManager;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\Player;
use SQLite3;

class VKManagerMain extends PluginBase implements Listener {

    private $db;
    private $blacklistCommands = ["ban-list", "ban", "kick", "pardon", "mute", "unmute", "addmoney"]; // Массив запрещённых команд для забаненного донатера
    private $allowedRanks = ["Console", "GlConsole", "Developer", "Administrator", "SeniorAdmin"]; // Разрешённые ранги

    public function onEnable(): void {
        $dbPath = '/root/vklink/vk_bot.db'; // Путь к базе данных
        $this->db = new SQLite3($dbPath);

        if (!$this->db) {
            $this->getLogger()->error("Не удалось подключиться к базе данных SQLite.");
        } else {
            $this->getLogger()->info("Подключение к базе данных SQLite установлено.");
        }

        // Регистрируем событие
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onDisable(): void {
        if ($this->db) {
            $this->db->close();
        }
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args): bool {
    if ($command->getName() === "givevk") {
        if (!($sender instanceof ConsoleCommandSender)) {
            $sender->sendMessage("§l§7[§l§c!§7§l] §l§7Эту §l§fкоманду §l§7можно §l§eвыполнять §l§7только из §l§6консоли §l§7сервера.");
            return true;
        }

        if (count($args) < 2) {
            $sender->sendMessage("Использование: /givevk [ник_игрока] [ранг]");
            return true;
        }

        $username = strtolower($args[0]);
        $rank = $args[1];

        // Массив допустимых рангов
        $allowedRanks = ["Console", "GlConsole", "Developer", "Administrator", "SeniorAdmin"];

        // Проверка, что введенный ранг допустим
        if (!in_array($rank, $allowedRanks)) {
            $sender->sendMessage("Ошибка: недопустимый ранг $rank. Допустимые ранги: " . implode(", ", $allowedRanks));
            return true;
        }

        // Проверка привязки пользователя к ВК
        $query = $this->db->prepare("SELECT vk_id, link FROM vk_links WHERE username = :username");
        $query->bindValue(":username", $username, SQLITE3_TEXT);
        $result = $query->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$result) {
            $sender->sendMessage("Игрок с ником $username не найден в базе данных.");
            
            // Отправка сообщения владельцу сообщества об ошибке
            $this->sendVkGroupMessage("⚠️ | Игрок с ником $username не найден в базе данных.\n Выдача не произошла");
            
            return true;
        }

        $vk_id = $result['vk_id'];
        $link = $result['link'];

        if ($link !== "YES") {
            $this->sendVkGroupMessage("⚠️ | Игрок $username не привязан к ВК. Выдача не произошла.");
            $sender->sendMessage("Игрок $username не привязан к ВК. Выдача не произошла.");
            return true;
        }

        // Проверка на существование ника в таблице vk_rcon
        $query = $this->db->prepare("SELECT nickname FROM vk_rcon WHERE nickname = :nickname");
        $query->bindValue(":nickname", $username, SQLITE3_TEXT);
        $existing = $query->execute()->fetchArray(SQLITE3_ASSOC);

        if ($existing) {
            $sender->sendMessage("Ошибка: Ник $username уже существует в таблице vk_rcon.");
            return true;
        }

        // Добавляем игрока в таблицу vk_rcon
        $query = $this->db->prepare("INSERT INTO vk_rcon (nickname, vk_id, rank) VALUES (:nickname, :vk_id, :rank)");
        $query->bindValue(":nickname", $username, SQLITE3_TEXT);
        $query->bindValue(":vk_id", $vk_id, SQLITE3_TEXT);
        $query->bindValue(":rank", $rank, SQLITE3_TEXT);
        $query->execute();

        // Выдача привилегии через команду консоли
        $this->getServer()->dispatchCommand(new ConsoleCommandSender(), "setgroup $username $rank");

        // Определяем привилегию для сообщения
        $privilege = "";
        switch ($rank) {
            case "SeniorAdmin":
                $privilege = "Главный Администратор";
                $help_msg = "📰 | Помощь\n💠 | Помощь админ";
                break;
            case "Administrator":
                $privilege = "Администратор";
                $help_msg = "📰 | Помощь\n💠 | Помощь админ";
                break;
            case "Developer":
                $privilege = "Разработчик";
                $help_msg = "📰 | Помощь\n💠 | Помощь админ";
                break;
            case "GlConsole":
                $privilege = "Главная Консоль";
                $help_msg = "📰 | Помощь\n💠 | Помощь админ";
                break;
            case "Console":
                $privilege = "Консоль";
                $help_msg = "📰 | Помощь\n💠 | Помощь админ";
                break;
        }

        // Отправка сообщения пользователю ВК о выдаче привилегии
        $this->sendVkMessage($vk_id, "❤ | Спасибо за покупку!\n👑 | $username, вам была успешно выдана привилегия $privilege и ранг $rank!\n$help_msg");

        $sender->sendMessage("Игроку $username успешно выдан ранг $rank и привилегия $privilege.");
        return true;
    }

    return false;
}

    public function onPlayerCommandPreprocess(PlayerCommandPreprocessEvent $event): void {
        $player = $event->getPlayer();
        $message = $event->getMessage();
        
        // Проверяем, начинается ли сообщение с "/"
        if (substr($message, 0, 1) === "/") {
            // Извлекаем команду из сообщения
            $command = strtolower(explode(" ", substr($message, 1))[0]); // Убираем "/" и получаем команду
            
            // Получаем имя игрока и конвертируем его в нижний регистр
            $username = strtolower($player->getName());
            
            // Проверка статуса бана игрока
            $query = $this->db->prepare("SELECT banned, ban_reason FROM vk_rcon WHERE LOWER(nickname) = :nickname");
            $query->bindValue(":nickname", $username, SQLITE3_TEXT);
            $result = $query->execute()->fetchArray(SQLITE3_ASSOC);

            // Если игрок найден и забанен
            if ($result && $result['banned'] === "YES") {
                // Проверяем, если команда находится в чёрном списке
                if (in_array($command, $this->blacklistCommands)) {
                    // Отменяем выполнение команды
                    $event->setCancelled(true);
                    $banReason = $result['ban_reason'];
                    $player->sendMessage("§l§f> §l§7[§l§c!§7§l] §l§7Ваши §l§eдонатерские возможности §l§7были §l§cограничены из-за нарушения §l§fправил сервера§l§7. §l§8| §l§7причина: §f{$banReason}§l§7");
                    return;
                }
            }
        }
    }

    private function sendVkMessage($vk_id, $message) {
        $accessToken = "vk1.a.MiuRvqwfVo3VEMf_rrbAGZjdKqZL9sHu4YZbK_ok9cX0W-HVCnRfhmP9umZDtWbvehcN4MnGFSxCr_rLeG2v03TUQYZEBbLkx4PgGsDS5Jzek8WKVKT4K3rqCykIqxybZWe5v9Tq88BpZ51abUmHrZnU-K3PSkIJtO0dKzcwwxTaDLfniYjj4tzU7RS8_BaT86onTxqMH1uLQOfpMblivg"; // Укажите ваш токен доступа
        $randomId = rand(100000, 999999); // Генерация случайного числа для random_id

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
        $accessToken = "vk1.a.MiuRvqwfVo3VEMf_rrbAGZjdKqZL9sHu4YZbK_ok9cX0W-HVCnRfhmP9umZDtWbvehcN4MnGFSxCr_rLeG2v03TUQYZEBbLkx4PgGsDS5Jzek8WKVKT4K3rqCykIqxybZWe5v9Tq88BpZ51abUmHrZnU-K3PSkIJtO0dKzcwwxTaDLfniYjj4tzU7RS8_BaT86onTxqMH1uLQOfpMblivg"; // Укажите ваш токен доступа
        $ownerId = "789886979"; // Замените на фактический ID администратора или владельца сообщества

        $randomId = rand(100000, 999999); // Генерация случайного числа для random_id

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