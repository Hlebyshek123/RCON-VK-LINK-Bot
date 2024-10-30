<?php

namespace ChangePrefix;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\command\{Command, CommandSender};
use pocketmine\utils\Config;

class PrefixManager extends PluginBase implements Listener {

    private $prefixes;
    private $nickColors;
    private $chatColors;

    public function onEnable(): void {
        @mkdir($this->getDataFolder());

        $this->prefixes = new Config($this->getDataFolder() . "prefixes.yml", Config::YAML);
        $this->nickColors = new Config($this->getDataFolder() . "nickColors.yml", Config::YAML);
        $this->chatColors = new Config($this->getDataFolder() . "chatColors.yml", Config::YAML);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->getLogger()->info("Плагин PrefixManager включен.");
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args): bool {
        if ($command->getName() === "prefixes") {
            if (count($args) < 1) {
                $sender->sendMessage("§7§l> §7(§6Префиксы §fпомощь§7)\n§f/prefixes set [текст] §6- §fустановить свой префикс в чате и табе.\n§f/prefixes del §6- §fудалить префикс в табе и чате.");
                return true;
            }

            $subCommand = strtolower($args[0]);
            if ($subCommand === "set" && isset($args[1])) {
                $prefix = $args[1];
                $this->prefixes->set($sender->getName(), $prefix);
                $this->prefixes->save();

                $this->updatePlayerDisplay($sender, $prefix);
                $sender->sendMessage("§7§l> §7(§6Префиксы§7) §f§lВаш новый префикс:§r $prefix");
            } elseif ($subCommand === "del") {
                $this->prefixes->remove($sender->getName());
                $this->prefixes->save();

                $this->updatePlayerDisplay($sender, "");
                $sender->sendMessage("§7§l> §7(§6Префиксы§7) §l§fВы успешно §l§6восстановили §f§lсвой прежний префикс\n§f§l!!(что бы изменения вступили в силу нужно перезайти)!!");
            }
            return true;
        }

        if ($command->getName() === "colornick") {
            if (count($args) < 1) {
                $sender->sendMessage("§l§7> §l§7(§6Префиксы §fпомощь§7)\n§f/colornick set [цвет] §6- §fустановить свой цвет ника в табе и чате.\n§f/colornick del §6- §fудалить цвет ника в табе и чате.\n§f/colornick bold §6- §fсделать ник жирным в чате и табе.");
                return true;
            }

            $subCommand = strtolower($args[0]);
            $name = $sender->getName();

            if ($subCommand === "set" && isset($args[1]) && strlen($args[1]) === 1 && preg_match('/^[0-9a-fA-F]$/', $args[1])) {
                $color = strtolower($args[1]);
                $this->nickColors->set($name, ["color" => $color, "bold" => false]);
                $this->nickColors->save();

                $this->updatePlayerDisplay($sender, $this->prefixes->get($name, ""));
                $sender->sendMessage("§7§l> §7(§6Префиксы§7) §f§lВы §l§6установили §l§fновый цвет вашего ника:§r §$color$name.");
            } elseif ($subCommand === "del") {
                $this->nickColors->remove($name);
                $this->nickColors->save();

                $this->updatePlayerDisplay($sender, $this->prefixes->get($name, ""));
                $sender->sendMessage("§7§l> §7(§6Префиксы§7) §f§lВы §c§lсбросили §f§lцвет своего ника.");
            } elseif ($subCommand === "bold") {
                $currentColor = $this->nickColors->get($name, ["color" => "f", "bold" => false]);
                $this->nickColors->set($name, ["color" => $currentColor["color"], "bold" => true]);
                $this->nickColors->save();

                $this->updatePlayerDisplay($sender, $this->prefixes->get($name, ""));
                $sender->sendMessage("§7§l> §7(§6Префиксы§7) §fВаш никнейм теперь §6§lжирный.");
            }
            return true;
        }

        if ($command->getName() === "colorchat") {
            if (count($args) < 1) {
                $sender->sendMessage("§7§l> §7(§6Префиксы §fпомощь§7)\n§f/colorchat set [цвет] §6- §fустановить цвет сообщений.\n§f/colorchat del §6- §fсбросить цвет сообщений.\n§f/colorchat bold §6- §fсделать сообщения жирными.");
                return true;
            }

            $subCommand = strtolower($args[0]);
            $name = $sender->getName();

            if ($subCommand === "set" && isset($args[1]) && strlen($args[1]) === 1 && preg_match('/^[0-9a-fA-F]$/', $args[1])) {
                $color = strtolower($args[1]);
                $this->chatColors->set($name, ["color" => $color, "bold" => false]);
                $this->chatColors->save();

                $sender->sendMessage("§7§l> §7(§6Чат§7) §fВы §l§6установили §l§fновый цвет своих сообщений:§r §$color сообщение.");
            } elseif ($subCommand === "del") {
                $this->chatColors->remove($name);
                $this->chatColors->save();

                $sender->sendMessage("§7§l> §7(§6Чат§7) §fЦвет сообщений §c§lсброшен§7.");
            } elseif ($subCommand === "bold") {
                $currentColor = $this->chatColors->get($name, ["color" => "7", "bold" => false]);
                $this->chatColors->set($name, ["color" => $currentColor["color"], "bold" => true]);
                $this->chatColors->save();

                $sender->sendMessage("§7§l> §7(§6Чат§7) §fВаши сообщения теперь §6§lжирные.");
            }
            return true;
        }

        return false;
    }

    /**
     * Обрабатывает событие чата и таба с установленным приоритетом highest.
     * 
     * @param PlayerJoinEvent $event
     * @priority highest
     */
    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $name = $player->getName();

        $prefix = $this->prefixes->exists($name) ? $this->prefixes->get($name) : null;
        $this->updatePlayerDisplay($player, $prefix);
    }

    /**
     * Обрабатывает событие чата с установленным приоритетом highest.
     * 
     * @param PlayerChatEvent $event
     * @priority highest
     */
    public function onPlayerChat(PlayerChatEvent $event): void {
        $player = $event->getPlayer();
        $name = $player->getName();
        $prefix = $this->prefixes->get($name, null);

        if ($prefix === null) {
            return;
        }

        $nickColorData = $this->nickColors->get($name, ["color" => "f", "bold" => false]);
        $nickColor = $nickColorData["color"];
        $boldNick = $nickColorData["bold"] ? "§l" : "";
        $coloredName = "{$boldNick}§$nickColor$name";

        $chatColorData = $this->chatColors->get($name, ["color" => "7", "bold" => false]);
        $chatColor = $chatColorData["color"];
        $boldChat = $chatColorData["bold"] ? "§l" : "";

        // Устанавливаем формат чата с префиксом, цветом ника и цветом сообщения
        $event->setFormat("{$prefix}§r {$coloredName}§r §6>> {$boldChat}§$chatColor" . $event->getMessage() . "§r");
    }

    /**
     * Устанавливает префикс и цвет ника для отображения, если префикс установлен.
     *
     * @param Player $player
     * @param string|null $prefix
     */
    private function updatePlayerDisplay($player, ?string $prefix): void {
        if ($prefix === null || $prefix === "") {
            return;
        }

        $nickColorData = $this->nickColors->get($player->getName(), ["color" => "f", "bold" => false]);
        $nickColor = $nickColorData["color"];
        $bold = $nickColorData["bold"] ? "§l" : "";

        $coloredName = "{$bold}§$nickColor{$player->getName()}";
        $displayName = "{$prefix}§r $coloredName";

        $player->setDisplayName($displayName);
        $player->setNameTag($displayName);
    }
}