<?php

namespace edit\util;

class Utils{

	public static function setPrivateValue($object, string $property, $value) : void{
		$reflectionClass = new \ReflectionClass($object);
		$property = $reflectionClass->getProperty($property);
		$property->setAccessible(true);
		$property->setValue($object, $value);
	}
}