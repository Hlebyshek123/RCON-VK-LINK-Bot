<?php

namespace info;

use pocketmine\plugin\PluginBase;
use pocketmine\command\{CommandSender, Command};
use pocketmine\event\Listener;

class info extends PluginBase implements Listener {
    public function onEnable(){
    }
    
    public function onCommand(CommandSender $p, Command $command, $label, array $args) {
        if($command->getName() == "rules"){
            $p->sendMessage("§l§6Хлебные §f§lПравила§7!\n§61. §fУважать хлеб и поклоняться ему всегда и везде§7!\n§62. §fУважать хлебное братство и §cникогда §fему не возражать и т.д\n§l§63. §fИ всегда помнить §6Хлеб §fвсему голова§7!");
        }
        if($command->getName() == "donate"){
            $p->sendMessage("§l§6Хлебные §l§fПривилегии\n§l§cХейтер §6Хлеба §7- §f10руб\n§l§aЛюбитель §6Хлеба §7- §f25руб\n§l§6Хлебный §fФанат §7- §f100руб\n§l§6Хлебный §fФанатик §7- §f250руб\n§l§6Хлебное §fБратство §7- §f500руб\n§l§6Хлебный §fНаместник §7- §f700руб\n§l§6Хлебное §fБожество §7- §f1,250руб");
        }
        if($command->getName() == "sosat"){
            $p->sendMessage("§a+PARRY");
        }
    }
}
