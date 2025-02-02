<?php

namespace vkNotifications;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\plugin\PluginBase;
use SQLite3;

class vkNotification extends PluginBase implements Listener {

    private $db;

    public function onEnable() {
        $this->db = new SQLite3('/root/vklink/vk_bot.db');
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onJoin(PlayerJoinEvent $event) {
        $player = $event->getPlayer();
        $username = strtolower($player->getName());
        $time = date("H:i МСК d.m.y");

        // Проверяем привязку и настройки
        $stmt = $this->db->prepare("
            SELECT vk_links.vk_id, settings.join_notifications 
            FROM vk_links 
            LEFT JOIN settings ON vk_links.username = settings.nickname 
            WHERE vk_links.username = :username 
            AND vk_links.link = 'YES'
        ");
        
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $result = $stmt->execute();

        if($result) {
            $data = $result->fetchArray(SQLITE3_ASSOC);
            if($data && $data['join_notifications'] === 'YES') {
                $this->sendVKMessage(
                    $data['vk_id'],
                    "➕ | {$player->getName()}, зашёл на сервер в $time.\n\n".
                    "⚠️ | Если у вас включена защита и это не вы смените пароль!"
                );
            }
        }
    }

    private function sendVKMessage(int $vkId, string $message) {
        $url = "https://api.vk.com/method/messages.send?" . http_build_query([
            'user_id' => $vkId,
            'message' => $message,
            'random_id' => rand(100000, 999999),
            'access_token' => 'vk1.a.3YvkZkZ_5ZsfGMpyPu4njwwg2N-F978I8DiiasbN5hiDbnCbEQrsyTNd_5Kchs2VZkQOjZ81kSil2pFJSXjDyg7Qw1vhOO8n3oMKP37Q9tp9QLLm2KXiSficD0To2j21CfKl6aVUUiVYphDcOgOAGnO9cqUr5-WHmA6_t2ZLRnj6mmcBeNhg-yIjvdgkgeP_qnXDw',
            'v' => '5.131'
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    }
}