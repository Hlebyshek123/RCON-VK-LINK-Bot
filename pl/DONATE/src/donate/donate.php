<?php

namespace donate;

use pocketmine\plugin\PluginBase;
use pocketmine\command\{CommandSender, Command};
use pocketmine\event\Listener;

class donate extends PluginBase implements Listener {
    public function onEnable(){
    }
    
    public function onCommand(CommandSender $p, Command $command, $label, array $args) {
        if($command->getName() == "donate"){
            $p->sendMessage("§l§6Хлебные §l§fПривилегии\n§l§cХейтер §6Хлеба §7- §f10руб\n§l§aЛюбитель §6Хлеба §7- §f25руб\n§l§6Хлебный §fФанат §7- §f100руб\n§l§6Хлебный §fФанатик §7- §f250руб\n§l§6Хлебное §fБратство §7- §f500руб\n§l§6Хлебный §fНаместник §7- §f700руб\n§l§6Хлебное §fБожество §7- §f1,250руб");
        }
    }
}