<?php

namespace ChangePrefix;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\command\{Command, CommandSender};
use pocketmine\Player;
use pocketmine\utils\Config;

class PrefixManager extends PluginBase implements Listener {

    private $playerData;

    public function onEnable(): void {
        @mkdir($this->getDataFolder());
        // Используем JSON для хранения данных игрока
        $this->playerData = new Config($this->getDataFolder() . "players_data.json", Config::JSON);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info("Плагин PrefixManager включен.");
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args): bool {
    $name = $sender->getName();
    $bold = false;

    // Проверка на наличие параметра "bold" в конце аргументов
    if (!empty($args) && strtolower(end($args)) === "bold") {
        $bold = true;
        array_pop($args); // Убираем "bold" из массива аргументов
    }

    // Команда для работы с префиксами
    if ($command->getName() === "prefixes") {
        if ($sender->hasPermission("prefixmanager.prefixes")) {
            if (count($args) < 1) {
            $this->sendHelpMessage($sender);
            return true;
        }

        $subCommand = strtolower($args[0]);

        if ($subCommand === "set" && isset($args[1])) {
            $prefix = $args[1];
            $this->setPlayerData($name, 'prefix', $prefix);
            $this->setPlayerData($name, 'boldPrefix', $bold);
            $this->updatePlayerDisplay($sender);
            $sender->sendMessage("§7§l> §7(§6Префиксы§7) §fВаш новый префикс: §r" . ($bold ? "§l" : "") . "$prefix");
        } elseif ($subCommand === "off") {
            $this->setPlayerData($name, 'prefix', null);
            $this->setPlayerData($name, 'boldPrefix', false);
            $this->updatePlayerDisplay($sender);
            $sender->sendMessage("§7§l> §7(§6Префиксы§7) §fПрежний префикс восстановлен.");
        } elseif ($subCommand === "hide-nick" && isset($args[1])) {
            
                        // Проверка прав на использование команды "hide-nick"
            if ($sender->hasPermission("prefixmanager.hidenick")) {
            
            if (isset($args[1]) && strtolower($args[1]) === "off") {
                // Отключаем фейковый ник
                $this->setPlayerData($name, 'fakeNick', null);
                $this->updatePlayerDisplay($sender);
                $sender->sendMessage("§7§l> §7(§6Ник§7) §fВаш настоящий ник §a§lвосстановлен.");
            } elseif (isset($args[1]))
 {           
            $fakeNick = $args[1];

            // Проверка на запрещённые символы
            if (preg_match('/[^\w\d_]/', $fakeNick) || strpos($fakeNick, '§') !== false) {
                $sender->sendMessage("§7§l> §7(§6Ник§7) §fНик §c§lне должен содержать §fспецсимволы или символы форматирования чата.");
                return true;
            }

            // Проверка, занят ли фейковый ник другим игроком
            if ($this->isFakeNickInUse($fakeNick)) {
                $sender->sendMessage("§7§l> §7(§6Ник§7) §fНик $fakeNick §c§lуже занят §fдругим игроком.");
                return true;
            }

            // Если все проверки пройдены, устанавливаем фейковый ник
            $this->setPlayerData($name, 'fakeNick', $fakeNick);
            $this->updatePlayerDisplay($sender);
            $sender->sendMessage("§7§l> §7(§6Ник§7) §fВаш новый фейковый ник: §r§l§c$fakeNick");
 }
                
            } else {
                $sender->sendMessage("§l§7> §7(§6Ник§7) §fУ вас §c§lнет прав §fна использование этой команды§7.");
            }
        } elseif ($subCommand === "hide") {
            // Проверка прав на использование команды "hide"
            if ($sender->hasPermission("prefixmanager.hide")) {
                $this->setPlayerData($name, 'boldNick', false);
            $this->setPlayerData($name, 'boldChat', false);
            $this->setPlayerData($name, 'boldPrefix', false);
            $this->setPlayerData($name, 'prefix', "§fИгрок");
            $this->setPlayerData($name, 'nickColor', "f");
            $this->setPlayerData($name, 'chatColor', "7");
            $this->updatePlayerDisplay($sender);
            $sender->sendMessage("§7§l> §7(§6Скрытие§7) §fВы §cскрыли свой §fпрефикс.");
            } else {
                $sender->sendMessage("§l§7> §7(§6Префиксы§7) §l§fУ вас §c§lнет прав §fна использование этой §6команды§7.");
            }
        } elseif ($subCommand === "realnick" && isset($args[1])) {
            
             // Проверка прав на использование команды "hide-nick"
            if ($sender->hasPermission("prefixmanager.realnick")) {
            
            $fakeNick = $args[1];
            $realNick = $this->findRealNick($fakeNick);

            if ($realNick !== null) {
                $sender->sendMessage("§7§l> §7(§6Ник§7) §a§lНастоящий §fникнейм игрока с фейк ником §c$fakeNick: §f$realNick");
            } else {
                $sender->sendMessage("§7§l> §7(§6Ник§7) §fИгрок с фейковым ником §6$fakeNick §l§cне найден.");
            }
        } else {
            $sender->sendMessage("§7§l> §7(§6Ник§7) §l§fУ вас §c§lнет прав §fна использование этой §6команды§7.");
        }
        } else {
            $this->sendErrorMessage($sender);
        }
        return true;
        } else {
            $sender->sendMessage("§l§7> §7(§6Префиксы§7) §l§fУ вас §c§lнет прав §fна использование этой §6команды§7.");
        }
        return true;
    }

    // Команда для работы с цветом ника
    if ($command->getName() === "colornick") {
        if (count($args) < 1) {
            $this->sendHelpMessage($sender, 'nick');
            return true;
        }

        $subCommand = strtolower($args[0]);

        if ($subCommand === "set" && isset($args[1]) && preg_match('/^[0-9a-fA-F]$/', $args[1])) {
            $color = strtolower($args[1]);
            $this->setPlayerData($name, 'nickColor', $color);
            $this->setPlayerData($name, 'boldNick', $bold);
            $this->updatePlayerDisplay($sender);
            $sender->sendMessage("§7§l> §7(§6Ник§7) §fВаш новый цвет ника: " . ($bold ? "§l" : "") . "§$color$name");
        } elseif ($subCommand === "del") {
            $this->setPlayerData($name, 'nickColor', "7");
            $this->setPlayerData($name, 'boldNick', false);
            $this->updatePlayerDisplay($sender);
            $sender->sendMessage("§7§l> §7(§6Ник§7) §fЦвет ника сброшен.");
        } else {
            $this->sendErrorMessage($sender);
        }
        return true;
    }

    // Команда для работы с цветом сообщений
    if ($command->getName() === "colorchat") {
        if (count($args) < 1) {
            $this->sendHelpMessage($sender, 'chat');
            return true;
        }

        $subCommand = strtolower($args[0]);

        if ($subCommand === "set" && isset($args[1]) && preg_match('/^[0-9a-fA-F]$/', $args[1])) {
            $color = strtolower($args[1]);
            $this->setPlayerData($name, 'chatColor', $color);
            $this->setPlayerData($name, 'boldChat', $bold);
            $sender->sendMessage("§7§l> §7(§6Чат§7) §fВаш новый цвет сообщений: " . ($bold ? "§l" : "") . "§$color сообщение");
        } elseif ($subCommand === "off") {
            $this->setPlayerData($name, 'chatColor', "7");
            $this->setPlayerData($name, 'boldChat', false);
            $sender->sendMessage("§7§l> §7(§6Чат§7) §fЦвет сообщений сброшен.");
        } else {
            $this->sendErrorMessage($sender);
        }
        return true;
    }

    return false;
}

    private function sendErrorMessage(CommandSender $sender): void {
        $sender->sendMessage("§l§7(§6Префиксы§7) §cОшибка§7: §fНеверные аргументы.");
    }

    private function sendHelpMessage(CommandSender $sender, string $type = null): void {
        if ($type === 'nick') {
            $sender->sendMessage("§l§7> §l§7(§6Ник §fпомощь§7)\n§f/colornick set [цвет] [bold] §6- §fустановить цвет ника.\n§f/colornick off §6- §fудалить цвет ника.");
        } elseif ($type === 'chat') {
            $sender->sendMessage("§7§l> §7(§6Чат §fпомощь§7)\n§f/colorchat set [цвет] [bold] §6- §fустановить цвет сообщений.\n§f/colorchat off §6- §fсбросить цвет сообщений.");
        } else {
            $sender->sendMessage("§7§l> §7(§6Префиксы §fпомощь§7)\n§f/prefixes set [текст] [bold] §6- §fустановить префикс.\n§f/prefixes off §6- §fудалить префикс.\n§f/prefixes hide §6- §fскрыть префикс.\n§f/prefixes hide-nick [фейк ник] §6- §fскрыть свой ник от всех.\n§f/prefixes hide-nick off §6- §fвосстановить свой прежний никнейм.\n§f/prefixes realnick [фейк ник] §6- §fузнать настоящий ник игрока если у него фейк ник.");
        }
    }

    /**
     * Обрабатывает событие чата и таба с установленным приоритетом highest.
     * 
     * @param PlayerJoinEvent $event
     * @priority lowest
     */
    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $this->updatePlayerDisplay($player);
    }

// Проверка, используется ли фейковый ник другим игроком
private function isFakeNickInUse(string $fakeNick): bool {
    $data = $this->playerData->getAll();
    foreach ($data as $playerName => $playerData) {
        if (isset($playerData['fakeNick']) && $playerData['fakeNick'] === $fakeNick) {
            return true;
        }
    }
    return false;
}

// Поиск настоящего ника по фейковому
private function findRealNick(string $fakeNick): ?string {
    $data = $this->playerData->getAll();
    foreach ($data as $playerName => $playerData) {
        if (isset($playerData['fakeNick']) && $playerData['fakeNick'] === $fakeNick) {
            return $playerName;
        }
    }
    return null;
}

    private function updatePlayerDisplay($player): void {
        $name = $player->getName();

        $prefix = $this->getPlayerData($name, 'prefix') ?? '';
        $nickColor = $this->getPlayerData($name, 'nickColor') ?? 'f';
        $boldPrefix = $this->getPlayerData($name, 'boldPrefix') ? "§l" : null;
        $boldNick = $this->getPlayerData($name, 'boldNick') ? "§l" : null;
        $coloredName = "{$boldNick}§{$nickColor}$name";
        $displayName = "{$boldPrefix}{$prefix}§r $coloredName";

        $player->setDisplayName($displayName);
        $player->setNameTag($displayName);
    }

    public function getPlayerData(string $playerName, string $key) {
        $data = $this->playerData->get($playerName, []);
        return $data[$key] ?? null;
    }

    public function setPlayerData(string $playerName, string $key, $value): void {
        $data = $this->playerData->get($playerName, []);
        if ($value === null) {
            unset($data[$key]); // Удаляем ключ, если значение null
        } else {
            $data[$key] = $value;
        }
        $this->playerData->set($playerName, $data);
        $this->playerData->save(); // Сохраняем изменения в JSON-файл
    }
}