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
        $dbPath = "/root/linux/plugins/HlebBans_src/vk_id.db";
        $this->db = new \SQLite3($dbPath);
        $this->db->exec("CREATE TABLE IF NOT EXISTS users (username TEXT PRIMARY KEY, vk_id TEXT)");
    }
    
    private function formatVkUserLink($username) 
    {
    $vk_id = $this->getVkIdByUsername($username);
    if (!empty($vk_id) && $vk_id != '0') {
        return "[id{$vk_id}|{$username}]";
    }
    return $username;
}

    private function sendVkNotification($action, $targetName, $moderatorName, $reason = '', $duration = '') 
    {
    $actions = [
        'ban' => [
            'emoji' => '🔒',
            'text' => "был заблокирован"
        ],
        'unban' => [
            'emoji' => '🔓',
            'text' => "был разблокирован"
        ],
        'kick' => [
            'emoji' => '🧹',
            'text' => "был кикнут"
        ],
        'mute' => [
            'emoji' => '🔇',
            'text' => "получил блокировку чата"
        ],
        'unmute' => [
            'emoji' => '💉',
            'text' => 'получил разблокировку чата'
            ]
    ];

    if (!isset($actions[$action])) return;

    $act = $actions[$action];
    $target = $this->formatVkUserLink($targetName);
    $moderator = $this->formatVkUserLink($moderatorName);

    $message = "{$act['emoji']} Игрок {$target} {$act['text']} игроком {$moderator}";
    
    if (!empty($reason)) {
        $message .= "\n\n » Причина: {$reason}";
    }
    
    if (!empty($duration)) {
        $message .= "\n » Длительность: {$duration}";
    }

    $this->send_post($message);
}

    private function handleApiResponse($response) 
    {
    $data = json_decode($response, true);
    if (isset($data['error'])) {
        $this->getLogger()->error("VK API Error: {$data['error']['error_msg']} (code: {$data['error']['error_code']})");
        return false;
    }
    return true;
}
/** @param string $message */
    private function url($url)
    {
		$ch = curl_init($url); 
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); 
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
		$response = curl_exec($ch); 
		curl_close($ch); 
		return $response; 
	}

    public function send_post($msg) 
    {
    $topic_id = '53828441';
    $group_id = '229239390';
    $from_group = '1';
    $token = 'vk1.a.J_OiNBUN8RFCKguMhBSADqO0bbB-kkyqriw4jK2OikQkbcqQrHxJwLt8qdeTBpAsPc5JsynyNWo1WGeIAzk_cGBLpVnKPwv4d3yPOvCKFwGU7eLLbs0LMuEc0hmbjfKuxqGl85i1D3hkqu8UGcRpvGZj21sE6N0wEW2xohwq9IglEo4i0HlkIe9ZJnRhHT3KT7WTacNWEs8dHYm27bOOkw';
    
    $url = "https://api.vk.com/method/board.createComment?" . http_build_query([
        'group_id' => $group_id,
        'topic_id' => $topic_id,
        'message' => $msg,
        'from_group' => $from_group,
        'v' => '5.131',
        'access_token' => $token
    ]);

    $response = $this->url($url);
    return $this->handleApiResponse($response);
}

// В методе getVkIdByUsername исправим возвращаемое значение
    public function getVkIdByUsername($username) 
    {
    $stmt = $this->db->prepare("SELECT vk_id FROM users WHERE username = :username");
    $stmt->bindValue(':username', strtolower($username), SQLITE3_TEXT);
    $result = $stmt->execute();

    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        return $row['vk_id'];
    }
    return null; // Возвращаем null вместо '0'
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
            $e->setKickMessage("§fВы §4Забанены! §fПричина: §c{$ban['reason']}\n§fВас §4забанил: §a{$ban['banby']}  §fБан истекает: §8{$timeLeft}\n§fЛоги: §6@{$this->logsprefix}");
            $e->setCancelled(true);
        } elseif ($this->bannedIp->exists($ip)) {
			if ($e->getPlayer()->hasPermission("hleb.immunity")) {
				return;
			}
            $ban      = $this->bannedIp->get($ip);
            $timeLeft = $this->parseTime($ban['time']);
            $e->setKickMessage("§fВы §4Забанены! §fПричина: §c{$ban['reason']}\n§fВас §4забанил: §a{$ban['banby']}  §fБан истекает: §8{$timeLeft}\n§fЛоги: §6@{$this->logsprefix}");
            $e->setCancelled(true);
			
        }
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args)
    {
        switch ($label) {
            case 'hban':
    if ($sender->hasPermission("hleb.ban")) {
        if (count($args) > 1 && preg_match("/^[0-9]+$/", $args[1])) {
            $pl = $this->getServer()->getPlayer($args[0]);
            
            // Проверка иммунитета
            if ($pl !== null && $pl->hasPermission("hleb.immunity")) {
                $sender->sendMessage($this->prefix . " §fВы §4не можете забанить §fигрока с данной группой!");
                return;
            }

            // Валидация времени бана
            if (!$sender->isOp() && ($args[1] == 0 || $args[1] > $this->config->get("maxban-time"))) {
                $sender->sendMessage($this->prefix . " §fНедопустимое время бана");
                break;
            }

            // Запрет самобана
            if ($sender instanceof Player && strtolower($args[0]) == strtolower($sender->getName())) {
                $sender->sendMessage($this->prefix . " §fВы §4не можете забанить §fсобственный аккаунт!");
                break;
            }

            // Подготовка данных
            $banTime = $args[1] != 0 ? time() + $args[1] * 60 : 0;
            $banby = $sender instanceof Player ? $sender->getName() : "Console";
            $timeLeft = $this->parseTime($banTime);
            $reason = implode(" ", array_slice($args, 2));

            // Бан онлайн-игрока
            if ($pl !== null) {
                $this->processBan(
                    $pl->getName(),
                    $banby,
                    $reason,
                    $banTime,
                    $timeLeft
                );
                
                $pl->kick("§fВы §4Забанены! §fПричина: §c{$reason}\n§fВас §4забанил: §a{$banby} §fБан истекает: §8{$timeLeft}\nЛоги: §6@{$this->logsprefix}", false);
                
                $this->getServer()->broadcastMessage($this->prefix . "§cBans §7• §fИгрок §a{$pl->getName()} §fзаблокирован игроком §3{$banby}\n§cBans §7• §fВремя блокировки: §3{$timeLeft}\n§cBans §7• §fПричина блокировки: §e{$reason}\n§8> §fУ §a{$banby} §fесть 1 час, §fчтобы предоставить все док-ва в §6@{$this->logsprefix}");
                
                // Отправка уведомления в ВК
                $this->sendVkNotification('ban', $pl->getName(), $banby, $reason, $timeLeft);
                
            // Бан оффлайн-игрока
            } elseif($sender->hasPermission("hleb.offban")) {
                $this->processBan(
                    $args[0],
                    $banby,
                    $reason,
                    $banTime,
                    $timeLeft
                );
                
                $this->getServer()->broadcastMessage($this->prefix . "§cBans §7• §fИгрок §a{$args[0]} §fзаблокирован игроком §3{$banby}\n§cBans §7• §fВремя блокировки: §3{$timeLeft}\n§cBans §7• §fПричина блокировки: §e{$reason}\n§§8> §fУ §a{$banby} §fесть 1 час, §fчтобы предоставить все док-ва в §6@{$this->logsprefix}");
                
                // Отправка уведомления в ВК
                $this->sendVkNotification('ban', $args[0], $banby, $reason, $timeLeft);
            }
        } else {
            $this->help($sender);
        }
    }
    
    break;
            case 'hpardon':
    if ($sender->hasPermission("hleb.pardon")) {
        if (count($args) == 1) {
            $target = strtolower($args[0]);
            $moderator = $sender instanceof Player ? $sender->getName() : "Console";
            $unbanned = false;

            // Снимаем обычный бан
            if ($this->banned->exists($target)) {
                $this->processPardon($target, 'banned');
                $unbanned = true;
            }

            // Снимаем IP-бан
            if ($this->bannedIp->exists($target)) {
                $this->processPardon($target, 'bannedIp');
                $unbanned = true;
            }

            if ($unbanned) {
                $this->getServer()->broadcastMessage(
                    $this->prefix . " §cPardon §7• §fИгрок §a{$args[0]} §fразблокирован игроком §c{$moderator}\n" .
                    "§8> §fУ §c{$moderator} §fесть 1 час чтобы предоставить док-ва в §6@{$this->logsprefix}"
                );

                // Отправка уведомления в ВК
                $this->sendVkNotification('unban', $args[0], $moderator);
            } else {
                $sender->sendMessage($this->prefix . " §fИгрок §a{$args[0]} §fне найден в бан-листах!");
            }
        } else {
            $this->help($sender);
        }
    } else {
        $sender->sendMessage($this->prefix . " §fКоманда доступна от группы §eСоздатель§c!");
    }
    
    break;
            case 'hbanlist':
    if ($sender->hasPermission("hleb.list")) {
        $bannedPlayers = $this->banned->getAll();
        $currentPage = isset($args[0]) ? max(1, (int)$args[0]) : 1;
        
        // Конфигурация пагинации
        $perPage = 8;
        $total = count($bannedPlayers);
        $totalPages = ceil($total / $perPage);
        
        if ($total === 0) {
            $sender->sendMessage($this->prefix . " §fСписок §cзабаненных §fигроков пуст!");
            break;
        }

        // Валидация страницы
        $currentPage = min($currentPage, $totalPages);
        $offset = ($currentPage - 1) * $perPage;
        $pageData = array_slice($bannedPlayers, $offset, $perPage, true);

        // Формирование заголовка
        $sender->sendMessage("§c▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬");
        $sender->sendMessage("§cСписок забаненных игроков §7(Страница §f{$currentPage}§7/§f{$totalPages}§7)");
        
        // Вывод записей
        $counter = $offset + 1;
        foreach ($pageData as $player => $data) {
            $timeLeft = $this->parseTime($data['time']);
            $sender->sendMessage("§8{$counter}. §c{$player} §8| §7Причина: §f{$data['reason']}");
            $sender->sendMessage("§a▸ §7Забанил: §e{$data['banby']} §8| §7Истекает: §a{$timeLeft}");
            $counter++;
        }

        // Подвал и навигация
        $sender->sendMessage("§c▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬");
        if ($totalPages > 1) {
            $sender->sendMessage("§7Используйте §f/hbanlist <страница> §7для навигации");
        }
    } else {
        $sender->sendMessage($this->prefix . " §cОшибка: §fНедостаточно прав!");
    }
    
    break;
            case 'hkick':
    if ($sender->hasPermission("hleb.kick")) {
        if (count($args) > 1) {
            $targetName = $args[0];
            $reason = implode(" ", array_slice($args, 1));
            $moderator = $sender instanceof Player ? $sender->getName() : "Console";

            // Запрет самокика
            if (strtolower($sender->getName()) === strtolower($targetName)) {
                $sender->sendMessage($this->prefix . "§fНельзя §cкикнуть §fсамого себя!");
                break;
            }

            $target = $this->getServer()->getPlayer($targetName);
            
            if ($target instanceof Player) {
                // Проверка иммунитета
                if ($target->hasPermission("hleb.immunity")) {
                    $sender->sendMessage($this->prefix . "§fИгрок с группой §a{$target->getName()} §cне может быть кикнут!");
                    break;
                }

                // Формирование сообщений
                $kickMessage = "§cKick • §fВы были §cкикнуты!\n"
                    . "§fПричина: §e{$reason}\n"
                    . "§fМодератор: §a{$moderator}\n"
                    . "§fЛоги: §6@{$this->logsprefix}";

                // Выполнение кика
                $target->kick($kickMessage, false);
                
                // Броадкаст на сервер
                $this->getServer()->broadcastMessage(
                    $this->prefix . " §cKick §7• §fИгрок §a{$target->getName()} §cкикнут!\n"
                    . "§cKick §7• §fПричина: §e{$reason}\n"
                    . "§cKick §7• §fМодератор: §a{$moderator}\n"
                    . "§8> §fДоказательства должны быть предоставлены в течение 1 часа"
                );

                // Уведомление в VK
                $this->sendVkNotification(
                    'kick',
                    $target->getName(),
                    $moderator,
                    $reason
                );

            } else {
                $sender->sendMessage($this->prefix . " §fИгрок §e{$targetName} §cне в сети!");
            }
        } else {
            $this->help($sender);
        }
    } else {
        $sender->sendMessage($this->prefix . " §cНедостаточно прав!");
    }
    
    break;
            case 'hmute':
    if ($sender->hasPermission("hleb.mute")) {
        if (count($args) > 2 && is_numeric($args[1])) {
            $targetName = $args[0];
            $duration = (int)$args[1];
            $reason = implode(" ", array_slice($args, 2));
            $moderator = $sender instanceof Player ? $sender->getName() : "Console";

            // Проверка на самомута
            if (strtolower($sender->getName()) === strtolower($targetName)) {
                $sender->sendMessage($this->prefix . " §fНельзя выдать мут §cсамому себе!");
                break;
            }

            // Валидация времени
            if ($duration < 1 || $duration > 10080) {
                $sender->sendMessage($this->prefix . "§fДлительность мута должна быть от §c1 до 10080 минут!");
                break;
            }

            $target = $this->getServer()->getPlayer($targetName);
            $expireTime = time() + ($duration * 60);
            $timeLeft = $this->parseTime($expireTime);

            // Обработка онлайн-игрока
            if ($target instanceof Player) {
                if ($target->hasPermission("hleb.immunity")) {
                    $sender->sendMessage($this->prefix . "§fИгрок §a{$targetName} §fимеет иммунитет!");
                    break;
                }

                $this->applyMute($targetName, $expireTime, $reason, $moderator);
                
                // Уведомление игроку
                $target->sendMessage("§c▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬");
                $target->sendMessage("§fВам выдан мут до §c" . date("H:i", $expireTime));
                $target->sendMessage("§fПричина: §4{$reason}");
                $target->sendMessage("§fМодератор: §a{$moderator}");
                $target->sendMessage("§c▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬");

            // Обработка оффлайн-игрока
            } elseif ($sender->hasPermission("hleb.offmute")) {
                $this->applyMute($targetName, $expireTime, $reason, $moderator);
            } else {
                $sender->sendMessage($this->prefix . " §cОшибка: §fИгрок §e{$targetName} §fне в сети!");
                break;
            }

            // Броадкаст на сервер
            $this->getServer()->broadcastMessage(
                "§c▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬\n" .
                "§cMUTE §8| §fИгроку §e{$targetName} §fвыдан мут\n" .
                "§cMUTE §8| §fПричина: §7{$reason}\n" .
                "§cMUTE §8| §7Длительность: §4{$timeLeft}\n" .
                "§cMUTE §8| §7Модератор: §a{$moderator}\n" .
                "§c▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬"
            );

            // Отправка в VK
            $this->sendVkNotification('mute', $targetName, $moderator, $reason, $timeLeft);

        } else {
            $this->help($sender);
        }
    } else {
        $sender->sendMessage($this->prefix . "§fНедостаточно прав!");
    }
    
    break;
            case 'hunmute':
    if ($sender->hasPermission("hleb.unmute")) {
        if (count($args) >= 1) {
            $targetName = strtolower($args[0]);
            $moderator = $sender instanceof Player ? $sender->getName() : "Console";

            if ($this->muted->exists($targetName)) {
                // Удаление мута
                $muteData = $this->muted->get($targetName);
                $this->muted->remove($targetName);
                $this->muted->save();

                // Уведомление онлайн-игроку
                $target = $this->getServer()->getPlayerExact($targetName);
                if ($target instanceof Player) {
                    $target->sendMessage("§a▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬");
                    $target->sendMessage("§fВаш мут §aбыл снят!");
                    $target->sendMessage("§fМодератор: §c" . $moderator);
                    $target->sendMessage("§a▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬");
                }

                // Броадкаст на сервер
                $this->getServer()->broadcastMessage(
                    "§a▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬\n" .
                    "§cUNMUTE §8| §fИгроку §c" . ucfirst($targetName) . " §aснят мут\n" .
                    "§cUNMUTE §8| §7Причина мута: §f" . $muteData['reason'] . "\n" .
                    "§cUNMUTE §8| §7Модератор: §a" . $moderator . "\n" .
                    "§a▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬▬"
                );

                // Отправка в VK
                $this->sendVkNotification(
                    'unmute', 
                    ucfirst($targetName), 
                    $moderator, 
                    "."
                );

            } else {
                $sender->sendMessage($this->prefix . "§fИгрок §a" . ucfirst($targetName) . " §cне в муте!");
            }
        } else {
            $sender->sendMessage("§cИспользование: §f/hunmute <ник>");
        }
    } else {
        $sender->sendMessage($this->prefix . "§cНедостаточно прав!");
    }
    break;
            case 'hfreeze':
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
            case 'bans-help':
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
        $sender->sendMessage($this->prefix . "§l§fПомощь по §7(§eHleb§fCraft §cBans§7)");
        $sender->sendMessage($this->prefix . "§l§e/hban §8(§aНик§8) §8(§aВремя§8) §8(§aПричина§8) - §fЗабанить игрока (макс.525600)");
        $sender->sendMessage($this->prefix . "§l§e/hpardon §8(§aНик§8) §e- §fРазбанить игрока");
        $sender->sendMessage($this->prefix . "§l§e/hbanlist §e- §fСписок забаненых");
        $sender->sendMessage($this->prefix . "§l§e/hkick §8(§aНик§8) §8(§aПричина§8) - §fКикнуть игрока");
        $sender->sendMessage($this->prefix . "§l§e/hmute §8(§aНик§8) §8(§aВремя§8) §8(§aПричина§8) - §fЗаткнуть игрока (макс.10080)");
        $sender->sendMessage($this->prefix . "§l§e/hunmute §8(§aНик§8) - §fСнять мут игрока");
        $sender->sendMessage($this->prefix . "§l§e/hfreeze §8(§aadd|del§8) §8(§a@a|Ник§8) - §fЗаморозка игроков");
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
    
    private function processBan($target, $banner, $reason, $time, $timeLeft) 
    {
    $this->banned->set(strtolower($target), [
        'reason' => $reason,
        'time' => $time,
        'banby' => $banner
    ]);
    $this->banned->save();
}

    private function applyMute($targetName, $expireTime, $reason, $moderator) {
    $this->muted->set(strtolower($targetName), [
        'time' => $expireTime,
        'reason' => $reason,
        'muteby' => $moderator
    ]);
    $this->muted->save();
}

    private function processPardon($target, $listType) {
    $config = $this->{$listType}; // Получаем нужный конфиг
    $config->remove($target);
    $config->save();
}

}
?>