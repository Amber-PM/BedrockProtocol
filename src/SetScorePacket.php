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

namespace pocketmine\network\mcpe\protocol;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use function count;

class SetScorePacket extends DataPacket implements ClientboundPacket{
	public const NETWORK_ID = ProtocolInfo::SET_SCORE_PACKET;

	public const TYPE_CHANGE = 0;
	public const TYPE_REMOVE = 1;

	public int $type;
	/** @var ScorePacketEntry[] */
	public array $entries = [];

	/**
	 * @generate-create-func
	 * @param ScorePacketEntry[] $entries
	 */
	public static function create(int $type, array $entries) : self{
		$result = new self;
		$result->type = $type;
		$result->entries = $entries;
		return $result;
	}

	/**
	 * As of 1.26.40 every entry describes itself: the identity type doubles as the cereal variant (with 0 meaning
	 * removal), and is followed by the name of that variant.
	 */
	private const MODERN_VARIANT_NAMES = [
		0 => "remove",
		ScorePacketEntry::TYPE_PLAYER => "changeplayer",
		ScorePacketEntry::TYPE_ENTITY => "changeentity",
		ScorePacketEntry::TYPE_FAKE_PLAYER => "changefakeplayer",
	];

	protected function decodePayload(ByteBufferReader $in, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			$this->decodeModernPayload($in);
			return;
		}

		$this->type = Byte::readUnsigned($in);
		for($i = 0, $i2 = VarInt::readUnsignedInt($in); $i < $i2; ++$i){
			$entry = new ScorePacketEntry();
			$entry->scoreboardId = VarInt::readSignedLong($in);
			$entry->objectiveName = CommonTypes::getString($in);
			$entry->score = LE::readSignedInt($in);
			if($this->type !== self::TYPE_REMOVE){
				$entry->type = Byte::readUnsigned($in);
				switch($entry->type){
					case ScorePacketEntry::TYPE_PLAYER:
					case ScorePacketEntry::TYPE_ENTITY:
						$entry->actorUniqueId = CommonTypes::getActorUniqueId($in);
						break;
					case ScorePacketEntry::TYPE_FAKE_PLAYER:
						$entry->customName = CommonTypes::getString($in);
						break;
					default:
						throw new PacketDecodeException("Unknown entry type $entry->type");
				}
			}
			$this->entries[] = $entry;
		}
	}

	/**
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	private function decodeModernPayload(ByteBufferReader $in) : void{
		$this->type = self::TYPE_CHANGE;
		for($i = 0, $i2 = VarInt::readUnsignedInt($in); $i < $i2; ++$i){
			$variant = VarInt::readUnsignedInt($in);
			if(!isset(self::MODERN_VARIANT_NAMES[$variant])){
				throw new PacketDecodeException("Unknown scoreboard entry variant $variant");
			}
			CommonTypes::getString($in); //variant name

			$entry = new ScorePacketEntry();
			$entry->scoreboardId = VarInt::readSignedLong($in);
			if($variant === 0){
				$this->type = self::TYPE_REMOVE;
				$entry->objectiveName = CommonTypes::getBool($in) ? CommonTypes::getString($in) : "";
				$entry->score = 0;
			}else{
				$entry->type = $variant;
				$entry->objectiveName = CommonTypes::getString($in);
				$entry->score = LE::readSignedInt($in);
				if($variant === ScorePacketEntry::TYPE_FAKE_PLAYER){
					$entry->customName = CommonTypes::getString($in);
				}else{
					$entry->actorUniqueId = CommonTypes::getActorUniqueId($in);
				}
			}
			$this->entries[] = $entry;
		}
	}

	private function encodeModernPayload(ByteBufferWriter $out) : void{
		VarInt::writeUnsignedInt($out, count($this->entries));
		foreach($this->entries as $entry){
			$variant = $this->type === self::TYPE_REMOVE ? 0 : $entry->type;
			if(!isset(self::MODERN_VARIANT_NAMES[$variant])){
				throw new \InvalidArgumentException("Unknown entry type $variant");
			}
			VarInt::writeUnsignedInt($out, $variant);
			CommonTypes::putString($out, self::MODERN_VARIANT_NAMES[$variant]);

			VarInt::writeSignedLong($out, $entry->scoreboardId);
			if($variant === 0){
				CommonTypes::writeOptional($out, $entry->objectiveName !== "" ? $entry->objectiveName : null, CommonTypes::putString(...));
			}else{
				CommonTypes::putString($out, $entry->objectiveName);
				LE::writeSignedInt($out, $entry->score);
				if($variant === ScorePacketEntry::TYPE_FAKE_PLAYER){
					CommonTypes::putString($out, $entry->customName ?? "");
				}else{
					CommonTypes::putActorUniqueId($out, $entry->actorUniqueId ?? 0);
				}
			}
		}
	}

	protected function encodePayload(ByteBufferWriter $out, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			$this->encodeModernPayload($out);
			return;
		}

		Byte::writeUnsigned($out, $this->type);
		VarInt::writeUnsignedInt($out, count($this->entries));
		foreach($this->entries as $entry){
			VarInt::writeSignedLong($out, $entry->scoreboardId);
			CommonTypes::putString($out, $entry->objectiveName);
			LE::writeSignedInt($out, $entry->score);
			if($this->type !== self::TYPE_REMOVE){
				Byte::writeUnsigned($out, $entry->type);
				switch($entry->type){
					case ScorePacketEntry::TYPE_PLAYER:
					case ScorePacketEntry::TYPE_ENTITY:
						CommonTypes::putActorUniqueId($out, $entry->actorUniqueId);
						break;
					case ScorePacketEntry::TYPE_FAKE_PLAYER:
						CommonTypes::putString($out, $entry->customName);
						break;
					default:
						throw new \InvalidArgumentException("Unknown entry type $entry->type");
				}
			}
		}
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handleSetScore($this);
	}
}
