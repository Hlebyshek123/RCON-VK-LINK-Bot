<?php

namespace twofavk;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\Player;
use SQLite3;

class twofa extends PluginBase implements Listener {

    private $db;

    public function onEnable() {
        // Подключаемся к базе данных SQLite
        $this->db = new SQLite3('/root/vklink/vk_bot.db');

        // Регистрируем события
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onPlayerPreLogin(PlayerPreLoginEvent $event) {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        $playerIp = $player->getAddress(); // Получаем текущий IP игрока

        // Проверяем, включен ли 2FA для игрока в таблице settings
        $stmtSettings = $this->db->prepare("SELECT twofa_active FROM settings WHERE nickname = :username");
        $stmtSettings->bindValue(':username', strtolower($playerName), SQLITE3_TEXT);
        $resultSettings = $stmtSettings->execute();

        if ($resultSettings) {
            $settingsData = $resultSettings->fetchArray(SQLITE3_ASSOC);
            $resultSettings->finalize(); // Закрываем результат запроса
            
            // Если active_twofa = 'NO', пропускаем проверки 2FA
            if ($settingsData && $settingsData['twofa_active'] === 'NO') {
                $this->getLogger()->info("2FA отключен для пользователя $playerName.");
                return;
            }
        }

        // Проверяем статус link и process
        $stmt = $this->db->prepare("SELECT vk_id, link, process, last_ip FROM vk_links WHERE username = :username");
        $stmt->bindValue(':username', strtolower($playerName), SQLITE3_TEXT);
        $result = $stmt->execute();

        if ($result) {
            $data = $result->fetchArray(SQLITE3_ASSOC);
            $result->finalize();  // Закрываем результат запроса

            if ($data) {
                if ($data['link'] === 'NO') {
                    $this->getLogger()->info("Пользователь $playerName не привязан к ВК. 2FA не запускается.");
                    return; // Прерываем выполнение, если link = NO
                }

                // Если IP совпадает, разрешаем вход без дальнейших проверок
                if ($data['last_ip'] === $playerIp) {
                    return;
                }

                // Если IP не совпадает и процесс в состоянии ожидания подтверждения
                if ($data['process'] === 'pending') {
                    // Кикаем игрока и отправляем сообщение
                    $event->setKickMessage("\n§l§7[§f2§cFA§7] §l§7Ваш §l§fIP §l§cне сходится §l§7с тем, с которого вы заходили ранее.");
                    $event->setCancelled(true);

                    // Обновляем статус на 'pending' в базе данных
                    $this->updateProcess($playerName, 'pending');

                    // Отправляем уведомление в ВК
                    $vkId = $data['vk_id'];
                    $this->sendVKConfirmationRequest($playerName, $vkId, $playerIp);

                } elseif ($data['process'] === 'denied') {
                    // Блокируем игрока, добавляя его в бан-лист по имени
                    $this->getServer()->getNameBans()->addBan($playerName, "Вход с нового IP отклонен.", null, "2FA");
                    $event->setKickMessage("\n§l§7[§f2§cFA§l§7] §l§7Вход с нового §l§fIP §l§7был отклонен. Ваш аккаунт заблокирован.");
                    $event->setCancelled(true);

                } elseif ($data['process'] === 'approved') {
                    // Разблокировка игрока
                    $this->getServer()->getNameBans()->remove($playerName);

                    // Обновляем IP-адрес игрока
                    $this->updateLastIp($playerName, $playerIp);

                    // Устанавливаем поле process в pending после успешного подтверждения
                    $this->updateProcess($playerName, 'pending');
                }
            }
        }
    }

    private function updateLastIp(string $username, string $ip): void {
        $stmt = $this->db->prepare("UPDATE vk_links SET last_ip = :last_ip WHERE username = :username");
        $stmt->bindValue(':last_ip', $ip, SQLITE3_TEXT);
        $stmt->bindValue(':username', strtolower($username), SQLITE3_TEXT);
        $stmt->execute();
    }

    private function updateProcess(string $username, string $process): void {
        $stmt = $this->db->prepare("UPDATE vk_links SET process = :process WHERE username = :username");
        $stmt->bindValue(':process', $process, SQLITE3_TEXT);
        $stmt->bindValue(':username', strtolower($username), SQLITE3_TEXT);
        $stmt->execute();
    }

    private function sendVKConfirmationRequest(string $playerName, int $vkId, string $playerIp): void {
        $message = "🔑 | $playerName, обнаружен вход с нового IP ($playerIp).\nВыбери действие (/акк):\n1⃣ принять - подтвердить вход.\n2⃣ отклонить - отклонить вход и блокнуть акк.\n3⃣ разбан - если вход отклонен это разблочит акк.\n\n⚠️ | Если это не вы заходите в акк то отклони вход и все.";

        // Формируем URL для отправки сообщения
        $url = "https://api.vk.com/method/messages.send?" . http_build_query([
            'user_id' => $vkId,
            'message' => $message,
            'random_id' => rand(100000, 999999),
            'access_token' => 'vk1.a.PERsOvAjIp8B-dhoMNQJZFN57n7vRde2MRGwUYfM1TFSrlIhc0GpAX7OnCPE7FRMK0-2w18h7xFWWR5ACAYOEGCKiyLZIt8URVoryemQec_eGxrZM85O3YvYfNckAABBzRUMU6ag_VHMTAWiu6gRO8lAAD3wVvEmyeQg4H4yCXm4y1GdBfE1Agi3Ttc7HUw21jy5lHoJg',
            'v' => '5.131'
        ]);

        // Инициализируем cURL сессию
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $this->getLogger()->error("Ошибка при отправке сообщения в ВК: " . curl_error($ch));
        } else {
            $this->getLogger()->info("Запрос на подтверждение входа отправлен пользователю VK ID: $vkId");
        }

        curl_close($ch);
    }
}