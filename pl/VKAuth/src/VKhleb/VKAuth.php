<?php

namespace VKhleb;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\Player;
use pocketmine\event\server\DataPacketReceiveEvent;

class VKAuth extends PluginBase implements Listener {

	public $status = array();

	function onEnable(){

		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		
		$this->getLogger()->info("VKauth успешно запущен! рафаэль пидор");
		

		$this->auth = new \SQLite3($this->getDataFolder() .'auth.db');
		$this->auth->query("CREATE TABLE IF NOT EXISTS auth(id INTEGER PRIMARY KEY, name TEXT NOT NULL, password TEXT NOT NULL, ip TEXT NOT NULL, os TEXT NOT NULL, device TEXT NOT NULL);");
	}
	
	public function onPacketReceived(DataPacketReceiveEvent $e) {
		if($e->getPacket() instanceof \pocketmine\network\mcpe\protocol\LoginPacket) {
			if($e->getPacket()->clientData["DeviceOS"] !== null) {
				$this->os[$e->getPacket()->username]=$e->getPacket()->clientData["DeviceOS"];
				$this->device[$e->getPacket()->username]=$e->getPacket()->clientData["DeviceModel"];
			}

		}

	}

	public function getUos(Player $player) {
		if(!isset($this->os[$player->getName()])) return 404;
		if($this->os[$player->getName()] == null) return 404;
		$hirss = $this->os[$player->getName()];

		if(is_int($hirss)) return $this->translateVersion($hirss);
		else return $hirss;
	}
	public function getUsd(Player $player){
		if(!isset($this->device[$player->getName()])) return 404;
		if($this->device[$player->getName()] == null) return 404;
		return $this->device[$player->getName()];
	}

	public function translateVersion($fdp){
		switch($fdp){
		case 1:
			$akha = "Android"; // это обычный Android
		break;
		case 2:
			$akha = "IOS"; // Телефоны Apple
		break;
		case 3:
			$akha = "MacOS"; // Компьютеры и ноутбуки Apple
		break;
		case 4:
			$akha = "FireOS"; // Операционная система от Amazon, почти не используется, но всё же она есть
		break;
		case 5:
			$akha = "GearVR"; // Тоже мало используемая операционная система VR
		break;
		case 6:
			$akha = "Hololens"; // Чесно хз что это
		break;
		case 7:
			$akha = "Windows 10"; // Windows 10
		break;
		case 8:
			$akha = "Windows 32,Educal_version"; // Обучающее издание
		break;
		case 9:
			$akha = "NoName"; #If you have the Name of that send me a mp
		break;
		case 10:
			$akha = "Playstation 4"; // Тут понятно
		break;
		case 11:
			$akha = "NX"; #NX no name... wollah c vrai
		break;

		default:
			$akha = "Not Registered!"; // если я пропустил
		break;
		}
		return $akha;
	}
	
	function onPreLogin(\pocketmine\event\player\PlayerPreLoginEvent $ev){

		$name = strtolower($ev->getPlayer()->getName());
		
		$player = $ev->getPlayer();
		
		$device = $this->getUsd($player);
		
		$os = $this->getUos($player);

		if(!$this->auth->query("SELECT * FROM auth WHERE name = '$name'")->fetchArray(SQLITE3_ASSOC)){

			$this->auth->query("INSERT INTO auth(name, password, ip, os, device) VALUES ('$name', 'none', 'none', '$os', '$device');");
		}
	}

	function setItems($p){

        // здесь указываем какие предметы выдаются после авторизации, хотя можно и что то другое, это просто функция которая выполнчется после авторизации.
        
	}

	function getData($player, $entry){

		$username = strtolower($player->getName());

		$result = $this->auth->query("SELECT `$entry` FROM `auth` WHERE `name` = '$username'")->fetchArray(SQLITE3_ASSOC);

		return $result[$entry];
	}
	
	function getAuthStatus($p){

		$name = strtolower($p->getName()); return $this->status[$name];
	}
	
	function setAuthStatus($p, $status){

		$name = strtolower($p->getName()); $this->status[$name] = $status;
	}
	
	function setIp($p){

		$name = strtolower($p->getName()); $ip = $p->getAddress();

		$this->auth->query("UPDATE `auth` SET `ip` = '$ip' WHERE `name` = '$name'");
	}
	
	function onJoin(\pocketmine\event\player\PlayerJoinEvent $ev){

		$p = $ev->getPlayer(); $name = strtolower($ev->getPlayer()->getName());

		if($this->getData($p, 'password') == "none"){

			$this->setAuthStatus($p, "register"); $p->setImmobile();

			$p->sendMessage("§l§7┃ §eДобро пожаловать на §l§6FallCraft§e PE§r\n§7┃ §r§bГруппа ВКонтакте §8- §cvk.com/fallcraft_pe\n§l§7┃ §r§eСайт авто-доната §8- §6shop.fallcraft.ru\n\n§7> §eПрочитать §aправила§e сервера можно командой§8: §6/rules\n§7> §eПрочитать §bинформацию§e про донат можно тут§8: §a/donate\n
§7> §l§aЗарегиструйся, §l§7введя любой §l§eпароль §l§7в §l§eчат§l§7! (p.s. его никто не увидит)");
			

			

		} else {

			if($this->getData($p, 'ip') == $p->getAddress()){

				$this->setAuthStatus($p, "game"); $p->setImmobile(false);

				$p->sendMessage("§l§7┃ §eДобро пожаловать на §l§6FallCraft§e PE§r\n§7┃ §r§bГруппа ВКонтакте §8- §cvk.com/fallcraft_pe\n§l§7┃ §r§eСайт авто-доната §8- §6shop.fallcraft.ru\n\n§7> §eПрочитать §aправила§e сервера можно командой§8: §6/rules\n§7> §eПрочитать §bинформацию§e про донат можно тут§8: §a/donate\n\n§f> §l§7Вы §l§aуспешно §l§7 вошли в свой §l§eаккаунт§l§7!\n§7> §l§7Привязка §l§eаккаунта к §l§fВ§l§bK §l§f/vkcode");

				$this->addSound($p, 47);
				$this->addGuardian($p);

				$this->setItems($p);

			} else {

				$this->setAuthStatus($p, "auth"); $p->setImmobile();

				$p->sendMessage("§l§7┃ §eДобро пожаловать на §l§6FallCraft§e PE§r\n§7┃ §r§bГруппа ВКонтакте §8- §cvk.com/fallcraft_pe\n§l§7┃ §r§eСайт авто-доната §8- §6ashop.fallcraft.ru\n\n§7> §eПрочитать §aправила§e сервера можно командой§8: §6/rules\n§7> §eПрочитать §bинформацию§e про донат можно тут§8: §a/donate\n\n§f> §l§7Чтобы §l§aавторизоваться §l§7введи свой §l§eпароль §l§7в чат! ");
				

				
			}
		}
	}
	
	public function addGuardian($p){

		$pk = new \pocketmine\network\mcpe\protocol\LevelEventPacket();

		$pk->evid = 2006;

		$pk->x = $p->x; $pk->y = $p->y; $pk->z = $p->z;

		$pk->data = 0;

		$p->dataPacket($pk);
	}
	
	public function addSound($p, $id){

		$pk = new \pocketmine\network\mcpe\protocol\LevelSoundEventPacket();

		$pk->sound = $id;

		$pk->x = $p->x; $pk->y = $p->y; $pk->z = $p->z;

		$p->dataPacket($pk);
	}
	
	function onChat(\pocketmine\event\player\PlayerCommandPreprocessEvent $ev){

		$p = $ev->getPlayer(); $name = strtolower($p->getName()); $msg = $ev->getMessage();

		if($this->getAuthStatus($p) == "register"){

			$ev->setCancelled();

			if($msg[0] == "/"){

				$p->sendMessage("§f> §l§7Для использования §l§eкоманд §l§7сначала §l§aзарегистрируйся/авторизируйся§l§7!"); $this->addSound($p, 61);
			}

			if(stripos($msg, ' ') !== false){

				$p->sendMessage("§f> §l§7В §l§eпароле §l§fНЕ должно быть §l§aпробелов §l§7!"); $this->addSound($p, 61);

			} else {

				$this->setAuthStatus($p, "game"); $p->setImmobile(false); $this->setIp($p);

				$p->sendMessage("§f> §l§7Вы §l§aуспешно §l§aзарегистрировались §l§7на сервере!\n §f> §l§7Ваш ник: §l§f{$p->getName()}\n §f> §l§7Ваш §l§7пароль: §l§f{$msg}§l§7! \n§l§7(P.S. §l§fзаскриньте§l§7, §l§fзапишите§l§7, §l§7или §l§fзапомните §l§7его, а самое главное §l§dникому §l§cне §l§7давайте!§l§f)");

				$this->addGuardian($p);

				$this->auth->query("UPDATE `auth` SET `password` = '$msg' WHERE `name` = '$name'");

				$this->setItems($p);
				
			}
		}elseif($this->getAuthStatus($p) == "auth"){

			$ev->setCancelled();

			if($msg[0] == "/"){

				$p->sendMessage("§f> §l§7Для использования §l§eкоманд §l§7сначала §l§aзарегистрируйся/авторизируйся§l§7!"); $this->addSound($p, 61);

			} else {

			    if($msg == $this->getData($p, 'password')){

				    $this->setAuthStatus($p, "game"); $p->setImmobile(false); $this->setIp($p);

				    $p->sendMessage("§l§7┃ §eДобро пожаловать на §l§6FallCraft§e PE§r\n§7┃ §r§bГруппа ВКонтакте §8- §cvk.com/fallcraft_pe\n§l§7┃ §r§eСайт авто-доната §8- §6fallcraft.ru\n\n§7> §eПрочитать §aправила§e сервера можно командой§8: §6/rules\n§7> §eПрочитать §bинформацию§e про донат можно тут§8: §a/donate\n\n§f> §l§7Вы §l§aуспешно §l§7 вошли в свой §l§eаккаунт §l§f{$p->getName()}§l§7!\n§7> §l§7Привязка §l§eаккаунта к §l§fB§l§bK §l§f/vkcode");

				    $this->addGuardian($p); $this->setItems($p);
				
			    } else {

				    $p->sendMessage("§f> §l§7{$p->getName()}, §l§7вы ввели §l§cНЕВЕРНЫЙ §l§7пароль! §l§a:3\n§l§7(§l§7Если вы забыли пароль введите §l§f/аккаунт восстановить §l§7в вк§l§7)"); $this->addSound($p, 61);
			    }
			}
		}
	}
}
?>