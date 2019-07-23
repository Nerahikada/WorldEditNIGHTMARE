<?php

namespace edit\util;

class Utils{

	public static function setPrivateValue($object, string $property, $value) : void{
		$reflectionClass = new \ReflectionClass($object);
		if(!$reflectionClass->hasProperty($property)){
			return;
		}
		$property = $reflectionClass->getProperty($property);
		$property->setAccessible(true);
		$property->setValue($object, $value);
	}

	public static function getPrivateValue($object, string $property){
		$reflectionClass = new \ReflectionClass($object);
		// Throw an exception if the property does not exist ...?
		$property = $reflectionClass->getProperty($property);
		$property->setAccessible(true);
		return $property->getValue($object);
	}
}