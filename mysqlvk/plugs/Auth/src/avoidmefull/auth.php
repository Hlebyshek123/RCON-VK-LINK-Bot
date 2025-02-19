<?php

namespace avoidmefull;

use pocketmine\command\{Command, CommandSender};
use pocketmine\event\block\{BlockBreakEvent, BlockPlaceEvent};
use pocketmine\event\entity\{EntityDamageByEntityEvent, EntityDamageEvent};
use pocketmine\event\Listener;
use pocketmine\event\player\{PlayerChatEvent,
    PlayerCommandPreprocessEvent,
    PlayerDropItemEvent,
    PlayerInteractEvent,
    PlayerJoinEvent,
    PlayerMoveEvent,
    PlayerPreLoginEvent,
    PlayerQuitEvent
};
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\CallbackTask;
use pocketmine\utils\Config;
use AllowDynamicProperties;
use mysqli;

class auth extends PluginBase implements Listener
{
    
    private $data;
    private $players;
    private $register = [], $attempts = [], $auth_timeout = [];

    public $users = [];

    public function onEnable()
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->mysqlConnect();
        $this->checkDatabase();

        $folder = "/root/linux/plugins/Auth";
        if (!is_dir($folder)) @mkdir($folder);
        $this->players = new Config($folder . "players.json", Config::JSON);

        $this->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask([$this, "addTitle"], []), 20);
        $this->checkPlayers();
    }

    public function onDisable()
    {
        foreach ($this->players->getAll() as $name => $port) {
            if ($port == $this->getServer()->getPort()) {
                $this->players->remove($name);
                $this->players->save();
            }
        }

        if ($this->data) $this->data->close();
    }

    private function mysqlConnect()
    {
        $config = [
        'host' => "sql7.freesqldatabase.com",
        'user' => "sql7759759",
        'pass' => "8S8UBcTY92",
        'db'   => "sql7759759"
    ];
    
        $this->data = new \mysqli(
        $config['host'],
        $config['user'],
        $config['pass'],
        $config['db']
    );

        if ($this->data->connect_error) {
            $this->getLogger()->alert("MySQL connection error: " . $this->data->connect_error);
            $this->data = null;
        }
    }

    public function getMySQLConnection()
{
    // Если подключение уже существует
    if ($this->data instanceof \mysqli) {
        // Проверяем живость соединения через ping()
        if ($this->data->ping()) {
            return true;
        }
        
        // Если соединение мертвое, пересоздаём
        $this->mysqlConnect();
    } else {
        // Создаём новое подключение если его нет
        $this->mysqlConnect();
    }

    // Проверяем результат подключения
    return $this->data !== null;
}

    private function checkDatabase()
    {
        if ($this->data) {
            $this->data->query("CREATE TABLE IF NOT EXISTS `users` (
                `nickname` VARCHAR(32) PRIMARY KEY,
                `password` VARCHAR(128) NOT NULL,
                `reg_ip` VARCHAR(45) NOT NULL,
                `last_ip` VARCHAR(45) NOT NULL,
                `last_device` VARCHAR(64) DEFAULT '',
                `last_port` INT DEFAULT 0,
                `last_date` INT DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
        }
    }

    public function registerUser(string $name, string $password, string $ip, string $device = "", int $port = 0, int $date = 0): bool
    {
        if (!$this->data) return false;

        $stmt = $this->data->prepare("INSERT INTO users (nickname, password, reg_ip, last_ip, last_device, last_port, last_date) 
            VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE password=VALUES(password), last_ip=VALUES(last_ip)");
        
        $hashed = $this->passwordToHash($password);
        $stmt->bind_param("ssssssi", 
            $name,
            $hashed,
            $ip,
            $ip,
            $device,
            $port,
            $date
        );

        return $stmt->execute();
    }

    public function getUser(string $name): array
    {
        if (!$this->data) return [];

        $stmt = $this->data->prepare("SELECT * FROM users WHERE nickname = ?");
        $stmt->bind_param("s", $name);
        $stmt->execute();
        
        $result = $stmt->get_result();
        return $result->fetch_assoc() ?? [];
    }

    public function setUserAuthInfo(string $name, string $ip, string $device, int $port, int $date): bool
    {
        if (!$this->data) return false;

        $stmt = $this->data->prepare("UPDATE users SET last_ip = ?, last_device = ?, last_port = ?, last_date = ? WHERE nickname = ?");
        $stmt->bind_param("ssiis", $ip, $device, $port, $date, $name);
        return $stmt->execute();
    }
    
    public function getCountUsers() {
    $count = 0;

    // Проверка подключения
    if (!$this->data || $this->data->connect_error) {
        $this->getLogger()->alert("Нет подключения к базе данных");
        return 0;
    }

    try {
        // Начало транзакции
        $this->data->autocommit(false);
        
        // Подготовка запроса
        $stmt = $this->data->prepare("SELECT COUNT(*) AS user_count FROM `users`");
        if (!$stmt) {
            throw new Exception("Ошибка подготовки запроса: " . $this->data->error);
        }

        // Выполнение запроса
        if (!$stmt->execute()) {
            throw new Exception("Ошибка выполнения запроса: " . $stmt->error);
        }

        // Получение результата
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $count = $row['user_count'];
        }

        // Фиксация транзакции
        $this->data->commit();
        
        // Закрытие запроса
        $stmt->close();

    } catch (Exception $e) {
        // Откат транзакции при ошибке
        $this->data->rollback();
        $this->getLogger()->alert($e->getMessage());
        return 0;
    }

    return $count;
}

    public function passwordToHash(string $password): string
    {
        return hash("sha512", $password);
    }

    public function addTitle()
    {
        foreach ($this->getServer()->getOnlinePlayers() as $pls) {
            if ($pls->isOnline()) {
                if (isset($this->auth_timeout[$pls->getName()])) {
                    $pls->addTitle("§l§aАвторизация", "§l§fОткройте чат и введите пароль", 0, 30, 0);
                }
            }
        }
    }

    public function checkPlayers()
    {
        foreach ($this->players->getAll() as $name => $port) {
            if ($port == $this->getServer()->getPort()) {
                $value = false;
                foreach ($this->getServer()->getOnlinePlayers() as $pls) {
                    if (strtolower($pls->getName()) == $name) {
                        $value = true;
                        break;
                    }
                }
                if ($value)
                    continue;
                $this->players->remove($name);
                $this->players->save();
            }
        }
    }

    public function setAuthTimer(Player $target)
    {
        $this->getServer()->getScheduler()->scheduleDelayedTask($task = new CallbackTask(array($target, "close"), array("Время авторизации вышло", "§aAuth §8• §fВремя для авторизации аккаунта вышло", true)), 20 * 75);
        $this->auth_timeout[$target->getName()] = $task->getTaskId();
    }

    public function successfulAuth(Player $target)
    {
        if (isset($this->auth_timeout[$target->getName()])) {
            $this->getServer()->getScheduler()->cancelTask($this->auth_timeout[$target->getName()]);
            unset($this->auth_timeout[$target->getName()]);
        }
        $target->addTitle("§c§lEpic§fMine", "§l§aАвторизован" . $this->getServer()->getPort(), 10, 60, 10);
    }

    public function onChat(PlayerChatEvent $e)
    {
        if (!isset($this->users[strtolower($e->getPlayer()->getName())])) {
            $e->setCancelled(true);
            return true;
        }
        $p = $e->getPlayer();
        $nickname = strtolower($p->getName());
        $message = $e->getMessage();
        $password = explode(" ", $message)[0];
        $result = $this->getUser($nickname);
        $password_hash = $this->passwordToHash($password);
        if ($password_hash === $result["password"]) {
            $e->setCancelled(true);
        }
        return true;
    }

    public function onBreak(BlockBreakEvent $e)
    {
        if (!isset($this->users[strtolower($e->getPlayer()->getName())]))
            $e->setCancelled(true);
    }

    public function onPlace(BlockPlaceEvent $e)
    {
        if (!isset($this->users[strtolower($e->getPlayer()->getName())]))
            $e->setCancelled(true);
    }

    public function onDrop(PlayerDropItemEvent $e)
    {
        if (!isset($this->users[strtolower($e->getPlayer()->getName())]))
            $e->setCancelled(true);
    }

    /**
     * @param PlayerInteractEvent $e
     *
     * @priority        LOWEST
     *
     */
    public function onInteract(PlayerInteractEvent $e)
    {
        if (!isset($this->users[strtolower($e->getPlayer()->getName())]))
            $e->setCancelled(true);
    }

    public function onMove(PlayerMoveEvent $e)
    {
        if (!isset($this->users[strtolower($e->getPlayer()->getName())]))
            $e->setCancelled(true);
    }


    public function onDamage(EntityDamageEvent $e)
    {
        if ($e instanceof EntityDamageByEntityEvent) {
            $p = $e->getEntity();
            if ($p instanceof Player) {
                $damager = $e->getDamager();
                if ($damager instanceof Player) {
                    if (!isset($this->users[strtolower($p->getName())]) || !isset($this->users[strtolower($damager->getName())])) {
                        $e->setCancelled();
                    }
                }
            }
        }
    }

    public function onPreLogin(PlayerPreLoginEvent $e)
    {

        $p = $e->getPlayer();

        if (!$this->getMySQLconnection()) {
            $p->close("Не удалось подключиться к базе данных", "§aAuth §8• §fПроизошла ошибка при подключении к базе данных \n§aAuth §8• §fПросим обратиться в тех.поддержку сервера");
            return true;
        }
        /*
        $this->players->reload();
        if(isset($this->players->getAll()[strtolower($p->getName())])){
            $e->setCancelled(true);
            $e->setKickMessage("§fВаш персонаж §aуже находится §fв игре!");
        }else{
            $this->players->set(strtolower($p->getName()), $this->getServer()->getPort());
            $this->players->save();
        }*/
        return true;

    }

    /**
     * @param PlayerJoinEvent $event
     *
     * @priority LOWEST
     */
    public function onJoin(PlayerJoinEvent $e)
    {

        $e->setJoinMessage("");

        $p = $e->getPlayer();

        $p->setImmobile(true);

        $p->sendMessage("§6• §fДобро пожаловать на проект §l§o§cEpic§fMine§r§f! §r§fСпасибо, что выбрали наши сервера! \n\n§6• §fГруппа сервера§7: §6@sosal §7| §fСайт сервера§7: §6shop.sosal.org");

        $result = $this->getUser($p->getName());
        if (isset($result["password"]) && $result["password"] != null) {
            $address = $p->getAddress();
            if (isset($result["last_ip"]) && $address == $result["last_ip"]) {
                $p->setImmobile(false);
                $this->users[strtolower($p->getName())] = ["ip" => $address];
                $this->setUserAuthInfo($p->getName(), $address, $p->getDeviceModel(), $this->getServer()->getPort(), time());
                $this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask(array($this, "successfulAuth"), array($p)), 10);
                $p->sendMessage("\n§a► §fВы авторизовались автоматически, приятной игры!");
                $p->sendMessage("§6! §fВы так же можете поставить защиту, дабы обезопасить ваш аккаунт §7- §6/mb [skin-on|uuid-on]\n");
            } else {
                $this->attempts[strtolower($p->getName())] = 0;
                $this->setAuthTimer($p);
                $p->sendMessage("\n§b► §fПожалуйста, авторизуйтесь, введя §bпароль §fв чат");
                $p->sendMessage("§6! §fЕсли вы зашли впервые, то §6смените §fникнейм на любой другой");
            }
        } else {
            if (!isset($this->attempts[strtolower($p->getName())]))
                $this->attempts[strtolower($p->getName())] = 1;
            $this->setAuthTimer($p);
            $p->sendMessage("\n§b► §fЧтобы зарегистрироваться, придумайте §bпароль §fи введите его в чат");
        }

    }

    public function onQuit(PlayerQuitEvent $e)
    {

        $e->setQuitMessage("");

        $p = $e->getPlayer();

        $this->players->reload();
        if (isset($this->players->getAll()[strtolower($p->getName())])) {
            $this->players->remove(strtolower($p->getName()));
            $this->players->save();
        }

        if (isset($this->users[strtolower($p->getName())]))
            unset($this->users[strtolower($p->getName())]);

        if (isset($this->register[strtolower($p->getName())]))
            unset($this->register[strtolower($p->getName())]);

        if (isset($this->auth_timeout[$p->getName()])) {
            $this->getServer()->getScheduler()->cancelTask($this->auth_timeout[$p->getName()]);
            unset($this->auth_timeout[$p->getName()]);
        }

        if ($p->hasEffect(15))
            $p->removeEffect(15);
    }

    /**
     *
     * @param PlayerCommandPreprocessEvent $e
     * @priority LOWEST
     *
     */
    public function onCommandPreprocess(PlayerCommandPreprocessEvent $e): bool
    {

        $message = $e->getMessage();

        $p = $e->getPlayer();
        $nickname = strtolower($p->getName());
        $address = $p->getAddress();

        $login_message = "\n§b► §fПожалуйста, авторизуйтесь, введя §bпароль §fв чат \n" .
            "§6! §fЕсли вы зашли впервые, то §6смените §fникнейм на любой другой";

        if (!isset($this->users[$nickname]) || (isset($this->users[$nickname]) && $this->users[$nickname]["ip"] !== $address)) {

            $e->setCancelled(true);

            if (!$this->getMySQLconnection()) {
                $p->sendMessage("§c► §fПроизошла ошибка при подключении к базе данных");
                return true;
            }

            if (count(explode("/", $message)) > 1) {
                $p->sendMessage($login_message);
                $e->setCancelled();
                return true;
            }

            $password = explode(" ", $message)[0];

            $result = $this->getUser($nickname);

            if (isset($result["password"]) && $result["password"] != null) {

                $password_hash = $this->passwordToHash($password);
                if ($password_hash === $result["password"]) {
                    $this->users[$nickname] = ["ip" => $address];
                    $this->setUserAuthInfo($nickname, $address, $p->getDeviceModel(), $this->getServer()->getPort(), time());
                    $p->setImmobile(false);
                    unset($this->attempts[$nickname]);
                    $this->successfulAuth($p);
                    $p->sendMessage("\n§a► §fВы §aуспешно §fавторизовались, приятной игры!");
                    $p->sendMessage("§6! §fВы так же можете поставить защиту, дабы обезопасить ваш аккаунт §7- §6/mb [skin-on|uuid-on]\n");
                } else {
                    if (isset($this->attempts[$nickname])) {
                        $this->attempts[$nickname]++;
                    } else {
                        return true;
                    }
                    if ($this->attempts[$nickname] < 5) {
                        $message = "§c► §fВы ввели неверный пароль";
                        if ($this->attempts[$nickname] < 4)
                            $message .= ", осталось §c" . ((int)5 - $this->attempts[$nickname]) . " §fпопытки";
                        else
                            $message .= ", осталась §cпоследняя §fпопытка";
                        $p->sendMessage($message);
                    } else {
                        unset($this->attempts[$nickname]);
                        $p->close("Попытка взлома / подбора паролей", "§aAuth §8• §fВы использовали все попытки для ввода пароля");
                    }
                }

            } else {

                if (!isset($this->register[$nickname])) {
                    if (preg_match("/^[0-9a-zA-Zа-яА-Я.,!?@#$%^&*_]{6,24}$/", $password)) {
                        $this->register[$nickname] = $password;
                        $p->sendMessage("§b► §fВведите пароль в чат ещё раз");
                        return true;
                    }
                    $p->sendMessage("§c► §fПароль должен состоять из §7[§c6-24§7] §fсимволов и может включать в себя§7: \n §c» §fЛатиницу §7[§ca-z§7] | §fКириллицу §7[§cа-я§7] | §fЦифры §7[§c0-9§7] | §fСпец. символы §7[§c.,!?@#$%^&*_§7]");
                    return true;
                }

                if ($password === $this->register[$nickname]) {

                    $password_hash = $this->passwordToHash($password);
                    $this->users[$nickname] = ["ip" => $address];
                    $this->registerUser($nickname, $password_hash, $address, $p->getDeviceModel(), $this->getServer()->getPort(), time());
                    $p->setImmobile(false);
                    $this->successfulAuth($p);

                    if (!isset($result["nickname"])) {
                        $count = $this->getCountUsers();
                        $players = [];
                        foreach ($this->getServer()->getOnlinePlayers() as $pls)
                            if (isset($this->users[strtolower($pls->getName())]))
                                $players[] = $pls;
                        $this->getServer()->broadcastMessage("\n§c! §fНа сервере новый игрок §l§b" . $p->getName() . " §r§7- §fименно он становится §c" . $count . " игроком §fсервера\n", $players);
                    } else
                        $p->sendMessage("\n");

                    $p->sendMessage("§a► §fВы §aуспешно §fзарегистрировались, ваш пароль §7- §b" . $password);
                    $p->sendMessage("§6! §fВы так же можете поставить защиту, дабы обезопасить ваш аккаунт §7- §6/mb [skin-on|uuid-on]\n");

                } else {
                    unset($this->register[$nickname]);
                    $p->sendMessage("§c► §fПароли не совпадают, попробуйте еще раз");
                }
            }

        }
        return true;

    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args): bool
{
    switch(strtolower($command->getName())) {
        case "auth-kick":
            if ($sender->isOp()) {
                if (isset($args[0])) {
                    $target = $this->getServer()->getPlayerExact($args[0]);
                    if ($target instanceof Player) {
                        $target->close("Удаленное отключение от сервера", "§aAuth §8• §fВаш аккаунт был удаленно отключен от сервера");
                        $sender->sendMessage("§aAuth §8• §fИгрок §a" . strtolower($args[0]) . " §fбыл отключен от сервера");
                        return true;
                    }
                    $sender->sendMessage("§aAuth §8• §fИгрок §a" . strtolower($args[0]) . " §fне найден");
                } else {
                    $sender->sendMessage("§aAuth §8• §fИспользование §7- §a/auth-kick <name>");
                }
            } else {
                $sender->sendMessage("§aAuth §8• §fУ вас недостаточно прав");
            }
            break;
            
        case "auth-set-password":
            // Команда доступна только из консоли
            if(!($sender instanceof \pocketmine\command\ConsoleCommandSender)) {
                $sender->sendMessage("§cЭта команда доступна только из консоли сервера");
                return true;
            }
            
            // Проверка аргументов
            if(count($args) < 2) {
                $sender->sendMessage("§aAuth §8• §fИспользование §7- §a/auth-set-password <name> <newpass>");
                return true;
            }
            
            // Получаем данные
            $username = strtolower($args[0]);
            $newPassword = $args[1];
            
            // Проверяем подключение к БД
            if(!$this->getMySQLConnection()) {
                $sender->sendMessage("§cОшибка подключения к базе данных");
                return true;
            }
            
            // Ищем пользователя
            $userData = $this->getUser($username);
            if(empty($userData)) {
                $sender->sendMessage("§cИгрок §e" . $args[0] . " §cне найден в базе данных");
                return true;
            }
            
            // Обновляем пароль
            $hashedPassword = $this->passwordToHash($newPassword);
            $stmt = $this->data->prepare("UPDATE users SET password = ? WHERE nickname = ?");
            $stmt->bind_param("ss", $hashedPassword, $username);
            
            if($stmt->execute()) {
                $sender->sendMessage("§aПароль для §e" . $args[0] . " §aуспешно изменен!");
            } else {
                $sender->sendMessage("§cОшибка при обновлении пароля: " . $stmt->error);
            }
            
            $stmt->close();
            return true;
            break;
    }
    return true;
}

}
