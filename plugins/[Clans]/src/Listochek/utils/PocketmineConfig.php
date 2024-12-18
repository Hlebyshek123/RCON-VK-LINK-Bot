<?php


namespace Listochek\utils;


use InvalidStateException;
use pocketmine\utils\MainLogger;
use RuntimeException;

class PocketmineConfig{
    const DETECT = -1; //Detect by file extension
    const PROPERTIES = 0; // .properties
    const CNF = PocketmineConfig::PROPERTIES; // .cnf
    const JSON = 1; // .js, .json
    const YAML = 2; // .yml, .yaml
    //const EXPORT = 3; // .export, .xport
    const SERIALIZED = 4; // .sl
    const ENUM = 5; // .txt, .list, .enum
    const ENUMERATION = PocketmineConfig::ENUM;
    /** @var array */
    private $config = [];
    private $nestedCache = [];
    /** @var string */
    private $file;
    /** @var bool */
    private $correct = false;
    /** @var int */
    private $type = PocketmineConfig::DETECT;
    /** @var int */
    private $jsonOptions = JSON_PRETTY_PRINT | JSON_BIGINT_AS_STRING;
    /** @var bool */
    private $changed = false;
    public static $formats = [
        "properties" => PocketmineConfig::PROPERTIES,
        "cnf" => PocketmineConfig::CNF,
        "conf" => PocketmineConfig::CNF,
        "config" => PocketmineConfig::CNF,
        "json" => PocketmineConfig::JSON,
        "js" => PocketmineConfig::JSON,
        "yml" => PocketmineConfig::YAML,
        "yaml" => PocketmineConfig::YAML,
        //"export" => PocketmineConfig::EXPORT,
        //"xport" => PocketmineConfig::EXPORT,
        "sl" => PocketmineConfig::SERIALIZED,
        "serialize" => PocketmineConfig::SERIALIZED,
        "txt" => PocketmineConfig::ENUM,
        "list" => PocketmineConfig::ENUM,
        "enum" => PocketmineConfig::ENUM
    ];
    /**
     * @param string $file     Path of the file to be loaded
     * @param int    $type     Config type to load, -1 by default (detect)
     * @param array  $default  Array with the default values that will be written to the file if it did not exist
     * @param null   &$correct Sets correct to true if everything has been loaded correctly
     */
    public function __construct(string $file, int $type = PocketmineConfig::DETECT, array $default = [], &$correct = null){
        $this->load($file, $type, $default);
        $correct = $this->correct;
    }
    /**
     * Removes all the changes in memory and loads the file again
     */
    public function reload(){
        $this->config = [];
        $this->nestedCache = [];
        $this->correct = false;
        $this->load($this->file, $this->type);
    }
    public function hasChanged() : bool{
        return $this->changed;
    }
    public function setChanged(bool $changed = true) {
        $this->changed = $changed;
    }
    /**
     * @param string $str
     *
     * @return string
     */
    public static function fixYAMLIndexes(string $str) : string{
        return preg_replace("#^( *)(y|Y|yes|Yes|YES|n|N|no|No|NO|true|True|TRUE|false|False|FALSE|on|On|ON|off|Off|OFF)( *)\:#m", "$1\"$2\"$3:", $str);
    }
    /**
     * @param string $file
     * @param int    $type
     * @param array  $default
     *
     * @return bool
     */
    public function load(string $file, int $type = PocketmineConfig::DETECT, array $default = []) : bool{
        $this->correct = true;
        $this->file = $file;
        $this->type = $type;
        if($this->type === PocketmineConfig::DETECT){
            $extension = explode(".", basename($this->file));
            $extension = strtolower(trim(array_pop($extension)));
            if(isset(PocketmineConfig::$formats[$extension])){
                $this->type = PocketmineConfig::$formats[$extension];
            }else{
                $this->correct = false;
            }
        }
        if(!file_exists($file)){
            $this->config = $default;
            $this->save();
        }else{
            if($this->correct){
                $content = file_get_contents($this->file);
                switch($this->type){
                    case PocketmineConfig::PROPERTIES:
                        $this->parseProperties($content);
                        break;
                    case PocketmineConfig::JSON:
                        $this->config = json_decode($content, true);
                        break;
                    case PocketmineConfig::YAML:
                        $content = self::fixYAMLIndexes($content);
                        $this->config = yaml_parse($content);
                        break;
                    case PocketmineConfig::SERIALIZED:
                        $this->config = unserialize($content);
                        break;
                    case PocketmineConfig::ENUM:
                        $this->parseList($content);
                        break;
                    default:
                        $this->correct = false;
                        return false;
                }
                if(!is_array($this->config)){
                    $this->config = $default;
                }
                if($this->fillDefaults($default, $this->config) > 0){
                    $this->save();
                }
            }else{
                return false;
            }
        }
        return true;
    }
    /**
     * @return bool
     */
    public function check() : bool{
        return $this->correct;
    }
    /**
     * @return bool
     */
    public function save() : bool{
        if($this->correct){
            $content = null;
            switch($this->type){
                case PocketmineConfig::PROPERTIES:
                    $content = $this->writeProperties();
                    break;
                case PocketmineConfig::JSON:
                    $content = json_encode($this->config, $this->jsonOptions);
                    break;
                case PocketmineConfig::YAML:
                    $content = yaml_emit($this->config, YAML_UTF8_ENCODING);
                    break;
                case PocketmineConfig::SERIALIZED:
                    $content = serialize($this->config);
                    break;
                case PocketmineConfig::ENUM:
                    $content = implode("\r\n", array_keys($this->config));
                    break;
                default:
                    throw new InvalidStateException("Config type is unknown, has not been set or not detected");
            }
            file_put_contents($this->file, $content);
            $this->changed = false;
            return true;
        }else{
            return false;
        }
    }

    /**
     * Sets the options for the JSON encoding when saving
     *
     * @param int $options
     *
     * @return PocketmineConfig $this
     * @see json_encode
     */
    public function setJsonOptions(int $options) : PocketmineConfig{
        if($this->type !== PocketmineConfig::JSON){
            throw new RuntimeException("Attempt to set JSON options for non-JSON config");
        }
        $this->jsonOptions = $options;
        $this->changed = true;
        return $this;
    }
    /**
     * Enables the given option in addition to the currently set JSON options
     *
     * @param int $option
     *
     * @return PocketmineConfig $this
     * @throws RuntimeException if the Config is not in JSON
     * @see json_encode
     */
    public function enableJsonOption(int $option) : PocketmineConfig{
        if($this->type !== PocketmineConfig::JSON){
            throw new RuntimeException("Attempt to enable JSON option for non-JSON config");
        }
        $this->jsonOptions |= $option;
        $this->changed = true;
        return $this;
    }

    /**
     * Disables the given option for the JSON encoding when saving
     *
     * @param int $option
     *
     * @return PocketmineConfig $this
     * @see json_encode
     */
    public function disableJsonOption(int $option) : PocketmineConfig{
        if($this->type !== PocketmineConfig::JSON){
            throw new RuntimeException("Attempt to disable JSON option for non-JSON config");
        }
        $this->jsonOptions &= ~$option;
        $this->changed = true;
        return $this;
    }
    /**
     * Returns the options for the JSON encoding when saving
     *
     * @return int
     * @throws RuntimeException if the Config is not in JSON
     * @see json_encode
     */
    public function getJsonOptions() : int{
        if($this->type !== PocketmineConfig::JSON){
            throw new RuntimeException("Attempt to get JSON options for non-JSON config");
        }
        return $this->jsonOptions;
    }
    /**
     * @param string $k
     *
     * @return bool|mixed
     */
    public function __get($k){
        return $this->get($k);
    }
    /**
     * @param string $k
     * @param mixed  $v
     */
    public function __set($k, $v){
        $this->set($k, $v);
    }
    /**
     * @param string $k
     *
     * @return bool
     */
    public function __isset($k){
        return $this->exists($k);
    }
    /**
     * @param string $k
     */
    public function __unset($k){
        $this->remove($k);
    }
    /**
     * @param string $key
     * @param mixed  $value
     */
    public function setNested($key, $value){
        $vars = explode(".", $key);
        $base = array_shift($vars);
        if(!isset($this->config[$base])){
            $this->config[$base] = [];
        }
        $base =& $this->config[$base];
        while(count($vars) > 0){
            $baseKey = array_shift($vars);
            if(!isset($base[$baseKey])){
                $base[$baseKey] = [];
            }
            $base =& $base[$baseKey];
        }
        $base = $value;
        $this->nestedCache = [];
        $this->changed = true;
    }
    /**
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function getNested($key, $default = null){
        if(isset($this->nestedCache[$key])){
            return $this->nestedCache[$key];
        }
        $vars = explode(".", $key);
        $base = array_shift($vars);
        if(isset($this->config[$base])){
            $base = $this->config[$base];
        }else{
            return $default;
        }
        while(count($vars) > 0){
            $baseKey = array_shift($vars);
            if(is_array($base) and isset($base[$baseKey])){
                $base = $base[$baseKey];
            }else{
                return $default;
            }
        }
        return $this->nestedCache[$key] = $base;
    }
    public function removeNested(string $key) {
        $this->nestedCache = [];
        $this->changed = true;
        $vars = explode(".", $key);
        $currentNode =& $this->config;
        while(count($vars) > 0){
            $nodeName = array_shift($vars);
            if(isset($currentNode[$nodeName])){
                if(empty($vars)){ //final node
                    unset($currentNode[$nodeName]);
                }elseif(is_array($currentNode[$nodeName])){
                    $currentNode =& $currentNode[$nodeName];
                }
            }else{
                break;
            }
        }
    }
    /**
     * @param string $k
     * @param mixed  $default
     *
     * @return bool|mixed
     */
    public function get($k, $default = false){
        return ($this->correct and isset($this->config[$k])) ? $this->config[$k] : $default;
    }
    /**
     * @param string $k key to be set
     * @param mixed  $v value to set key
     */
    public function set($k, $v = true){
        $this->config[$k] = $v;
        $this->changed = true;
        foreach($this->nestedCache as $nestedKey => $nvalue){
            if(substr($nestedKey, 0, strlen($k) + 1) === ($k . ".")){
                unset($this->nestedCache[$nestedKey]);
            }
        }
    }
    /**
     * @param array $v
     */
    public function setAll(array $v){
        $this->config = $v;
        $this->changed = true;
    }
    /**
     * @param string $k
     * @param bool   $lowercase If set, searches Config in single-case / lowercase.
     *
     * @return bool
     */
    public function exists($k, bool $lowercase = false) : bool{
        if($lowercase){
            $k = strtolower($k); //Convert requested  key to lower
            $array = array_change_key_case($this->config, CASE_LOWER); //Change all keys in array to lower
            return isset($array[$k]); //Find $k in modified array
        }else{
            return isset($this->config[$k]);
        }
    }
    /**
     * @param string $k
     */
    public function remove($k){
        unset($this->config[$k]);
        $this->changed = true;
    }
    /**
     * @param bool $keys
     *
     * @return array
     */
    public function getAll(bool $keys = false) : array{
        return ($keys ? array_keys($this->config) : $this->config);
    }
    /**
     * @param array $defaults
     */
    public function setDefaults(array $defaults){
        $this->fillDefaults($defaults, $this->config);
    }
    /**
     * @param array $default
     * @param array &$data
     *
     * @return int
     */
    private function fillDefaults(array $default, &$data) : int{
        $changed = 0;
        foreach($default as $k => $v){
            if(is_array($v)){
                if(!isset($data[$k]) or !is_array($data[$k])){
                    $data[$k] = [];
                }
                $changed += $this->fillDefaults($v, $data[$k]);
            }elseif(!isset($data[$k])){
                $data[$k] = $v;
                ++$changed;
            }
        }
        if($changed > 0){
            $this->changed = true;
        }
        return $changed;
    }
    /**
     * @param string $content
     */
    private function parseList(string $content){
        foreach(explode("\n", trim(str_replace("\r\n", "\n", $content))) as $v){
            $v = trim($v);
            if($v == ""){
                continue;
            }
            $this->config[$v] = true;
        }
    }
    /**
     * @return string
     */
    private function writeProperties() : string{
        $content = "#Properties Config file\r\n#" . date("D M j H:i:s T Y") . "\r\n";
        foreach($this->config as $k => $v){
            if(is_bool($v)){
                $v = $v ? "on" : "off";
            }elseif(is_array($v)){
                $v = implode(";", $v);
            }
            $content .= $k . "=" . $v . "\r\n";
        }
        return $content;
    }
    /**
     * @param string $content
     */
    private function parseProperties(string $content){
        if(preg_match_all('/^\s*([a-zA-Z0-9\-_\.]+)[ \t]*=([^\r\n]*)/um', $content, $matches) > 0){ //false or 0 matches
            foreach($matches[1] as $i => $k){
                $v = trim($matches[2][$i]);
                switch(strtolower($v)){
                    case "on":
                    case "true":
                    case "yes":
                        $v = true;
                        break;
                    case "off":
                    case "false":
                    case "no":
                        $v = false;
                        break;
                }
                if(isset($this->config[$k])){
                    MainLogger::getLogger()->debug("[Config] Repeated property " . $k . " on file " . $this->file);
                }
                $this->config[$k] = $v;
            }
        }
    }
}