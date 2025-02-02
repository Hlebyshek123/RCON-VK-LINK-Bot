<?php

namespace _64FF00\PurePerms\cmd;

use _64FF00\PurePerms\PPGroup;
use _64FF00\PurePerms\PurePerms;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\command\PluginIdentifiableCommand;
use pocketmine\command\RemoteConsoleCommandSender;
use pocketmine\Player;
use pocketmine\utils\TextFormat;
use function array_flip;
use function array_keys;
use function in_array;
use function var_dump;

class SetGroup extends Command implements PluginIdentifiableCommand{
	/*
		PurePerms by 64FF00 (Twitter: @64FF00)

		  888  888    .d8888b.      d8888  8888888888 8888888888 .d8888b.   .d8888b.
		  888  888   d88P  Y88b    d8P888  888        888       d88P  Y88b d88P  Y88b
		888888888888 888          d8P 888  888        888       888    888 888    888
		  888  888   888d888b.   d8P  888  8888888    8888888   888    888 888    888
		  888  888   888P "Y88b d88   888  888        888       888    888 888    888
		888888888888 888    888 8888888888 888        888       888    888 888    888
		  888  888   Y88b  d88P       888  888        888       Y88b  d88P Y88b  d88P
		  888  888    "Y8888P"        888  888        888        "Y8888P"   "Y8888P"
	*/

	/**
	 * @param PurePerms $plugin
	 * @param $name
	 * @param $description
	 */
	public function __construct(PurePerms $plugin, $name, $description){
		$this->plugin = $plugin;

		parent::__construct($name, $description);

		$this->setPermission("pperms.command.setgroup");
	}

	/**
	 * @param CommandSender $sender
	 * @param $label
	 * @param array $args
	 * @return bool
	 */
	public function execute(CommandSender $sender, $label, array $args){
		if(!$this->testPermission($sender)){
			return false;
		}

		if(count($args) < 2){
			$sender->sendMessage(TextFormat::BLUE . "[PurePerms] " . $this->plugin->getMessage("cmds.setgroup.usage"));
			if($sender instanceof ConsoleCommandSender and !$sender instanceof RemoteConsoleCommandSender){
				$sender->sendMessage('§7► §eЧтобы выдать группу игроку ниже его, введи §d/'. $label .' (ник игрока) (группа) true');
			}
			return true;
		}

		$player = $this->plugin->getPlayer($args[0]);

		$group = $this->plugin->getGroup($args[1]);

		if($group == null){
			$sender->sendMessage(TextFormat::RED . "[PurePerms] " . $this->plugin->getMessage("cmds.setgroup.messages.group_not_exist", $args[1]));

			return true;
		}

		$levelName = null;

		/*if(isset($args[2])){
			$level = $this->plugin->getServer()->getLevelByName($args[2]);

			if($level == null){
				$sender->sendMessage(TextFormat::RED . "[PurePerms] " . $this->plugin->getMessage("cmds.setgroup.messages.level_not_exist", $args[2]));

				return true;
			}

			$levelName = $level->getName();
		}*/

		if($sender instanceof ConsoleCommandSender and !isset($args[2])){
			$playerGroup = $this->plugin->getUserDataMgr()->getGroup($player);

			$inh = array_map(function(PPGroup $group) : string{
				return $group->getName();
			}, $playerGroup->getInheritedGroups());

			if(in_array($group->getName(), $inh)){
				$sender->sendMessage('§7► §cОшибка. Группа '. $group->getName() .' ниже группы игрока!');
				return true;
			}
		}

		$this->plugin->getUserDataMgr()->setGroup($player, $group, null);

		$sender->sendMessage(TextFormat::BLUE . "[PurePerms] " . $this->plugin->getMessage("cmds.setgroup.messages.setgroup_successfully", $player->getName()));

		if($player instanceof Player)
			$player->sendMessage(TextFormat::BLUE . "[PurePerms] " . $this->plugin->getMessage("cmds.setgroup.messages.on_player_group_change", strtolower($group->getName())));

		return true;
	}

	public function getPlugin(){
		return $this->plugin;
	}
}