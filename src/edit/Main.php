<?php
namespace edit;

use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use pocketmine\Player;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\utils\MainLogger;
use pocketmine\item\Item;
use pocketmine\block\Block;
use pocketmine\block\Fallable;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\math\Vector3;
use pocketmine\level\Position;
use pocketmine\level\Level;
use pocketmine\scheduler\Task;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;

use edit\blocks\BlockType;
use edit\util\TargetBlock;
use edit\command\tool\BrushTool;
use edit\command\tool\Tool;
use edit\command\tool\TraceTool;
use edit\extension\factory\BlockFactory;
use edit\extension\factory\PatternFactory;
use edit\world\registry\LegacyBlockRegistry;
use edit\world\registry\BundledBlockData;

use edit\command\Pos1Command;
use edit\command\Pos2Command;
use edit\command\CopyCommand;
use edit\command\CutCommand;
use edit\command\PasteCommand;
use edit\command\RotateCommand;
use edit\command\FlipCommand;
use edit\command\BrushCommand;
use edit\command\MaskCommand;
use edit\command\MoveCommand;
use edit\command\StackCommand;
use edit\command\CylinderCommand;
use edit\command\HcylinderCommand;
use edit\command\SphereCommand;
use edit\command\HsphereCommand;
use edit\command\PyramidCommand;
use edit\command\SetCommand;
use edit\command\ReplaceCommand;
use edit\command\OverlayCommand;
use edit\command\UndoCommand;
use edit\command\RedoCommand;
use edit\command\SmoothCommand;

class Main extends PluginBase implements Listener{

	const LOGO = "[Edit] ";

	public static $instance;

	public static $wandID = 271;
	public static $canUseNotOp = false;

	public static function getInstance(){
		return self::$instance;
	}

	/** @var Config */
	private $config;

	/** @var EditSessions[] */
	public $sessions = [];

	public $tools = [];

	public $patternFactory;
	public $blockFactory;

	public $blockRegistry;

	public function onEnable(){
		self::$instance = $this;
		$this->getServer()->getLogger()->info(self::LOGO . " §a" . $this->getFullName() . "を読み込みました");
		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		$this->config = new Config($this->getDataFolder()."config.yml", Config::YAML, [
			"選択ツール" => 271,
			"OP以外も使えるようにする" => false
		]);

		self::$wandID = $this->config->get("選択ツール");
		self::$canUseNotOp = $this->config->get("OP以外も使えるようにする");
		
		$this->patternFactory = new PatternFactory();
		$this->blockFactory = new BlockFactory();
		$this->blockRegistry = new LegacyBlockRegistry();
		new BundledBlockData();

		$this->getServer()->getCommandMap()->registerAll("worldedit-nightmare", [
			new Pos1Command("/pos1"),
			new Pos2Command("/pos2"),
			new CopyCommand("/copy"),
			new PasteCommand("/paste"),
			new CutCommand("/cut"),
			new RotateCommand("/rotate"),
			new FlipCommand("/flip"),
			new BrushCommand("/brush"),
			new MaskCommand("/mask"),
			new MoveCommand("/move"),
			new StackCommand("/stack"),
			new CylinderCommand("/cylinder"),
			new CylinderCommand("/cyl"),
			new HcylinderCommand("/hcylinder"),
			new HcylinderCommand("/hcyl"),
			new SphereCommand("/sphere"),
			new HsphereCommand("/hsphere"),
			new PyramidCommand("/pyramid"),
			new SetCommand("/set"),
			new ReplaceCommand("/replace"),
			new OverlayCommand("/overlay"),
			new UndoCommand("/undo"),
			new RedoCommand("/redo"),
			new SmoothCommand("/smooth")
		]);
	}

	public function onLogin(PlayerLoginEvent $event){
		$player = $event->getPlayer();
		$session = $this->getEditSession($player);
		if($session === null){
			$this->sessions[$player->getName()] = new EditSession($player);
		}else{
			$session->setPlayer($player);
		}
	}

	public function onInteract(PlayerInteractEvent $event){
		$block = $event->getBlock();
		$player = $event->getPlayer();
		$item = $event->getItem();
		if (!$player->isCreative()) return;
		if(!self::$canUseNotOp && !$player->isOp()) return;
		if($item->getID() == self::$wandID && $block->getId() != 0){
			$pos = new Vector($block->getX(), $block->getY(), $block->getZ());
			Main::getInstance()->getEditSession($player)->getRegionSelector($player->getLevel())->selectPrimary($pos);
			Main::getInstance()->getEditSession($player)->getRegionSelector($player->getLevel())->explainPrimarySelection($player);
		}else if($this->getTool($item, $player) != null){
			$this->getTool($item, $player)->actPrimary($player);
		}
	}

	public function onBreak(BlockBreakEvent $event){
		$player = $event->getPlayer();
		$item = $event->getItem();
		if (!$player->isCreative()) return;
		if(!self::$canUseNotOp && !$player->isOp()) return;
		if($item->getID() == self::$wandID){
			$block = $event->getBlock();
			$pos = new Vector($block->getX(), $block->getY(), $block->getZ());
			Main::getInstance()->getEditSession($player)->getRegionSelector($player->getLevel())->selectSecondary($pos);
			Main::getInstance()->getEditSession($player)->getRegionSelector($player->getLevel())->explainSecondarySelection($player);
			$event->setCancelled();
		}else if($this->getTool($item, $player) != null){
			$event->setCancelled();
		}
	}

	public function getEditSession(Player $player) : ?EditSession{
		return $this->sessions[$player->getName()] ?? null;
	}

	public static function getBlockTrace(Player $player, int $range, bool $useLastBlock = false) : ?WorldVector{
		$tb = new TargetBlock($player, $range, 0.2);
		$result = ($useLastBlock ? $tb->getAnyTargetBlock() : $tb->getTargetBlock());
		return $result->y == 255 ? null : $result;
	}

	public static function getDirection(float $rot) : Vector{
		if(0 <= $rot && $rot < 22.5){
			return new Vector(0, 0, 1);//SOUTH
		}else if(22.5 <= $rot && $rot < 67.5){
			return new Vector(-1, 0, 1);//SOUTH_WEST
		}else if(67.5 <= $rot && $rot < 112.5){
			return new Vector(-1, 0, 0);//WEST
		}else if(112.5 <= $rot && $rot < 157.5){
			return new Vector(-1, 0, -1);//NORTH_WEST
		}else if(157.5 <= $rot && $rot < 202.5){
			return new Vector(0, 0, -1);//NORTH
		}else if(202.5 <= $rot && $rot < 247.5){
			return new Vector(1, 0, -1);//NORTH_EAST
		}else if(247.5 <= $rot && $rot < 292.5){
			return new Vector(1, 0, 0);//EAST
		}else if(292.5 <= $rot && $rot < 337.5){
			return new Vector(1, 0, 1);//SOUTH_EAST
		}else if(337.5 <= $rot && $rot < 360.0){
			return new Vector(0, 0, 1);//SOUTH
		}else{
			return new Vector(0, 0, 0);
		}
	}

	public static function getCardinalDirection(Player $player, int $yawOffset = 0) : Vector{
		if($player->getPitch() > 67.5){
			return new Vector(0, -1, 0);
		}else if($player->getPitch() < -67.5){
			return new Vector(0, 1, 0);
		}

		$rot = ($player->getYaw() + $yawOffset) % 360;
		if($rot < 0){
			$rot += 360;
		}
		return self::getDirection($rot);
	}

	public static function getFlipDirection(Player $player, string $dir) : Vector{
		if($dir == "me"){
			return self::getCardinalDirection($player);
		}

		$d = substr($dir, 0, 1);

		switch($d){
			case "w"://west
				return new Vector(-1, 0, 0);
			case "e"://east
				return new Vector(1, 0, 0);
			case "s"://south
				return new Vector(0, 0, 1);
			case "n"://north
				return new Vector(0, 0, -1);
			case "u"://up
				return new Vector(0, 1, 0);
			case "d"://down
				return new Vector(0, -1, 0);
			case "m"://me
			case "f"://forward
				return self::getCardinalDirection($player, 0);
			case "b"://backward
				return self::getCardinalDirection($player, 180);
			case "l"://left
				return self::getCardinalDirection($player, -90);
			case "r"://right
				return self::getCardinalDirection($player, 90);
			default:
				return new Vector(0, 0, 0);
		}
	}

	public static function findFreePosition(Player $player){
		$searchPos = new WorldVector($player->getLevel(), $player->x, $player->y, $player->z);
		$searchPos = $searchPos->floor();
		$world = $player->getLevel();
		$x = $searchPos->getBlockX();
		$y = max(0, $searchPos->getBlockY());
		$origY = $y;
		$z = $searchPos->getBlockZ();

		$free = 0;

		while($y <= 255 + 2){
			if(BlockType::canPassThrough(Main::getInstance()->getEditSession($player)->getBlock(new Vector($x, $y, $z))->getType())){
				++$free;
			}else{
				$free = 0;
			}

			if($free == 2) {
				if($y - 1 != $origY){
					$pos = new Vector($x, $y - 2, $z);
					$block = Main::getInstance()->getEditSession($player)->getBlock($pos);
					$player->teleport(new Vector3($x + 0.5, $y - 2 + 1, $z + 0.5));
				}

				return;
			}

			++$y;
		}
	}

	public function getTool(Item $item, Player $player) : ?Tool{
		$name = "";

		if(!$item->hasCompoundTag()){
			$name = "";
		}

		$tag = $item->getNamedTag();
		if($tag->hasTag("toolData")){
			$tag = $tag->getTag("toolData");
			if($tag instanceof CompoundTag and $tag->hasTag("Data") and $tag->getTag("Data") instanceof StringTag){
				$name = $tag->getTag("Data")->getValue();
			}
		}
		if(empty($this->tools[$name])) return null;
		return $this->tools[$name];
	}

	public function getBrushTool(Item $item, Player $player) : BrushTool{
		$tool = $this->getTool($item, $player);

		if($tool == null || !($tool instanceof BrushTool)){
			$tool = new BrushTool();
			$this->setTool($item, $tool, $player);
		}

		return $tool;
	}

	public function setTool(Item $item, Tool $tool, Player $player){
		if($item->getId() == self::$wandID){
			return;
		}

		$name = (string) microtime();
		echo($name);

		if(!$item->hasCompoundTag()){
			$tag = new CompoundTag("", []);
		}else{
			$tag = $item->getNamedTag();
		}

		if($tag->hasTag("toolData") and $tag->getTag("toolData") instanceof CompoundTag){
			$tag->getTag("toolData")->setTag(new StringTag("Data", $name));
		}else{
			$tag->setTag(new CompoundTag("toolData", [
				new StringTag("Data", $name)
			]));
		}

		$item->setCompoundTag($tag);

		$this->tools[$name] = $tool;
		$player->getInventory()->setItemInHand($item);
	}

	public function getPatternFactory() : PatternFactory{
		return $this->patternFactory;
	}

	public function getBlockFactory() : BlockFactory{
		return $this->blockFactory;
	}

	public function getBlockRegistry(){
		return $this->blockRegistry;
	}
}