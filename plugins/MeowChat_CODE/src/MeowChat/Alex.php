<?php

/**/

namespace MeowChat;

use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;

use pocketmine\Player;

use pocketmine\event\player\{
  PlayerJoinEvent,
  PlayerChatEvent};

use pocketmine\event\entity\EntityLevelChangeEvent;

use pocketmine\command\ConsoleCommandSender;

use pocketmine\utils\{
  TextFormat,
  Config
};
use ChangePrefix\PrefixManager;
use Listochek\Clans;

/* utils GroupChangedEvent **/
use _64FF00\PurePerms\event\PPGroupChangedEvent;
use function strtolower;

class Alex extends PluginBase implements Listener{
	
	public $config, $prefixes, $purePerms, $factions, $server;
	
	
	function onEnable(){
	    @mkdir($this->getDataFolder());
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->config = new Config($this->getDataFolder() ."config.yml", Config::YAML);
		$this->purePerms = $this->getServer()->getPluginManager()->getPlugin("PurePerms");
		$this->factions = $this->getServer()->getPluginManager()->getPlugin("Clans");
		$this->PrefixManager = $this->getServer()->getPluginManager()->getPlugin("PrefixManager");
		
		if($this->purePerms !== null){
			$this->getLogger()->info("§aПлагин §dPurePerms §aнайден, включение плагина");
		}else{
			$this->getLogger()->error("§aПлагин §dPurePerms §cНЕ найден, §aвыключение плагина");
			$this->getServer()->getPluginManager()->disablePlugin($this);
		}
		
		if($this->PrefixManager !== null){
			$this->getLogger()->info("§aПлагин §dPrefixManager §aнайден, включение плагина");
		}else{
			$this->getLogger()->error("§aПлагин §dPrefixManager §cНЕ найден, §aвыключение плагина");
			$this->getServer()->getPluginManager()->disablePlugin($this);
		}
		
		if($this->factions !== null){
			$this->getLogger()->info("§aПлагин §bClans §aнайден");
		}else{
			$this->getLogger()->error("§aПлагин §bClans §aне найден, функционал кланов отключён!");
			$this->factions = null;
		}
		
		if(!is_dir($this->getDataFolder())){
			@mkdir($this->getDataFolder());
			$this->saveDefaultConfig();
		}
			$this->getServer()->getScheduler()->scheduleRepeatingTask(new UndefinedTask($this), 20);
	}
	
	
	function getPurePerms(){
		return $this->purePerms;
	}
	
	function getFactions(){
		return $this->factions;
	}
	
	function getData(){
	    return $this->getConfig()->getAll();
	}

	function onGroupChanged(PPGroupChangedEvent $event){
		$player = $event->getPlayer();
		if($player instanceof Player){
			$group = $event->getGroup()->getName();
			$nameTag = $this->getNameTag($player, $group);
			$displayName = $this->getDisplayName($player, $group);
			$player->setNameTag($nameTag);
			$player->setDisplayName($displayName);
		}
	}
	
	function getFaction(Player $player){
		$name = $player->getName();
		if($this->getFactions() !== null){
			$f = $this->getFactions();
			$clan = $f->getPlayerClan($name);
			return $clan !== null ? $clan : $this->getConfig()->get("ClanNone", "x");
		}
		return $this->getConfig()->get("ClanNone", "x");
	}
	
	function onJoin(PlayerJoinEvent $event){
		$player = $event->getPlayer();
		$data = $this->getConfig()->getAll();
		$group = $this->getPurePerms()->getUserDataMgr()->getGroup($player)->getName();
		if(!empty($data[$group])){
			$nameTag = $this->getNameTag($player, $group);
			$displayName = $this->getDisplayName($player, $group);
		}else{
			$player->sendMessage(" К сожалению, ваш чат не будет отображаться, поскольку в базе не заполнены форматы для группы §c". $group);
			$nameTag = "§8[§b". $group ."§8] §f". $player->getName();
			$displayName = "§b§l". $player->getName() ."§r";
		}
		$player->setNameTag($nameTag);
		$player->setDisplayName($displayName);
	}
	
	function onAlexChat(PlayerChatEvent $event) {
    $player = $event->getPlayer();
    $message = $event->getMessage();
    $group = $this->getPurePerms()->getUserDataMgr()->getGroup($player)->getName();
    $data = $this->getConfig()->getAll();
    $GlobalChatSymbol = $data["GlobalChatSymbol"];

    // Определяем тип чата
    $isGlobal = $message[0] === "$GlobalChatSymbol";
    $chatType = $isGlobal ? "global" : "local";

    // Убираем "!" из сообщения, если это глобальный чат
    if ($isGlobal) {
        $message = substr($message, 1);
    }

    // Форматируем сообщение чата с учетом префиксов и настроек
    $formattedMessage = $this->convertMessage($player, $message, $group, $chatType);

    // Локальный чат: сообщение отправляется только игрокам в пределах 120 блоков
    if ($chatType === "local") {
        $onlinePlayers = $this->getServer()->getOnlinePlayers();
        $data = $this->getConfig()->getAll();
        $LocalChatDistance = $data["LocalChatDistance"];

        // Если игрок один на сервере, отправляем ему сообщение, что его никто не слышит
        if (count($onlinePlayers) === 1) {
            $player->sendMessage("§c§lВас никто не услышал. §fВы один...");
            $event->setCancelled();
            return;
        }

        // Ищем игроков в пределах 120 блоков
        $recipients = [];
        foreach ($onlinePlayers as $p) {
            if ($player->distance($p) <= $LocalChatDistance) {
                $recipients[] = $p;
            }
        }

        // Если нет игроков в пределах слышимости, информируем об этом игрока
        if (empty($recipients)) {
            $player->sendMessage("");
            $event->setCancelled();
            return;
        }

        // Отправляем сообщение только в локальный чат
        foreach ($recipients as $recipient) {
            $recipient->sendMessage($formattedMessage);
        }
    } else {
        // Глобальный чат: сообщение отправляется всем игрокам
        foreach ($this->getServer()->getOnlinePlayers() as $p) {
            $p->sendMessage($formattedMessage);
        }
    }

    // Отменяем стандартное событие для предотвращения дублирования сообщений
    $event->setCancelled();
}

	/*
	* Тут заканчивается (почти код) Максима Коробченко
	*/
	
	function onLevelChange(EntityLevelChangeEvent $event){
		if($event->getEntity() instanceof Player){
			$player = $event->getEntity();
			$group = $this->getPurePerms()->getUserDataMgr()->getGroup($player)->getName();
			$nameTag = $this->getNameTag($player, $group);
			$displayName = $this->getDisplayName($player, $group);
			$player->setNameTag($nameTag);
			$player->setDisplayName($displayName);
		}
	}
	
	function getNameTag(Player $player, $group){
    $data = $this->getConfig()->getAll();
    $nameTag = $data[$group]["NameTagFormat"];
    
    // Получаем данные из PrefixManager
    $prefixManager = $this->getServer()->getPluginManager()->getPlugin("PrefixManager");
    $prefix = $prefixManager->getPlayerData($player->getName(), 'prefix') ?? '';
    $nickColor = $prefixManager->getPlayerData($player->getName(), 'nickColor') ?? 'f';
    $boldPrefix = $prefixManager->getPlayerData($player->getName(), 'boldPrefix') ? "§l" : "";
    $boldNick = $prefixManager->getPlayerData($player->getName(), 'boldNick') ? "§l" : "";
    $clanName = $this->getFaction($player);
    $nickname = $prefixManager->getPlayerData($player->getName(), 'fakeNick') ?? $player->getName();
    $Health = $this->convertHealth($player) . "§l§c❤";
    // Если префикс установлен, заменяем формат
    if (!empty($prefix)) {
        $nameTag = "§8о§7(§a{$clanName}§7) §r{$boldPrefix}{$prefix} §r{$boldNick}§{$nickColor}{$nickname}\n{$Health}";
    } else {
        // Обычная замена форматов
        $nameTag = str_replace("{NAME}", $nickname, $nameTag);
        $nameTag = str_replace("{CLAN}", $this->getFaction($player), $nameTag);
        $nameTag = str_replace("{NICK_COLOR}", $nickColor, $nameTag);
        $nameTag = str_replace("{BOLD_NICK}", $boldNick, $nameTag);
        $nameTag = str_replace("{FAC}", $this->getFaction($player), $nameTag);
        $nameTag = str_replace("{WORLD}", $player->getLevel()->getName(), $nameTag);
        $nameTag = str_replace("{HEALTH}", $this->convertHealth($player), $nameTag);
        $nameTag = str_replace("{MAXHEALTH}", $player->getMaxHealth(), $nameTag);
        $nameTag = str_replace("{DISPLAY_NAME}", $player->getDisplayName(), $nameTag);
        $nameTag = str_replace("{REPEAT}", $this->getRepeating($player), $nameTag);
    }

    return $nameTag;
}

function getDisplayName(Player $player, $group){
    $data = $this->getConfig()->getAll();
    $displayName = $data[$group]["DisplayFormat"] ?? $data[$group]["NameTagFormat"];
    
    // Получаем данные из PrefixManager
    $prefixManager = $this->getServer()->getPluginManager()->getPlugin("PrefixManager");
    $prefix = $prefixManager->getPlayerData($player->getName(), 'prefix') ?? '';
    $nickColor = $prefixManager->getPlayerData($player->getName(), 'nickColor') ?? 'f';
    $boldNick = $prefixManager->getPlayerData($player->getName(), 'boldNick') ? "§l" : "";
    $boldPrefix = $prefixManager->getPlayerData($player->getName(), 'boldPrefix') ? "§l" : "";
    $clanName = $this->getFaction($player);
    $nickname = $prefixManager->getPlayerData($player->getName(), 'fakeNick') ?? $player->getName();

    // Если префикс установлен, заменяем формат
    if (!empty($prefix)) {
        $displayName = "§7(§a{$clanName}§7)§r {$boldPrefix}{$prefix} §r§{$nickColor}{$nickname}";
    } else {
        // Обычная замена форматов
        $displayName = str_replace("{NAME}", $nickname, $displayName);
        $displayName = str_replace("{NICK_COLOR}", $nickColor, $displayName);
        $displayName = str_replace("{BOLD_NICK}", $boldNick, $displayName);
        $displayName = str_replace("{CLAN}", $this->getFaction($player), $displayName);
        $displayName = str_replace("{WORLD}", $player->getLevel()->getName(), $displayName);
    }

    return $displayName;
}

function convertMessage(Player $player, $message, $group, $chatType) {
    $data = $this->getConfig()->getAll();
    $chatFormat = $data[$group]["ChatFormat"];
    
    // Получаем данные из PrefixManager
    $prefixManager = $this->getServer()->getPluginManager()->getPlugin("PrefixManager");
    $prefix = $prefixManager->getPlayerData($player->getName(), 'prefix') ?? '';
    $nickColor = $prefixManager->getPlayerData($player->getName(), 'nickColor') ?? 'f';
    $chatColor = $prefixManager->getPlayerData($player->getName(), 'chatColor') ?? '7';
    $boldPrefix = $prefixManager->getPlayerData($player->getName(), 'boldPrefix') ? "§l" : "";
    $boldNick = $prefixManager->getPlayerData($player->getName(), 'boldNick') ? "§l" : "";
    $boldChat = $prefixManager->getPlayerData($player->getName(), 'boldChat') ? "§l" : "";
    $clanName = $this->getFaction($player);
    $nickname = $prefixManager->getPlayerData($player->getName(), 'fakeNick') ?? $player->getName();

    // Определяем префикс типа чата
    $chatPrefix = $chatType === "global" ? "§l§cГлобал" : "§l§7Локал-" . $this->calculateLocalDistance($player);

    // Если у игрока установлен префикс
    if (!empty($prefix)) {
        $chatFormat = "{$chatPrefix}§r§7 |§r §7(§a{$clanName}§7) §r{$boldPrefix}{$prefix} §r{$boldNick}§$nickColor{$nickname} §r§6>> §r{$boldChat}§$chatColor{$message}";
    } else {
        // Стандартный формат без префиксов
        $chatFormat = str_replace("{NAME}", $nickname, $chatFormat);
        $chatFormat = str_replace("{CHAT_COLOR}", $chatColor, $chatFormat);
        $chatFormat = str_replace("{BOLD_CHAT}", $boldChat, $chatFormat);
        $chatFormat = str_replace("{NICK_COLOR}", $nickColor, $chatFormat);
        $chatFormat = str_replace("{BOLD_NICK}", $boldNick, $chatFormat);
        $chatFormat = str_replace("{MSG}", $message, $chatFormat);
        $chatFormat = str_replace("{CLAN}", $clanName, $chatFormat);
        $chatFormat = str_replace("{WORLD}", $player->getLevel()->getName(), $chatFormat);
        $chatFormat = "{$chatPrefix}§r§l§7 |§r " . $chatFormat;
    }

    return $chatFormat;
}

	function removeColors($message){
        $message = str_replace(TextFormat::BLACK, "", $message);
        $message = str_replace(TextFormat::DARK_BLUE, "", $message);
        $message = str_replace(TextFormat::DARK_GREEN, "", $message);
        $message = str_replace(TextFormat::DARK_AQUA, "", $message);
        $message = str_replace(TextFormat::DARK_RED, "", $message);
        $message = str_replace(TextFormat::DARK_PURPLE, "", $message);
        $message = str_replace(TextFormat::GOLD, "", $message);
        $message = str_replace(TextFormat::GRAY, "", $message);
        $message = str_replace(TextFormat::DARK_GRAY, "", $message);
        $message = str_replace(TextFormat::BLUE, "", $message);
        $message = str_replace(TextFormat::GREEN, "", $message);
        $message = str_replace(TextFormat::AQUA, "", $message);
        $message = str_replace(TextFormat::RED, "", $message);
        $message = str_replace(TextFormat::LIGHT_PURPLE, "", $message);
        $message = str_replace(TextFormat::YELLOW, "", $message);
        $message = str_replace(TextFormat::WHITE, "", $message);

        $message = str_replace(TextFormat::OBFUSCATED, "", $message);
        $message = str_replace(TextFormat::BOLD, "", $message);
        $message = str_replace(TextFormat::ITALIC, "", $message);
        $message = str_replace(TextFormat::RESET, "", $message);
        return $message;
    }
    
	function convertHealth(Player $player){
		$format = $this->getConfig()->get("HealthFormat");
		$animationEnabled = $this->getConfig()->get("HealthAnimation");
		if($format == "points" || $format == true){
			$health = $player->isSurvival() ? $player->getHealth() : $player->getMaxHealth();
		}elseif($format == "hearts" || $format == false){
			$health = $player->isSurvival() ? (int)$player->getHealth() / 2 : $player->getMaxHealth();
		}else{
			$health = $player->isSurvival() ? $player->getHealth() : $player->getMaxHealth();
		}
		if($animationEnabled){
			if($player->getMaxHealth() == 0x14){
				if($health >= 15 && $health <= 0x14){
					$health = "§a". $health;
				}elseif($health >= 10 && $health <= 14){
					$health = "§e". $health;
				}elseif($health >= 5 && $health <= 9){
					$health = "§c". $health;
				}else{
					$health = "§l§c". $health . TextFormat::RESET;
				}
			}elseif($player->getMaxHealth() == 0x28){
				if($health >= 30 && $health <= 0x28){
					$health = "§a". $health;
				}elseif($health >= 20 && $health <= 29){
					$health = "§e". $health;
				}elseif($health >= 10 && $health <= 19){
					$health = "§c". $health;
				}else{
					$health = "§l§c". $health . TextFormat::RESET;
				}
			}
		}else{
			$health = TextFormat::RESET . $health;
		}
		return $health;
	}
	
	function getRepeating(Player $player){
		$group = $this->getPurePerms()->getUserDataMgr()->getGroup($player)->getName();
		$db = $this->getConfig()->getAll();
		$format = $db[$group]["NameTagFormat"];
		$format = explode("\n", $format)[0];
		$format = TextFormat::clean($format, true);
		$num = strlen($format >= 14) ? 3.44 : 6.77;
		$int = (int)strlen($format) / $num;
		$repeat = "";
		for($i = 0; $i <= $int; $i++){
			$repeat .= " ";
		}
		return $repeat;
	}
	
	/* Функция для вычисления расстояния до * ближайшего игрока для локального чата 
*/
    function calculateLocalDistance(Player $player) {
    $nearestDistance = PHP_INT_MAX;
    $hasNearbyPlayers = false;
    $data = $this->getConfig()->getAll();
    $LocalChatDistance = $data["LocalChatDistance"];
    
    foreach ($this->getServer()->getOnlinePlayers() as $p) {
        if ($p !== $player) {
            $distance = $player->distance($p);
            if ($distance < $LocalChatDistance) {
                $hasNearbyPlayers = true; // Есть игроки в радиусе 120 блоков
                if ($distance < $nearestDistance) {
                    $nearestDistance = $distance;
                }
            }
        }
    }
    
    // Если нет игроков в пределах 120 блоков, отправляем сообщение и возвращаем "Л-120" как максимальный диапазон
    if (!$hasNearbyPlayers) {
        $player->sendMessage("§c§lВас никто не услышал.\n§fЧтобы отправить сообщение в глобал, используйте \"!сообщение\"");
        return $LocalChatDistance; // Возвращаем максимальное значение для отображения "Л-120"
    }

    // Округляем расстояние до целого числа
    return min((int)round($nearestDistance), $LocalChatDistance);
}

}