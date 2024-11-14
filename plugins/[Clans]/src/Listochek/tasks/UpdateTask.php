<?php

namespace Listochek\tasks;

use pocketmine\scheduler\PluginTask;
use Listochek\Clans;

/**
 * Class UpdateTask
 * @package Listochek\tasks
 */
class UpdateTask extends PluginTask
{

    /**
     * @var Clans
     */
    private $plugin;

    /**
     * UpdateTask constructor.
     * @param Clans $plugin
     */
    public function __construct(Clans $plugin)
    {
        parent::__construct($plugin);
        $this->plugin = $plugin;
    }

    /**
     * @param int $currentTick
     */
    public function onRun($currentTick)
    {
        $this->plugin->updateTop();
    }
}