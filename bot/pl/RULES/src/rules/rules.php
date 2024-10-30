<?php

namespace rules;

use pocketmine\plugin\PluginBase;
use pocketmine\command\{CommandSender, Command};
use pocketmine\event\Listener;

class rules extends PluginBase implements Listener {
    public function onEnable(){
    }
    
    public function onCommand(CommandSender $p, Command $command, $label, array $args) {
        if($command->getName() == "rules"){
            $p->sendMessage("§l§6Хлебные §f§lПравила§7!\n§61. §fУважать хлеб и поклоняться ему всегда и везде§7!\n§62. §fУважать хлебное братство и §cникогда §fему не возражать и т.д\n§l§63. §fИ всегда помнить §6Хлеб §fвсему голова§7!");
        }
    }
}
