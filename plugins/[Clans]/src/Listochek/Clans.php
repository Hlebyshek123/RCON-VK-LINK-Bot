<?php

namespace Listochek;

use Listochek\events\EventListener;
use Listochek\tasks\UpdateTask;
use Listochek\utils\PocketmineConfig as Config;
use pocketmine\command\{Command, CommandSender, ConsoleCommandSender};
use pocketmine\level\particle\FloatingTextParticle;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\Plugin;
use pocketmine\utils\TextFormat;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;
use Richen\Economy\Economy;

/**
 * Class Clans
 * @package Listochek
 */
class Clans extends PluginBase {

    /**
     * @var int
     */
    const TIME = 5;    //Частота обновления топа (в минутах)

    /**
     * @var array
     */
    public $data = [];

    /**
     * @var array
     */
    public $invites = [];

    /**
     * @var Economy
     */
    public $eco;

    /**
     * @var FloatingTextParticle
     */
    public $particle;

    public function onEnable()
    {
        @mkdir($this->getDataFolder());
        @mkdir($this->getClansPath());

        $this->tryConvertOldData();

        $this->loadClans();

        $this->eco = $this->getServer()->getPluginManager()->getPlugin("Economy");
        $this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);
        $this->getServer()->getScheduler()->scheduleRepeatingTask(new UpdateTask($this), self::TIME * 60 * 20);
        $this->particle = new FloatingTextParticle(new Vector3(64, 78, -12), $this->getTopList());
        $this->getLogger()->info("Плагин Clans был успешно включен!");
        
        if($this->eco !== null){
			$this->getLogger()->info("§aПлагин §dEconomy §aнайден, включение плагина");
		}else{
			$this->getLogger()->error("§aПлагин §dEconomy §cНЕ найден, §aвыключение плагина");
			$this->getServer()->getPluginManager()->disablePlugin($this);
		}
    }

    private function loadClans()
    {
        $path = $this->getClansPath();
        $Directory = new RecursiveDirectoryIterator($path);
        $Iterator = new RecursiveIteratorIterator($Directory);
        $Regex = new RegexIterator($Iterator, '/^.+\.json$/i', RecursiveRegexIterator::GET_MATCH);

        $c = 0;
        foreach ($Regex as $key => $value) {
            $cfgData = (new Config($key))->getAll();
            if(empty($cfgData)){
                $this->getLogger()->warning("Конфиг $key - пуст");
                continue;
            }
            $fileName = pathinfo($key)['filename'];
            $cfgData['filename'] = $fileName;

            $clanName = $cfgData['cname'];

            unset($cfgData['cname']);

            $this->data[$clanName] = $cfgData;
            $c++;
        }

        $this->getLogger()->notice("Загружено $c кланов");
    }

    private function getClansPath()
    {
        return $this->getDataFolder() . "Clans/";
    }

    /**
     * @return 
     */
    public function getTopList()
    {
        $clans = [];
        foreach ($this->data as $cname => $info) {
            $clans[$cname] = $this->getKillsClan($cname);
        }
        arsort($clans);
        $top = "§l§bТ§aО§eП§f кланов по очкам§r";
        $count = 0;
        foreach ($clans as $cname => $kills) {
            $count++;
            $top .= "\n§l§7" . $count . " §eместо - §b§l" . $this->data[$cname]["original"] . " §r§f-§l§d " . $kills . " §fочков";
            if ($count == 5) {
                break;
            }
        }
        return $top;
    }

    /**
     * @param  $cname
     * @return int
     */
    public function getKillsClan($cname)
    {
        $kills = 0;
        foreach ($this->data[$cname]["players"] as $name => $info) {
            $kills += $info[0];
        }
        return $kills;
    }

    public function onDisable()
    {
        $path = $this->getClansPath();
        $c = 0;
        foreach ($this->data as $cname => $data) {
            $fileName = $data['filename'];
            unset($data['filename']);

            $data['cname'] = $cname;
            $cfg = new Config($path . $fileName . ".json");
            $cfg->setAll($data);
            $cfg->save();
            $c++;
        }
        $this->getLogger()->notice("$c кланов сохранено");
    }

    public function updateTop()
    {
        $this->particle->setText($this->getTopList());
        $level = $this->getServer()->getDefaultLevel();
        $level->addParticle($this->particle, $level->getPlayers());
    }

    /**
     * @param  $name
     */
    public function addKill($name)
    {
        if (($cname = $this->getPlayerClan($name)) == null) {
            return;
        }
        $this->data[$cname]["players"][$name][0] += 1;
    }

    /**
     * @param  $name
     * @return int||null
     */
    public function getPlayerClan($name)
    {
        foreach ($this->data as $cname => $info) {
            if (isset($info["players"][$name])) {
                return $cname;
            }
        }
        return null;
    }
    
    public function dmg(EntityDamageEvent $e){
        
    }

    /**
     * @param  $name
     * @return 
     */
    public function getPrefix($name)
    {
        if (($cname = $this->getPlayerClan($name)) !== null) {
            return $this->data[$cname]["original"];
        }
        return "";
    }

    /**
     * @param CommandSender $player
     * @param Command $cmd
     * @param  $label
     * @param array $args
     * @return bool
     */
    public function onCommand(CommandSender $player, Command $cmd, $label, array $args){

        if (!isset($args[0])) {
            $this->sendHelp($player);
            return true;
        }
        if($args[0] === "claninfo"){
            if(!$player->isOp()){
                $this->sendHelp($player);
                return true;
            }
            if(!isset($args[1])){
                $player->sendMessage("§l§d⎢ §a/claninfo §a<название клана>");
                return true;
            }
            $cname = $args[1];
            if(!isset($this->data[$cname])){
                $player->sendMessage("§l§d⎢ §7Неизвестный клан§a $cname");
                return true;
            }
            $data = $this->data[$cname];
            $player->sendMessage("§l§d⎢ §7Клан§a $cname");
            $player->sendMessage(print_r($data, true));
            return true;
        }else{
            if (!$player instanceof Player) {
                $player->sendMessage("§l§d⎢ §7Команда вводиться только в игре!");
                if($player->isOp()){
                    $player->sendMessage("§l§d⎢ §7Доступно: /c claninfo");
                }
                return true;
            }
            switch ($args[0]){
                case "create":
                    $name = $player->getName();
                    if ($this->getPlayerClan($name) !== null) {
                        $player->sendMessage("§l§d⎢ §7Вы уже состоите в §a§aклане, для создания нового - выйдите со старого");
                        return true;
                    }

                    if (!preg_match("#^[aA-zZАа-яЯ0-9\§_]+$#", $args[1])) {
                        $player->sendMessage("§l§d⎢ §7В название запрещено использовать §aсимволы.");
                        return true;
                    }

                    if (!isset($args[1])) {
                        $player->sendMessage("§l§d⎢ §7Вы не ввели название клана, вводите §a/c create <название>");
                        return true;
                    }
                    $cname = $args[1];
                    if (strlen($cname) > 15) {
                        $player->sendMessage("§l§d⎢ §7Максимальная длина названия клана - §a15§7 символов");
                        return true;
                    }
                    $cname = $args[1];
                    if (strlen($cname) < 3) {
                        $player->sendMessage("§l§d⎢ §7Минимальная длина названия клана - §a3§7 символа");
                        return true;
                    }
                    if (isset($this->data[$cname])) {
                        $player->sendMessage("§l§d⎢ §7Клан с таким названием уже существует");
                        return true;
                    }
                    if ($this->eco->myMoney($player->getName()) >= 5000) {
                        $path = $this->getClansPath();
                        do {
                            $newFileName = $this->rd();
                        } while (file_exists($path . $newFileName));

                        $this->eco->remMoney($player->getName(), 5000);
                        $this->data[$cname] = [
                            "owner" => $name,
                            "players" => [
                                $name => [0, 0]
                            ],
                            "original" => $cname,
                            "description" => "§l§d⎢ §7Уютный клан с §aлучшими игроками сервера",
                            "money" => 0,
                            "home" => [
                                "x" => -272,
                                "y" => 65,
                                "z" => -159,
                                "yaw" => 0,
                                "pitch" => 0
                            ],
                            'filename' => $newFileName
                        ];
                        $player->sendMessage("§l§d⎢ §7Клан успешно §aсоздан");
                        $this->getServer()->broadcastMessage("§l§d⎢ §7На сервере создан §aновый клан §7- §a" . $cname . "§7, создатель §7-§a " . $player->getName());
                    } else {
                        $player->sendMessage("§l§d⎢ §7Стоимость создания клана - §a5000§6 монет");
                    }
                    break;
                case "delete":
                    $name = $player->getName();
                    if (($cname = $this->getPlayerClan($name)) == null) {
                        $player->sendMessage("§l§d⎢ §7Вы не в §a§aклане");
                        return true;
                    }
                    if (!$this->isOwner($name)) {
                        $player->sendMessage("§l§d⎢ §7Вы не владелец §aклана");
                        return true;
                    }
                    $player->sendMessage("§l§d⎢ §7Клан успешно §aудален");
                    $this->getServer()->broadcastMessage("§l§d⎢ §7Распался клан §a" . $this->data[$cname]["original"]);
                    $fileName = $this->data[$cname]['filename'];
                    unset($this->data[$cname]);
                    unlink($this->getClansPath() . $fileName . ".json");
                    break;
                case "newowner":
                    $name = $player->getName();
                    if (($cname = $this->getPlayerClan($name)) == null) {
                        $player->sendMessage("§l§d⎢ §7Вы не в §aклане");
                        return true;
                    }
                    if (!$this->isOwner($name)) {
                        $player->sendMessage("§l§d⎢ §7Вы не владелец клана");
                        return true;
                    }
                    if (!isset($args[1])) {
                        $player->sendMessage("§l§d⎢ §7Вы не ввели ник игрока которому хотите передать права владения гильдией, вводите §a/c newowner <ник>");
                        return true;
                    }
                    $oname = strtolower($args[1]);
                    if ($oname == $name) {
                        $player->sendMessage("§l§d⎢ §7Вы не можете передать клан себе-же");
                        return true;
                    }
                    if (!isset($this->data[$cname]["players"][$oname])) {
                        $player->sendMessage("§l§d⎢ §7Вы должны быть в одном §aклане§7 с игроком которому хотите передать его во владение");
                        return true;
                    }
                    $this->data[$cname]["owner"] = $oname;
                    $this->sendMessageClan($cname, "§l§d⎢ §7У нас новый владелец §7-§a " . $args[1]);
                    $player->sendMessage("§l§d⎢ §7Вы успешно передали клан во владение игроку§a " . $args[1]);
                    break;
                case "description":
                    $name = $player->getName();
                    if (($cname = $this->getPlayerClan($name)) == null) {
                        $player->sendMessage("§l§d⎢ §7Вы не в §aклане");
                        return true;
                    }
                    if (!$this->isOwner($name)) {
                        $player->sendMessage("§l§d⎢ §7Вы не владелец §aклана");
                        return true;
                    }
                    if (!isset($args[1])) {
                        $player->sendMessage("§l§d⎢ §7Вы не ввели описание, вводите /c description <описание>");
                        return true;
                    }
                    unset($args[0]);
                    $description = implode(" ", $args);
                    $this->data[$cname]["description"] = $description;
                    $player->sendMessage("§l§d⎢ §7Вы успешно сменили описание §aклана");
                    break;
                case "rename":
                    $name = $player->getName();
                    if (($cname = $this->getPlayerClan($name)) == null) {
                        $player->sendMessage("§l§d⎢ §7Вы не в §aклане");
                        return true;
                    }
                    if (!$this->isOwner($name)) {
                        $player->sendMessage("§l§d⎢ §7Вы не владелец §aклана");
                        return true;
                    }
                    if (!isset($args[1])) {
                        $player->sendMessage("§l§d⎢ §7Вы не ввели новое название, вводите §a/c rename <name>");
                        return true;
                    }
                    if ($this->data[$cname]["money"] < 5000) {
                        $player->sendMessage("§l§d⎢ §7Стоимость переименования клана - §a5000 монет в его казне");
                        return true;
                    }
                    $ncname = $args[1];
                    if (strlen(TextFormat::clean($ncname)) > 15) {
                        $player->sendMessage("§l§d⎢ §7Максимальная длина названия клана - §a20 символов");
                        return true;
                    }
                    $ncname = $args[1];
                    if (strlen(TextFormat::clean($ncname)) < 3) {
                        $player->sendMessage("§l§d⎢ §7Минимальная длина названия клана - §a3§7 символа");
                        return true;
                    }
                    if (isset($this->data[strtolower(TextFormat::clean($cname))])) {
                        $player->sendMessage("§l§d⎢ §7Клан с таким названием уже §aсуществует");
                        return true;
                    }
                    $old = $this->data[$cname];
                    $old["original"] = $ncname;
                    $old["money"] -= 5000;
                    unset($this->data[$cname]);
                    $ncname = strtolower(TextFormat::clean($ncname));
                    $this->data[$ncname] = $old;
                    $this->sendMessageClan($ncname, "§l§d⎢ §7Наш клан §aпереименован!");
                    $player->sendMessage("§l§d⎢ §7Вы успешно сменили название клана");
                    break;
                case "pay":
                    $name = $player->getName();
                    if (($cname = $this->getPlayerClan($name)) == null) {
                        $player->sendMessage("§l§d⎢ §7Вы не в §aклане");
                        return true;
                    }
                    if (!isset($args[1])) {
                        $player->sendMessage("§l§d⎢ §7Вы не ввели сумму, вводите §a/c pay <сумма>");
                        return true;
                    }
                    if (!is_numeric($args[1]) or $args[1] < 0) {
                        $player->sendMessage("§l§d⎢ §7Сумма должна быть §aположительным§7 числом");
                        return true;
                    }
                    $sum = floor($args[1]);
                    if (($money = $this->eco->myMoney($name)) < $sum) {
                        $player->sendMessage("§l§d⎢ §7Вам не хватает §a" . ($sum - $money) . "§7 монет");
                        return true;
                    }
                    $this->eco->remMoney($name, $sum);
                    $this->data[$cname]["money"] += $sum;
                    $this->data[$cname]["players"][$name][1] += $sum;
                    $this->sendMessageClan($cname, "§l§d⎢ §7Игрок §a" . $player->getName() . " §7пожертвовал §a" . $sum . "§7 в казну клана");
                    $player->sendMessage("§l§d⎢ §7Вы успешно пожертвовали §a" . $sum . " в казну клана");
                    break;
                case "take":
                    $name = $player->getName();
                    if (($cname = $this->getPlayerClan($name)) == null) {
                        $player->sendMessage("§l§d⎢ §7Вы не в §aклане");
                        return true;
                    }
                    if (!$this->isOwner($name)) {
                        $player->sendMessage("§l§d⎢ §7Вы не владелец §aклана");
                        return true;
                    }
                    if (!isset($args[1])) {
                        $player->sendMessage("§l§d⎢ §7Вы не ввели сумму для вывода, вводите §a/c take <сумма>");
                        return true;
                    }
                    if (!is_numeric($args[1]) or $args[1] < 0) {
                        $player->sendMessage("§l§d⎢ §7Сумма должна быть положительным числом");
                        return true;
                    }
                    $sum = floor($args[1]);
                    if ($this->data[$cname]["money"] < $sum) {
                        $player->sendMessage("§l§d⎢ §7В казне клана только §a" . $this->data[$cname]["money"] . "§e монет");
                        return true;
                    }
                    $this->eco->addMoney($name, $sum);
                    $this->data[$cname]["money"] -= $sum;
                    $player->sendMessage("§l§d⎢ §7Вы успешно вывели §a" . $sum . "§7 с казны клана");
                    break;
                case "invite":
                    $name = $player->getName();
                    if (($cname = $this->getPlayerClan($name)) == null) {
                        $player->sendMessage("§l§d⎢ §7Вы не в §aклане");
                        return true;
                    }
                    if (!$this->isOwner($name)) {
                        $player->sendMessage("§l§d⎢ §7Вы не владелец клана");
                        return true;
                    }
                    if (count($this->data[$cname]["players"]) == 15) {
                        $player->sendMessage("§l§d⎢ §7Превышен лимит участников клана");
                        return true;
                    }
                    if (!isset($args[1])) {
                        $player->sendMessage("§l§d⎢ §7Вы не ввели ник для приглашения, вводите /c invite <name>");
                        return true;
                    }
                    if ($this->getPlayerClan(strtolower($args[1])) !== null) {
                        $player->sendMessage("§l§d⎢ §7Игрок уже в §aклане");
                        return true;
                    }
                    if (($iplayer = $this->getServer()->getPlayer($args[1])) == null) {
                        $player->sendMessage("§l§d⎢ §7Игрок §aоффлайн");
                        return true;
                    }
                    $iname = $iplayer->getName();
                    $this->invites[$iname] = ["time" => (time() + 60), "cname" => $cname];
                    $iplayer->sendMessage("§l§d⎢ §7Вас пригласили в клан§7 " . $this->data[$cname]["original"] . "§7, убийства клана §7-§a§l " . $this->getKillsClan($cname) . "§r§7, описание клана §7- §7" . $this->data[$cname]["description"] . "\n§7У вас §760§7 секунд на принятие запроса §7(§a/c accept§7)");
                    $player->sendMessage("§l§d⎢ §7Вы успешно пригласили игрока §7" . $iname . "§7 в клан");
                    break;
                case "accept":
                    $name = $player->getName();
                    if (!isset($this->invites[$name])) {
                        $player->sendMessage("§l§d⎢ §7У вас нету активных §aприглашений");
                        return true;
                    }
                    if ($this->invites[$name]["time"] < time()) {
                        unset($this->invites[$name]);
                        $player->sendMessage("§l§d⎢ §7У вас нету активных §aприглашений");
                        return true;
                    }
                    if (count($this->data[$this->invites[$name]["cname"]]["players"]) == 15) {
                        $player->sendMessage("§l§d⎢ §7Превышен §aлимит §7участников клана в который вы хотите вступить");
                        return true;
                    }
                    if ($this->getPlayerClan($name) !== null) {
                        $player->sendMessage("§l§d⎢ §7Вы уже в §aклане. Выйдите из него §7(§a/c leave§7)§7 для вступления в новый");
                        return true;
                    }
                    $cname = $this->invites[$name]["cname"];
                    unset($this->invites[$name]);
                    $this->sendMessageClan($cname, "§d§l⎢§a " . $player->getName() . " §7вступил в наш клан");
                    $this->data[$cname]["players"][$name] = [0, 0];
                    $player->sendMessage("§l§d⎢ §7Вы успешно §aвступили§7 в клан");
                    break;
                case "kick":
                    $name = $player->getName();
                    if (($cname = $this->getPlayerClan($name)) == null) {
                        $player->sendMessage("§l§d⎢ §7Вы не в §aклане");
                        return true;
                    }
                    if (!$this->isOwner($name)) {
                        $player->sendMessage("§l§d⎢ §7Вы не владелец §aклана");
                        return true;
                    }
                    if (!isset($args[1])) {
                        $player->sendMessage("§l§d⎢ §7Вы не ввели ник игрока которого хотите выгнать, вводите §a/c kick <ник>");
                        return true;
                    }
                    $kname = strtolower($args[1]);
                    if ($kname == $name) {
                        $player->sendMessage("§l§d⎢ §7Вы не можете выгнать §aсебя-же");
                        return true;
                    }
                    if (!isset($this->data[$cname]["players"][$kname])) {
                        $player->sendMessage("§l§d⎢ §7Игрок которого вы хотите выгнать не в вашем §aклане");
                        return true;
                    }
                    unset($this->data[$cname]["players"][$kname]);
                    $this->sendMessageClan($cname, "§l§d⎢ §7Игрока §7" . $args[1] . "§7 выгнали с клана");
                    $player->sendMessage("§l§d⎢ §7Вы успешно выгнали игрока §a" . $args[1] . "§7 с клана");
                    break;
                case "leave":
                    $name = $player->getName();
                    if (($cname = $this->getPlayerClan($name)) == null) {
                        $player->sendMessage("§l§d⎢ §7Вы не в §aклане");
                        return true;
                    }
                    if ($this->isOwner($name)) {
                        $player->sendMessage("§l§d⎢ §7Владелец клана не может его покинуть, удалите клан(/c delete), либо отдайте его другому игроку(/c newowner) и выйдите");
                        return true;
                    }
                    unset($this->data[$cname]["players"][$name]);
                    $this->sendMessageClan($cname, "§e§lКланы>§7 " . $player->getName() . "§7 покинул наш клан");
                    $player->sendMessage("§l§d⎢ §7Вы успешно вышли с клана");
                    break;
                case "chat":
                    $name = $player->getName();
                    if (($cname = $this->getPlayerClan($name)) == null) {
                        $player->sendMessage("§l§d⎢ §7Вы не в §aклане");
                        return true;
                    }
                    if (!isset($args[1])) {
                        $player->sendMessage("§l§d⎢ §7Вы не ввели сообщение, вводите §a/c chat <сообщений>");
                        return true;
                    }
                    unset($args[0]);
                    $message = "§e§lКлановый Чат>§a " . $player->getName() . ":§7 " . implode(" ", $args);
                    $this->sendMessageClan($cname, $message);
                    break;
                case "stats":
                    if (($cname = $this->getPlayerClan($player->getName())) == null) {
                        $player->sendMessage("§l§d⎢ §7Вы не в §aклане");
                        return true;
                    }
                    $player->sendMessage("§l§d⎢ §7Статистика клана " . $this->data[$cname]["original"] . "\n§a• §7Владелец:§d " . $this->data[$cname]["owner"] . "\n§a• §7Казна:§e " . $this->data[$cname]["money"] . "\n§a• §7Убийства:§7 " . $this->getKillsClan($cname) . "\n§a• §7Описание:§7 " . $this->data[$cname]["description"] . "\n§a• §7Участники§a (" . count($this->data[$cname]["players"]) . "/15)");
                    break;
                case "top":
                    $player->sendMessage($this->getTopList());
                    break;
                case "sethome":
                    $name = $player->getName();
                    if (($cname = $this->getPlayerClan($name)) == null) {
                        $player->sendMessage("§l§d⎢ §7Вы не в §aклане");
                        return true;
                    }
                    if (!$this->isOwner($name)) {
                        $player->sendMessage("§l§d⎢ §7Вы не владелец §aклана");
                        return true;
                    }
                    list($this->data[$cname]["home"]["x"], $this->data[$cname]["home"]["y"], $this->data[$cname]["home"]["z"], $this->data[$cname]["home"]["yaw"], $this->data[$cname]["home"]["pitch"]) = [$player->x, $player->y, $player->z, $player->yaw, $player->pitch];
                    $player->sendMessage("§l§d⎢ §7Вы успешно установили §aдом §7клана");
                    break;
                    case "info":
        // Проверим, указан ли игрок
        if (!isset($args[1])) {
            $player->sendMessage("§l§d⎢ §7Введите ник игрока, чтобы посмотреть информацию о его клане. Используйте §a/c info <ник>");
            return true;
        }

        // Получаем ник указанного игрока
        $targetName = $args[1];

        // Проверяем, находится ли указанный игрок в клане
        $targetClan = $this->getPlayerClan($targetName);
        if ($targetClan === null) {
            $player->sendMessage("§l§d⎢ §7Игрок §a" . $args[1] . " §7не состоит в клане");
            return true;
        }

        // Выводим информацию о клане указанного игрока
        $clanData = $this->data[$targetClan];
        $player->sendMessage("§l§d⎢ §7Информация о клане игрока§a " . $args[1] . ":\n"
            . "§a• §7Название клана: §d" . $clanData["original"] . "\n"
            . "§a• §7Владелец: §d" . $clanData["owner"] . "\n"
            . "§a• §7Казна: §e" . $clanData["money"] . " монет\n"
            . "§a• §7Убийства клана: §c" . $this->getKillsClan($targetClan) . "\n"
            . "§a• §7Описание: §7" . $clanData["description"] . "\n"
            . "§a• §dУчастники (" . count($clanData["players"]) . "/15)"
        );
        break;
                case "home":
                    $name = $player->getName();
                    if (($cname = $this->getPlayerClan($name)) == null) {
                        $player->sendMessage("§l§d⎢ §7Вы не в §aклане");
                        return true;
                    }
                    $pos = new Position($this->data[$cname]["home"]["x"], $this->data[$cname]["home"]["y"], $this->data[$cname]["home"]["z"]);
                    $player->teleport($pos, $this->data[$cname]["home"]["yaw"], $this->data[$cname]["home"]["pitch"]);
                    $player->sendMessage("§l§d⎢ §7Вы успешно телепортировались в §aдом§7 клана");
                    break;
                default:
                    $this->sendHelp($player);
                    break;
            }
        }
        return true;
    }

    /**
     * @param CommandSender $player
     */
    private function sendHelp(CommandSender $player)
    {

        $player->sendMessage(
            "§l§d⎢ §7Справка по командам:\n" .
            "§a/c invite §f<ник> §7-§e пригласить в клан §7(§aтолько для создателя клана§7)\n" .
            "§a/c accept §7- §eпринять приглашение в клан\n" .
            "§a/c leave §7-§e выйти с клана\n" .
            "§a/c kick §f<ник> §7-§e выгнать с клана §7(§aтолько для создателя клана§7)\n" .
            "§a/c sethome §7-§e установить точку дома клана §7(§aтолько для создателя клана§7)\n" .
            "§a/c home §7-§e телепортироваться на точку дома клана\n" .
            "§a/c pay §f<сумма> §7-§e вложить монеты в казну клана\n" .
            "§a/c take §f<сумма> §7-§e снять монеты с казны клана §7(§aтолько для создателя клана§7)\n" .
            "§a/c top §7-§e §l§cтоп 5 §eкланов по киллам\n" .
            "§a/c delete §7-§e удалить клан §7(§aтолько для создателя клана§7)\n" .
            "§a/c newowner §f<ник> §7-§e передать клан другому его участнику §7(§aтолько для создателя клана§7)\n" .
            "§a/c description §f<описание> §7-§e установить описание клана §7(§aтолько для создателя клана§7)\n" .
            "§a/c rename §f<название> §7-§e переименовать клан §7(§dцена - §e5000 монет, §eтолько для §fсоздателя клана§7)\n" .
            "§a/c chat §f<сообщение> §7-§e написать сообщение в чат клана\n" .
            "§a/c stats §7-§e статистика вашего клана\n" .
            "§a/c create §f<название> §7-§e создать клан цена §e5000\n" .
            "§a/c info §f[ник игрока] §7-§e посмотреть статистику клана игрока\n" . 
            "§a/c friendlyfire §f(enable/disable) §7-§e включить/выключить 'огонь по своим' в клане §a(только для создателя)"
        );
        if($player->isOp()){
            $player->sendMessage("§l§d⎢ §7/c claninfo §7<§7название§7> §7-§a инфа о §aклане и файле хранения");
        }
    }

    /**
     * @param  $name
     * @return bool
     */
    public function isOwner($name)
    {
        $cname = $this->getPlayerClan($name);
        if ($this->data[$cname]["owner"] == $name) {
            return true;
        }
        return false;
    }

    /**
     * @param  $cname
     * @param  $message
     */
    public function sendMessageClan($cname, $message)
    {
        foreach ($this->data[$cname]["players"] as $name => $pdata) {
            if (($player = $this->getServer()->getPlayer($name)) != null) {
                $player->sendMessage($message);
            }
        }
    }

    private function rd($length = 15)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $random = '';
        for ($i = 0; $i < $length; $i++) {
            $random .= $characters[rand(0, $charactersLength - 1)];
        }
        return $random;
    }

    private function tryConvertOldData()
    {
        if(file_exists($this->getDataFolder() . "data.json")){
            $oldCfg = new Config($this->getDataFolder() . "data.json");
            $oldData = $oldCfg->getAll();
            $convert = 0;
            $path = $this->getClansPath();
            foreach ($oldData as $clanName => $clanData){
                do{
                    $newFileName = $this->rd();
                }while(file_exists($path . $newFileName . ".json"));

                $clanData['cname'] = $clanName;

                $newConfig = new Config($path . $newFileName . ".json", Config::JSON, $clanData);

                $newConfig->save();

                $oldCfg->remove($clanName);
                $convert++;
            }
            $this->getLogger()->warning("Старые данные пересены в новый формат: $convert кланов");
            $oldCfg->save();
        }
    }
}