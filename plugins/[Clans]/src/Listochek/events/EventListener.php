<?php

namespace Listochek\events;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\Player;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use Listochek\Clans;

/**
 * Class EventListener
 * @package Listochek\events
 */
class EventListener implements Listener
{

    /**
     * @var Clans
     */
    private static $instance;

    /**
     * @var array
     */
    private $lastHit = [];

    /**
     * EventListener constructor.
     * @param Clans $plugin
     */
    public function __construct(Clans $plugin)
    {
        self::$instance = $plugin;
    }

    /**
     * @return Clans
     */
    private static function getPlugin()
    {
        return self::$instance;
    }

    /**
     * @param $name
     * @param $damager
     */
    private function setLastHit(Player $player, Player $damager)
    {
        $time = time();
        if ($player instanceof Player) {
            $name = $player->getName();
        }

        $this->lastHit[$name] = [
            "damager" => $damager,
            "time" => $time
        ];
    }

    /**
     * @param $name
     * @return null
     */
    private function getLastHit($name)
    {
        $time = time();
        if (isset($this->lastHit[$name])) {
            $data = $this->lastHit[$name];
            if (($time - $data["time"]) >= 6) {
                return null;
            }
            return $data["damager"];
        }
        return null;
    }

    /**
     * @param PlayerJoinEvent $event
     */
    public function sendTop(PlayerJoinEvent $event)
    {
        $player = $event->getPlayer();
        self::getPlugin()->getServer()->getDefaultLevel()->addParticle(self::getPlugin()->particle, [$player]);
    }

    /**
     * @param EntityDamageEvent $e
     */
    public function onDamage(EntityDamageEvent $event)
    {
        $player = $event->getEntity();
        if ($player instanceof Player) {
            if ($event instanceof EntityDamageByEntityEvent) {
                $damager = $event->getDamager();
                if ($damager instanceof Player) {
                    $this->setLastHit($player, $damager);
                }
            }
        }
    }

    /**
     * @param PlayerDeathEvent $event
     */
    public function onKill(PlayerDeathEvent $event)
    {
        $player = $event->getPlayer();
        $damager = $this->getLastHit($player->getName());
        if ($damager instanceof Player) {
            $name = $damager->getName();
            self::getPlugin()->addKill($name);
        }
    }
}