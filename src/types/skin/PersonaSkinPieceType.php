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

namespace pocketmine\network\mcpe\protocol\types\skin;

use function array_search;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * As of 1.26.40, persona piece types are sent as a numeric enum instead of the persona_* strings used by the login
 * chain. This maps between the two so that {@link PersonaSkinPiece} can keep using the login representation.
 */
final class PersonaSkinPieceType{

	public const UNKNOWN = 0;
	public const SKELETON = 1;
	public const BODY = 2;
	public const SKIN = 3;
	public const BOTTOM = 4;
	public const FEET = 5;
	public const DRESS = 6;
	public const TOP = 7;
	public const HIGH_PANTS = 8;
	public const HANDS = 9;
	public const OUTERWEAR = 10;
	public const FACIAL_HAIR = 11;
	public const MOUTH = 12;
	public const EYES = 13;
	public const HAIR = 14;
	public const HOOD = 15;
	public const BACK = 16;
	public const FACE_ACCESSORY = 17;
	public const HEAD = 18;
	public const LEGS = 19;
	public const LEFT_LEG = 20;
	public const RIGHT_LEG = 21;
	public const ARMS = 22;
	public const LEFT_ARM = 23;
	public const RIGHT_ARM = 24;
	public const CAPES = 25;
	public const CLASSIC_SKIN = 26;
	public const EMOTE = 27;
	public const UNSUPPORTED = 28;

	/**
	 * Names as they appear in the login chain. The hands piece is singular there, but plural in the numeric enum.
	 *
	 * @var string[]
	 * @phpstan-var array<int, string>
	 */
	private const NAMES = [
		self::UNKNOWN => "persona_unknown",
		self::SKELETON => "persona_skeleton",
		self::BODY => "persona_body",
		self::SKIN => "persona_skin",
		self::BOTTOM => "persona_bottom",
		self::FEET => "persona_feet",
		self::DRESS => "persona_dress",
		self::TOP => "persona_top",
		self::HIGH_PANTS => "persona_high_pants",
		self::HANDS => "persona_hand",
		self::OUTERWEAR => "persona_outerwear",
		self::FACIAL_HAIR => "persona_facial_hair",
		self::MOUTH => "persona_mouth",
		self::EYES => "persona_eyes",
		self::HAIR => "persona_hair",
		self::HOOD => "persona_hood",
		self::BACK => "persona_back",
		self::FACE_ACCESSORY => "persona_face_accessory",
		self::HEAD => "persona_head",
		self::LEGS => "persona_legs",
		self::LEFT_LEG => "persona_left_leg",
		self::RIGHT_LEG => "persona_right_leg",
		self::ARMS => "persona_arms",
		self::LEFT_ARM => "persona_left_arm",
		self::RIGHT_ARM => "persona_right_arm",
		self::CAPES => "persona_capes",
		self::CLASSIC_SKIN => "persona_classic_skin",
		self::EMOTE => "persona_emote",
		self::UNSUPPORTED => "unsupported",
	];

	private function __construct(){
		//NOOP
	}

	public static function idFromName(string $name) : int{
		$id = array_search($name, self::NAMES, true);

		return $id === false ? self::UNKNOWN : $id;
	}

	public static function nameFromId(int $id) : string{
		return self::NAMES[$id] ?? self::NAMES[self::UNKNOWN];
	}

	/**
	 * Tint colours identify the piece they belong to by the short form of the name, without the persona_ prefix.
	 */
	public static function shortNameFromName(string $name) : string{
		if($name === self::NAMES[self::HANDS]){
			return "hands";
		}

		return str_starts_with($name, "persona_") ? substr($name, strlen("persona_")) : $name;
	}

	public static function nameFromShortName(string $shortName) : string{
		if($shortName === "hands"){
			return self::NAMES[self::HANDS];
		}
		if($shortName === self::NAMES[self::UNSUPPORTED]){
			return $shortName;
		}

		return "persona_" . $shortName;
	}
}
