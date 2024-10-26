<?php
// ENCODING UTF-8 
namespace Bans;

use pocketmine\Player;
use pocketmine\Server;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\CallbackTask;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\player\PlayerChatEvent;

use pocketmine\utils\Config;
use pocketmine\level\sound\FizzSound;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\utils\TextFormat;

class Main extends PluginBase implements Listener
{
    private $config;
    private $banned;
    private $muted;
    private $freeze = array();
    private $db;

    public function onEnable()
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        
        $this->initDatabase();
        $this->getLogger()->info("§e===================\n§6Hleb§fBans §aВключен\n§fБураа няяя\n§e===================");
        $this->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask(array(
            $this,
            "cronTask"
        )), 300);
        @mkdir($this->getDataFolder());
        @mkdir($this->getDataFolder() . "data/");
        if (!file_exists($this->getDataFolder() . "config.yml"))
            file_put_contents($this->getDataFolder() . "config.yml", $this->getResource("config.yml"));
        $this->muted    = new Config($this->getDataFolder() . "data/muted.yml", Config::YAML);
        $this->config   = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->banned   = new Config($this->getDataFolder() . "data/banned.yml", Config::YAML);
        $this->bannedIp = new Config($this->getDataFolder() . "data/banned-ip.yml", Config::YAML);
        $this->logsprefix   = $this->config->get("logs-prefix");
        $this->prefix   = $this->config->get("chat-prefix");
        $this->shop   = $this->config->get("srv-shop");
    }

    private function initDatabase(){
        $dbPath = "/root/VDS/grif/sv/plugins/HlebBans_src/vk_id.db";
        $this->db = new \SQLite3($dbPath);
        $this->db->exec("CREATE TABLE IF NOT EXISTS users (username TEXT PRIMARY KEY, vk_id TEXT)");
    }
    
    public function getVkIdByUsername($username){
        $stmt = $this->db->prepare("SELECT vk_id FROM users WHERE username = :username");
        $stmt->bindValue(':username', strtolower($username), SQLITE3_TEXT);
        $result = $stmt->execute();

        if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            return $row['vk_id'];
    #    } else {
   #         ebal '0'; // vk_id не найден
        }
    }
    
    public function onJoin(PlayerPreLoginEvent $e)
    {
        $name = strtolower($e->getPlayer()->getName());
        $ip   = $e->getPlayer()->getAddress();
        if ($this->banned->exists($name)) {
			if ($e->getPlayer()->hasPermission("hleb.immunity")) {
			return;
			}
            $ban      = $this->banned->get($name);
            $timeLeft = $this->parseTime($ban['time']);
            $e->setKickMessage("§fВы §bЗабанены! §fПричина: §c{$ban['reason']}\n§fВас забанил: §e{$ban['banby']}  §fБан истекает: §a{$timeLeft}\n§fЛоги: §9@{$this->logsprefix}");
            $e->setCancelled(true);
        } elseif ($this->bannedIp->exists($ip)) {
			if ($e->getPlayer()->hasPermission("hleb.immunity")) {
				return;
			}
            $ban      = $this->bannedIp->get($ip);
            $timeLeft = $this->parseTime($ban['time']);
            $e->setKickMessage("§fВы §bЗабанены! §fПричина: §c{$ban['reason']}\n§fВас забанил: §e{$ban['banby']}  §fБан истекает: §a{$timeLeft}\n§fЛоги: §9@{$this->logsprefix}");
            $e->setCancelled(true);
			
        }
    }

    /** @param string $message */
    private function url($url){
		$ch = curl_init($url); 
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); 
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
		$response = curl_exec($ch); 
		curl_close($ch); 
		return $response; 
	}
    public function send_post(string $msg){
    $msg = urlencode($msg);
    $topic_id = '525684'; // ID обсуждения
    $group_id = '2278604'; // ID вашей группы, без минуса
    $from_group = '1'; # от имени сообщества
    $token = 'vk1.a.lI1K8C96MToghQMWRfCSGQLXHXpAPg1iniMLQ-nNeC0nNHs8TKUOag87f05eUwRdq1HNSbipEZdOFs5MBHhBJTvI1owWrFhDuVRDtlzDnjQjeYCFlY9gvfAV4SQlc_cUD9_zbr7ucye5XaiTPiex3SrK__CQ_wViyjtUTKaqTfnw_dxU5Q-W0csezaIRDQ';
    $response = $this->url("https://api.vk.com/method/board.createComment?group_id={$group_id}&topic_id={$topic_id}&message={$msg}&from_group={$from_group}&v=5.131&access_token={$token}");
    return "Ответ на запрос: ".$response;
}

    public function onCommand(CommandSender $sender, Command $command, $label, array $args)
    {
        switch ($label) {
            case 'ban':
                if ($sender->hasPermission("hleb.ban")) {
        if (count($args) > 1 && preg_match("/^[0-9]+$/", $args[1])) {
            $pl = $this->getServer()->getPlayer($args[0]);
            if ($pl !== null) {
                if ($pl->hasPermission("hleb.immunity")) {
                    $sender->sendMessage($this->prefix . " §fВы не можете забанить игрока с данной группой");
                    return;
                }
            }
            if (!$sender->isOp() && ($args[1] == 0 || $args[1] > $this->config->get("maxban-time"))) {
                $sender->sendMessage($this->prefix . " §fНедопустимое время бана");
                break;
            }
            if ($sender Instanceof Player && strtolower($args[0]) == strtolower($sender->getName())) {
                $sender->sendMessage($this->prefix . " §fВы не можете забанить собственный аккаунт!");
                break;
            }
            if ($args[1] != 0)
                $args[1] = time() + $args[1] * 60;
            if ($sender Instanceof Player)
                $banby = $sender->getName();
            else
                $banby = "Console";
            $timeLeft = $this->parseTime($args[1]);

            if ($this->banned->exists(strtolower($args[0]))) {
                $sender->sendMessage($this->prefix . " §fИгрок уже находится в §cбане§r!");
                break;
            }

            $arg = $args;
            array_shift($arg);
            array_shift($arg);
            $reason = implode(" ", $arg);

            if ($pl !== null) {
                $this->banned->set(strtolower($pl->getName()), array(
                    'reason' => $reason,
                    'time' => $args[1],
                    'banby' => $banby
                ));
                $this->banned->save();
                $pl->kick("§fВы §bЗабанены! §fПричина: §c{$reason}\n§fВас забанил: §e{$banby} §fБан истекает: §a{$timeLeft}\nЛоги: §9@{$this->logsprefix}", false);
                
                $this->getServer()->broadcastMessage($this->prefix . "§cBans §7• §fИгрок §a{$pl->getName()} §fзаблокирован игроком §3{$banby}\n§cBans §7• §fВремя блокировки: §3{$timeLeft}\n§cBans §7• §fПричина блокировки: §e{$reason}\n§8> §fУ §3{$banby} §fесть 1 час, §fчтобы предоставить все док-ва в §e@{$this->logsprefix} §l§fили ваш аккаунт будет §l§cзаблокирован");

                // Получение vk_id игрока
                $vk_id = $this->getVkIdByUsername($pl->getName());
                $vk_admin_id = $this->getVkIdByUsername($banby);

                // Проверка наличия vk_id и админского vk_id
                $playerNamePart = !empty($vk_id) && $vk_id != 0 ? "[id{$vk_id}|{$pl->getName()}]" : "{$pl->getName()}";
                $adminNamePart = !empty($vk_admin_id) && $vk_admin_id != 0 ? "[id{$vk_admin_id}|{$banby}]" : "{$banby}";

                $leea = "🔒 Игрок {$playerNamePart} был заблокирован игроком {$adminNamePart}\n\n » Причина: {$reason}\n » Длительность: $timeLeft";
                
                $this->send_post($leea);

            } elseif($sender->hasPermission("hleb.offban")) {
                $this->banned->set(strtolower($args[0]), array(
                    'reason' => $reason,
                    'time' => $args[1],
                    'banby' => $banby
                ));
                $this->banned->save();
                $this->getServer()->broadcastMessage($this->prefix . "§cBans §7• §fИгрок §a{$args[0]} §fзаблокирован игроком §3{$banby}\n§cBans §7• §fВремя блокировки: §3{$timeLeft}\n§cBans §7• §fПричина блокировки: §e{$reason}\n§§8> §fУ §e{$banby} §fесть 1 час, §fчтобы предоставить все док-ва в §e@{$this->logsprefix}");

                // Получение vk_id игрока
                $vk_idoff = $this->getVkIdByUsername($args[0]);
                $vk_admin_id = $this->getVkIdByUsername($banby);

                // Проверка наличия vk_id и админского vk_id
                $playerNamePartOff = !empty($vk_idoff) && $vk_idoff != 0 ? "[id{$vk_idoff}|{$args[0]}]" : "{$args[0]}";
                $adminNamePartOff = !empty($vk_admin_id) && $vk_admin_id != 0 ? "[id{$vk_admin_id}|{$banby}]" : "{$banby}";

                $leea2 = "🔒 Игрок {$playerNamePartOff} был заблокирован игроком {$adminNamePartOff}\n\n » Причина: {$reason}\n » Длительность: $timeLeft";
                
                $this->send_post($leea2);
            }
        } else {
            $this->help($sender);
        }
    }
                break;
            #case 'ban-ip':
                ###
                #break;
            case 'pardon':
                if ($sender->hasPermission("hleb.pardon")) {
        if (count($args) == 1) {
            $args[0] = strtolower($args[0]);
            if ($sender Instanceof Player)
                $by = $sender->getName();
            else
                $by = "Console";

            if ($this->banned->exists($args[0])) {
                $this->banned->remove($args[0]);
                $this->banned->save();
                $this->getServer()->broadcastMessage($this->prefix . " §cBans §7• §fИгрок §a{$args[0]} разблокирован игроком §3{$by}\n§8> §f У §3{$by} §fесть 1 час, чтобы предоставить все док-ва в §e@{$this->logsprefix}");

                // Получение vk_id игрока
                $vk_idOff = $this->getVkIdByUsername($args[0]);
                $vk_admin_id = $this->getVkIdByUsername($by);

                // Проверка наличия vk_id и админского vk_id
                $playerNamePart = !empty($vk_idOff) && $vk_idOff != 0 ? "[id{$vk_idOff}|{$args[0]}]" : "{$args[0]}";
                $adminNamePart = !empty($vk_admin_id) && $vk_admin_id != 0 ? "[id{$vk_admin_id}|{$by}]" : "{$by}";

                $leea9 = "🔓 Игрок {$playerNamePart} был разблокирован игроком {$adminNamePart}";
                
                $this->send_post($leea9);

            } elseif ($this->bannedIp->exists($args[0])) {
                $this->bannedIp->remove($args[0]);
                $this->bannedIp->save();
                $this->getServer()->broadcastMessage($this->prefix . " §cBans §7• §fИгрок §a{$args[0]} разблокирован игроком §3{$by}\n§8> §f У §3{$by} §fесть 1 час, чтобы предоставить все док-ва в §e@{$this->logsprefix}");

                // Получение vk_id игрока
                $vk_idOff = $this->getVkIdByUsername($args[0]);
                $vk_admin_id = $this->getVkIdByUsername($by);

                // Проверка наличия vk_id и админского vk_id
                $playerNamePartIp = !empty($vk_idOff) && $vk_idOff != 0 ? "[id{$vk_idOff}|{$args[0]}]" : "{$args[0]}";
                $adminNamePartIp = !empty($vk_admin_id) && $vk_admin_id != 0 ? "[id{$vk_admin_id}|{$by}]" : "{$by}";

                $leea10 = "🔓 Игрок {$playerNamePart} был разблокирован игроком {$adminNamePart}";
                
                $this->send_post($leea10);

            } else {
                $sender->sendMessage($this->prefix . " §fИгрок с веденным ником отсутствует в бан-листе! Проверьте веденный ник!");
            }
        } else {
            $this->help($sender);
        }
    } else
                    $sender->sendMessage($this->prefix . " §fРазбан аккаунтов доступен от группы \"§eСоздатель§с\"!");
                break;
            case 'ban-list':
                if ($sender->hasPermission("hleb.list")) {
                    $banned = $this->banned->getAll();
                    $sender->sendMessage($this->prefix . " §fСписок забаненых");
                    foreach ($banned as $key => $value) {
                        $sender->sendMessage("§c{$key} §fПричина: §3{$value['reason']} §fЗабанил: §3{$value['banby']}");
                    }
                } else
                    $sender->sendMessage($this->prefix . " §fПросмотр забаненых игроков доступен от группы \"§eОператор§c\" и выше");
                break;
            case 'kick':
			           $arg = $args;
						array_shift($arg);
						array_shift($arg);
						$reason = implode(" ", $arg);
                if ($sender->hasPermission("hleb.kick")) {
    if (count($args) > 1) {
        if (strtolower($sender->getName()) == strtolower($args[0])) {
            $sender->sendMessage($this->prefix . " §fВы не можете кикнуть самого себя!");
            break;
        }

        $player = $this->getServer()->getPlayer($args[0]);
        if ($player !== null) {
            if ($sender Instanceof Player)
                $kickby = $sender->getName();
            else
                $kickby = "Console";

            if (!$player->hasPermission("hleb.immunity")) {
                $reason = implode(" ", array_slice($args, 1)); // Сбор причины кика

                // Кик игрока с сервера
                $player->kick($this->prefix . "§fВы §bКикнуты!\n§fПричина: §e{$reason}\n§fВас кикнул: §e{$kickby}\n§fЛоги: §9@{$this->logsprefix}", false);
                $name = $player->getName();

                // Сообщение на сервере о кике
                $this->getServer()->broadcastMessage($this->prefix . "§cKick §7• §fИгрок §a{$name} §fкикнут с сервера игроком §3{$kickby}\n§cKick §7• §fПричина кика: §e{$reason}\n§8> §f У §3{$kickby} §fесть 1 час, чтобы предоставить всё док-ва в §e@{$this->logsprefix}");

                // Получение vk_id игрока
                $vk_id = $this->getVkIdByUsername($name);
                $vk_admin_id = $this->getVkIdByUsername($kickby);

                // Проверка наличия vk_id и vk_id администратора
                $playerNamePart = !empty($vk_id) && $vk_id != 0 ? "[id{$vk_id}|{$name}]" : "{$name}";
                $adminNamePart = !empty($vk_admin_id) && $vk_admin_id != 0 ? "[id{$vk_admin_id}|{$kickby}]" : "{$kickby}";

                // Формирование и отправка сообщения в VK
                $leea5 = "🧹 Игрок {$playerNamePart} был кикнут с сервера игроком {$adminNamePart}\n\n » Причина: {$reason}";

                $this->send_post($leea5);

            } else {
                // Если игрок имеет иммунитет от кика
                $sender->sendMessage($this->prefix . " §fВы не можете кикнуть игрока с данной группой!");
            }
        } else {
            // Если игрок отсутствует на сервере
            $sender->sendMessage($this->prefix . " §fИгрок с введенным ником отсутствует на сервере!");
        }
    } else {
        // Если не указаны все аргументы
        $this->help($sender);
    }
} else
                    $sender->sendMessage($this->prefix . " §fКикать игроков доступно от группы \"§eМодератор§c\" и выше");
                break;
            case 'mute':
                if ($sender->hasPermission("hleb.mute")) {
    if (count($args) > 2 && preg_match("/^[0-9]+$/", $args[1])) {
        if (strtolower($sender->getName()) == strtolower($args[0])) {
            $sender->sendMessage($this->prefix . " §fВы не можете выдать мут на собственный аккаунт!");
            break;
        }

        $player = $this->getServer()->getPlayer($args[0]);
        if ($sender Instanceof Player)
            $muteby = $sender->getName();
        else
            $muteby = "Console";

        $arg = $args;
        array_shift($arg); // Удаление ника игрока
        array_shift($arg); // Удаление времени мута
        $reason = implode(" ", $arg); // Собираем причину мута

        if ($player !== null) {
            if ($player->hasPermission("hleb.immunity")) {
                $sender->sendMessage($this->prefix . " §fВы не можете выдать мут игроку с данной группой!");
                break;
            }

            if ($args[1] > 0 && $args[1] < $this->config->get("maxmute-time")) {
                $args[1] = time() + $args[1] * 60;
                $timeLeft = $this->parseTime($args[1]);

                // Сохранение информации о муте
                $this->muted->set(strtolower($args[0]), array(
                    "time" => $args[1],
                    "reason" => $reason,
                    "muteby" => $muteby
                ));
                $this->muted->save();

                $name = $player->getName();
                
                // Сообщение на сервере о муте
                $this->getServer()->broadcastMessage($this->prefix . "§cMute §7• §fИгрок §a{$muteby} §fзаблокировал чат игрока §3{$name}\n§cMute §7• §fПричина мута: §e{$reason}\n§cMute §7• §fВремя блокировки: §3{$timeLeft}\n§8> §fУ §a{$muteby} §fесть 1 час, чтобы предоставить все док-ва в §e@{$this->logsprefix}");

                // Получение vk_id игрока
                $vk_id = $this->getVkIdByUsername($name);
                $vk_admin_id = $this->getVkIdByUsername($muteby);

                // Проверка наличия vk_id и vk_id администратора
                $playerNamePart = !empty($vk_id) && $vk_id != 0 ? "[id{$vk_id}|{$name}]" : "{$name}";
                $adminNamePart = !empty($vk_admin_id) && $vk_admin_id != 0 ? "[id{$vk_admin_id}|{$muteby}]" : "{$muteby}";

                // Формирование и отправка сообщения в VK
                $leea6 = "🔇 Игрок {$playerNamePart} получил блокировку чата от игрока {$adminNamePart}\n\n » Причина: {$reason}\n » Длительность: $timeLeft";
                
                $this->send_post($leea6);

            } else {
                $sender->sendMessage($this->prefix . " §fНеверно указано время мута!");
            }
        } elseif ($sender->hasPermission("hleboff.mute")) {
            // Мут игрока оффлайн
            if ($args[1] > 0 && $args[1] < $this->config->get("maxmute-time")) {
                $args[1] = time() + $args[1] * 60;
                $timeLeft = $this->parseTime($args[1]);

                // Сохранение информации о муте оффлайн игрока
                $this->muted->set(strtolower($args[0]), array(
                    "time" => $args[1],
                    "reason" => $reason,
                    "muteby" => $muteby
                ));
                $this->muted->save();

                // Сообщение на сервере о муте оффлайн игрока
                $this->getServer()->broadcastMessage($this->prefix . "§cMute §7• §fИгрок §a{$muteby} §fзаблокировал чат игрока §3{$args[0]}\n§cMute §7• §fПричина мута: §e{$reason}\n§cMute §7• §fВремя блокировки: §3{$timeLeft}\n§8> §fУ §a{$muteby} §fесть 1 час, чтобы предоставить все док-ва в §e@{$this->logsprefix}");

                // Получение vk_id оффлайн игрока
                $vk_idOff = $this->getVkIdByUsername($args[0]);
                $vk_admin_id = $this->getVkIdByUsername($muteby);

                // Проверка наличия vk_id игрока и администратора
                $playerNamePart = !empty($vk_idOff) && $vk_idOff != 0 ? "[id{$vk_idOff}|{$args[0]}]" : "{$args[0]}";
                $adminNamePart = !empty($vk_admin_id) && $vk_admin_id != 0 ? "[id{$vk_admin_id}|{$muteby}]" : "{$muteby}";

                // Формирование и отправка сообщения в VK
                $leea7 = "🔇 Игрок {$playerNamePart} получил блокировку чата от игрока {$adminNamePart}\n\n » Причина: {$reason}\n » Длительность: $timeLeft";
                
                $this->send_post($leea7);
            } else {
                $sender->sendMessage($this->prefix . " §fНеверно указано время мута!");
            }
        } else {
            $sender->sendMessage($this->prefix . " §fИгрок с введенным ником отсутствует на сервере!");
        }
    } else {
        $this->help($sender);
    }
} else
                    $sender->sendMessage($this->prefix . " §fМутить игроков доступно от группы \"§eМодератор§c\" и выше");
                break;
            case 'unmute':
                if ($sender->hasPermission("hleb.unmute")) {
    if (count($args) == 1) {
        if ($sender Instanceof Player)
            $by = $sender->getName();
        else
            $by = "Console";

        $args[0] = strtolower($args[0]);

        // Проверка, существует ли игрок в списке мутов
        if ($this->muted->exists($args[0])) {
            $this->muted->remove($args[0]);
            $this->muted->save();

            // Уведомление на сервере о размуте
            $this->getServer()->broadcastMessage($this->prefix . " §cMute §7• §fИгрок §a{$by} §fразблокировал чат игрока §3{$args[0]}\n§8> §fУ §a{$by} §fесть 1 час, чтобы предоставить все док-ва в §e@{$this->logsprefix}");

            // Получение vk_id игрока и администратора
            $vk_idOff = $this->getVkIdByUsername($args[0]);
            $vk_admin_id = $this->getVkIdByUsername($by);

            // Формируем части для отправки сообщения в VK
            $playerNamePart = !empty($vk_idOff) && $vk_idOff != 0 ? "[id{$vk_idOff}|{$args[0]}]" : "{$args[0]}";
            $adminNamePart = !empty($vk_admin_id) && $vk_admin_id != 0 ? "[id{$vk_admin_id}|{$by}]" : "{$by}";

            // Формирование и отправка сообщения в VK
            $leea8 = "🔈 Игроку {$playerNamePart} разблокирован чат игроком {$adminNamePart}";

            $this->send_post($leea8);

        } else {
            $sender->sendMessage($this->prefix . " §fЭтого игрока не затыкали!");
        }
    } else {
        $this->help($sender);
    }
} else
                    $sender->sendMessage($this->prefix . " §fУ вас нет прав на использование этой комнды!");
                break;
            case 'freeze':
                if ($sender Instanceof Player)
                    $by = $sender->getName();
                else
                    $by = "Console";
                if ($sender->hasPermission("hleb.freeze")) {
                    if (count($args) == 2) {
                        switch ($args[0]) {
                            case 'add':
                                if ($args[1] == "@a") {
                                    foreach ($this->getServer()->getOnlinePlayers() as $p) {
                                        $this->freeze[strtolower($p->getName())] = strtolower($p->getName());
                                        $p->getLevel()->addSound(new FizzSound($p));
                                    }
                                    $this->getServer()->broadcastMessage($this->prefix . "§cFreeze §7• §fИгрок: §a{$by} §f§3заморозил §l§eВСЕХ§7!");
                                } else {
									$p = $this->getServer()->getPlayer($args[1]);
                                    if ($p == null) {
                                        $sender->sendMessage($this->prefix . " §fИгрок с веденным ником отсутствует на сервере");
                                        return;
                                    }
									$name = $p->getName();
                                    $this->freeze[strtolower($name)] = strtolower($name);
                                    $this->getServer()->broadcastMessage($this->prefix . "§cFreeze §7• §fИгрок: §a{$by} §3заморозил §fигрока §3" . $name);
                                    $p->getLevel()->addSound(new FizzSound($p));
                                }
                                break;
                            case 'del':
                                if ($args[1] == "@a") {
                                    foreach ($this->freeze as $p => $value) {
                                        unset($this->freeze[$p]);
                                    }
                                    $this->getServer()->broadcastMessage($this->prefix . "§cFreeze §7• §fИгрок: §a{$by} §f§eвсех §3разморозил!");
                                } else {
                                    unset($this->freeze[strtolower($args[1])]);
                                    $this->getServer()->broadcastMessage($this->prefix . "§cFreeze §7• §fИгрок: §a{$by} §3разморозил §fигрока: §3{$args[1]}!");
                                }
                                break;
                            default:
                                $this->help($sender);
                                break;
                        }
                    } else
                        $this->help($sender);
                }
                break;
            case 'ban-help':
                $this->help($sender);
                break;
        }
    }

    // Массив для отслеживания игроков, которым уже было отправлено сообщение о заморозке
private $frozenMessageSent = [];

public function onMove(PlayerMoveEvent $ev)
{
    $player = $ev->getPlayer();
    $name = strtolower($player->getName());

    // Проверяем, заморожен ли игрок
    if (in_array($name, $this->freeze)) {
        // Если сообщение уже не отправлялось этому игроку
        if (!isset($this->frozenMessageSent[$name]) || !$this->frozenMessageSent[$name]) {
            // Отправляем сообщение
            $player->sendMessage($this->prefix . "§l§fВы §3заморожены");

            // Устанавливаем флаг, что сообщение было отправлено
            $this->frozenMessageSent[$name] = true;
        }
        
        // Отменяем движение игрока
        $ev->setCancelled(true);
    } else {
        // Если игрок разморожен, сбрасываем флаг
        if (isset($this->frozenMessageSent[$name])) {
            unset($this->frozenMessageSent[$name]);
        }
    }
}

    public function onMute(PlayerChatEvent $e)
    {
        $p     = $e->getPlayer();
        $name  = strtolower($p->getName());
        $muted = $this->muted->getAll();
        if (isset($muted[$name])) {
            $e->setCancelled();
            $timeLeft = $this->parseTime($muted[$name]['time']);
            $p->sendMessage($this->prefix . "§l§fВаш чат заблокирован! §7| Конец мута: {$timeLeft}");
        }
    }

    private function help($sender)
    {
        $sender->sendMessage($this->prefix . "§l§fПомощь по §7(§cFall§fCraft §cBans§7)");
        $sender->sendMessage($this->prefix . "§l§e/ban §8(§aНик§8) §8(§aВремя§8) §8(§aПричина§8) - §fЗабанить игрока (макс.525600)");
        #$sender->sendMessage($this->prefix . "§l§e/ban-ip §8(§aНик§8) §8(§aВремя§8) §8(§aПричина§8) - §fЗабанить игрока по IP (макс.525600) ");
        $sender->sendMessage($this->prefix . "§l§e/pardon §8(§aНик§8) §e- §fРазбанить игрока");
        $sender->sendMessage($this->prefix . "§l§e/ban-list §e- §fСписок забаненых");
        $sender->sendMessage($this->prefix . "§l§e/kick §8(§aНик§8) §8(§aПричина§8) - §fКикнуть игрока");
        $sender->sendMessage($this->prefix . "§l§e/mute §8(§aНик§8) §8(§aВремя§8) §8(§aПричина§8) - §fЗаткнуть игрока (макс.10080)");
        $sender->sendMessage($this->prefix . "§l§e/unmute §8(§aНик§8) - §fСнять мут игрока");
        $sender->sendMessage($this->prefix . "§l§e/freeze §8(§aadd|del§8) §8(§a@a|Ник§8) - §fЗаморозка игроков");
        $sender->sendMessage($this->prefix . "§l§fВремя бана и кика в минутах.");
    }

    private function parseTime($time)
    {
        switch ($time) {
            case '0':
                return "Никогда";
                break;
            default:
                $now     = time();
                $left    = ($time - $now);
                $seconds = $left % 60;
                $minutes = (int) ($left / 60);
                if ($minutes >= 60) {
                    $hours   = (int) ($minutes / 60);
                    $minutes = $minutes % 60;
                }
                if (@$hours >= 24) {
                    $days  = (int) ($hours / 24);
                    $hours = $hours % 24;
                }
                $timeLeft = $seconds . "с.";
                $timeLeft = $minutes . "м. " . $timeLeft;
                if (isset($hours))
                    $timeLeft = $hours . "ч. " . $timeLeft;
                if (isset($days))
                    $timeLeft = $days . "д. " . $timeLeft;
                return " " . $timeLeft;
                break;
        }
    }
    
    public function cronTask()
    {
        $banned = $this->banned->getAll();
        foreach ($banned as $key => $value) {
            if (time() >= $value['time'] && $value['time'] != 0)
                $this->banned->remove($key);
        }
        $this->banned->save();
        $bannedIp = $this->bannedIp->getAll();
        foreach ($bannedIp as $key => $value) {
            if (time() >= $value['time'] && $value['time'] != 0)
                $this->bannedIp->remove($key);
        }
        $this->bannedIp->save();
        $muted = $this->muted->getAll();
        foreach ($muted as $key => $value) {
            if (time() >= $value['time'])
                $this->muted->remove($key);
        }
        $this->muted->save();
    }
}
?>