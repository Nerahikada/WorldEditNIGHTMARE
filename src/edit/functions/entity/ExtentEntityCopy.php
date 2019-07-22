<?php

namespace edit\functions\entity;

use pocketmine\entity\Entity;

use edit\jnbt\CompoundTag;
use edit\jnbt\CompoundTagBuilder;
use edit\blocks\BaseBlock;
use edit\extent\Extent;
use edit\functions\EntityFunction;
use edit\math\transform\Transform;
use edit\internal\helper\MCDirections;
use edit\history\change\EntityRemove;
use edit\util\Direction;
use edit\util\DirectionFlag;
use edit\util\Location;
use edit\Vector;
use edit\EditSession;

class ExtentEntityCopy implements EntityFunction{

	private $destination;
	private $from;
	private $to;
	private $transform;
	private $removing;

	public function __construct(Vector $from, Extent $destination, Vector $to, Transform $transform){
		$this->from = $from;
		$this->destination = $destination;
		$this->to = $to;
		$this->transform = $transform;
	}

	public function isRemoving() : bool{
		return $this->removing;
	}

	public function setRemoving(bool $removing){
		$this->removing = $removing;
	}

	public function apply(Entity $entity) : bool{
		$position = new Vector($entity->x, $entity->y, $entity->z);
		$orig = $position->subtract($this->from);
		$transformed = $this->transform->apply($orig);

		$newLocation = new Location($this->destination, $transformed->add($this->to), $entity->getYaw(), $entity->getPitch());

		$entity = $this->transformNbtData($entity);

		$success = $this->destination->createEntity($newLocation, $entity) != null;

		if($this->isRemoving() && $success){
			if($this->destination instanceof EditSession) $this->destination->changeMemory->add(new EntityRemove($newLocation, $entity));
			$entity->close();
		}

		return $success;
	}

	private function transformNbtData(Entity $state) : Entity{
		return $state;
	}
}