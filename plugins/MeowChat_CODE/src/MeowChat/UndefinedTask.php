<?php

namespace MeowChat;

use pocketmine\scheduler\PluginTask;
use MeowChat\Alex;

class UndefinedTask extends PluginTask{
	
	function __construct(Alex $plugin){
		parent::__construct($plugin);
	}
	
	function onRun($tick){
		foreach($this->getOwner()->getServer()->getOnlinePlayers() as $players){
			$players->setNameTag($this->getOwner()->getNameTag($players, $this->getOwner()->getPurePerms()->getUserDataMgr()->getGroup($players)->getName()));
			$players->setDisplayName($this->getOwner()->getDisplayName($players, $this->getOwner()->getPurePerms()->getUserDataMgr()->getGroup($players)->getName()));
		}
	}
}