<?php

/*
 * This file is part of BedrockProtocol.
 * Copyright (C) 2014-2022 PocketMine Team <https://github.com/pmmp/BedrockProtocol>
 *
 * BedrockProtocol is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol\types\inventory\stackrequest;

use pocketmine\network\mcpe\protocol\ProtocolInfo;
use function array_search;

final class ItemStackRequestActionType{

	private function __construct(){
		//NOOP
	}

	public const TAKE = 0;
	public const PLACE = 1;
	public const SWAP = 2;
	public const DROP = 3;
	public const DESTROY = 4;
	public const CRAFTING_CONSUME_INPUT = 5;
	public const CRAFTING_CREATE_SPECIFIC_RESULT = 6;
	public const LAB_TABLE_COMBINE = 7;
	public const BEACON_PAYMENT = 8;
	public const MINE_BLOCK = 9;
	public const CRAFTING_RECIPE = 10;
	public const CRAFTING_RECIPE_AUTO = 11; //recipe book?
	public const CREATIVE_CREATE = 12;
	public const CRAFTING_RECIPE_OPTIONAL = 13; //anvil/cartography table rename
	public const CRAFTING_GRINDSTONE = 14;
	public const CRAFTING_LOOM = 15;
	public const CRAFTING_NON_IMPLEMENTED_DEPRECATED_ASK_TY_LAING = 16;
	public const CRAFTING_RESULTS_DEPRECATED_ASK_TY_LAING = 17; //no idea what this is for
	public const PLACE_INTO_BUNDLE = 18; //no longer sent since 1.26.40
	public const TAKE_FROM_BUNDLE = 19; //no longer sent since 1.26.40
	public const CRAFTING_RESERVED = 20; //1.26.60 and later

	public const INNER_TYPES = [
		self::TAKE => 0,
		self::PLACE => 1,
		self::SWAP => 2,
		self::DROP => 3,
		self::DESTROY => 4,
		self::CRAFTING_CONSUME_INPUT => 5,
		self::CRAFTING_CREATE_SPECIFIC_RESULT => 6,
		self::PLACE_INTO_BUNDLE => 7, //PlaceItemInContainer_DEPRECATED since 1.26.40
		self::TAKE_FROM_BUNDLE => 8, //TakeItemFromContainer_DEPRECATED since 1.26.40
		self::LAB_TABLE_COMBINE => 9,
		self::BEACON_PAYMENT => 10,
		self::MINE_BLOCK => 11,
		self::CRAFTING_RECIPE => 12,
		self::CRAFTING_RECIPE_AUTO => 13, //recipe book?
		self::CREATIVE_CREATE => 14,
		self::CRAFTING_RECIPE_OPTIONAL => 15, //anvil/cartography table rename
		self::CRAFTING_GRINDSTONE => 16,
		self::CRAFTING_LOOM => 17,
		self::CRAFTING_NON_IMPLEMENTED_DEPRECATED_ASK_TY_LAING => 18,
		self::CRAFTING_RESULTS_DEPRECATED_ASK_TY_LAING => 19, //no idea what this is for
	];

	//1.26.60 inserted the reserved action before the deprecated ones so their wire ids moved up by one
	private const OUTER_TYPES_1_26_60 = [
		self::CRAFTING_RESERVED => 16,
		self::CRAFTING_NON_IMPLEMENTED_DEPRECATED_ASK_TY_LAING => 17,
		self::CRAFTING_RESULTS_DEPRECATED_ASK_TY_LAING => 18,
	];
	private const INNER_TYPES_1_26_60 = [
		self::CRAFTING_RESERVED => 18,
		self::CRAFTING_NON_IMPLEMENTED_DEPRECATED_ASK_TY_LAING => 19,
		self::CRAFTING_RESULTS_DEPRECATED_ASK_TY_LAING => 20,
	];

	public static function toOuterType(int $type, int $protocolId) : ?int{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_60){
			return self::OUTER_TYPES_1_26_60[$type] ?? ($type === self::PLACE_INTO_BUNDLE || $type === self::TAKE_FROM_BUNDLE ? null : $type);
		}
		return $type === self::CRAFTING_RESERVED ? null : $type;
	}

	public static function fromOuterType(int $outerType, int $protocolId) : ?int{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_60){
			$type = array_search($outerType, self::OUTER_TYPES_1_26_60, true);
			if($type !== false){
				return $type;
			}
			return $outerType <= self::CRAFTING_LOOM ? $outerType : null;
		}
		return $outerType === self::CRAFTING_RESERVED ? null : $outerType;
	}

	public static function toInnerType(int $type, int $protocolId) : ?int{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_60 && isset(self::INNER_TYPES_1_26_60[$type])){
			return self::INNER_TYPES_1_26_60[$type];
		}
		return $type === self::CRAFTING_RESERVED ? null : (self::INNER_TYPES[$type] ?? null);
	}
}
