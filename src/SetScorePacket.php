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
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntryAction;
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

	protected function decodePayload(ByteBufferReader $in, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			// valida el type name y usa doble optional en remove
			$hasChange = false;
			$this->entries = CommonTypes::readList($in, function(ByteBufferReader $in) use (&$hasChange) : ScorePacketEntry{
				$action = ScorePacketEntryAction::fromOrdinal(VarInt::readUnsignedInt($in));
				$innerType = CommonTypes::getString($in);
				if($action !== ScorePacketEntryAction::fromPacket($innerType)){
					throw new PacketDecodeException("Expected inner type {$action->value} for score packet entry ordinal {$action->toOrdinal()}, got $innerType");
				}

				$entry = new ScorePacketEntry();
				$entry->action = $action;
				$entry->type = match($action){
					ScorePacketEntryAction::REMOVE => ScorePacketEntry::TYPE_REMOVE,
					ScorePacketEntryAction::CHANGE_PLAYER => ScorePacketEntry::TYPE_PLAYER,
					ScorePacketEntryAction::CHANGE_ENTITY => ScorePacketEntry::TYPE_ENTITY,
					ScorePacketEntryAction::CHANGE_FAKE_PLAYER => ScorePacketEntry::TYPE_FAKE_PLAYER,
				};

				//same for all types
				$entry->scoreboardId = VarInt::readSignedLong($in);

				if($action === ScorePacketEntryAction::REMOVE){
					$entry->objectiveName = CommonTypes::readDoubleOptional($in, CommonTypes::getString(...));
				}elseif($action === ScorePacketEntryAction::CHANGE_PLAYER || $action === ScorePacketEntryAction::CHANGE_ENTITY){
					$hasChange = true;
					$entry->objectiveName = CommonTypes::getString($in);
					$entry->score = LE::readSignedInt($in);
					$entry->actorUniqueId = CommonTypes::getActorUniqueId($in);
				}elseif($action === ScorePacketEntryAction::CHANGE_FAKE_PLAYER){
					$hasChange = true;
					$entry->objectiveName = CommonTypes::getString($in);
					$entry->score = LE::readSignedInt($in);
					$entry->customName = CommonTypes::getString($in);
				}else{ // this should never be the case
					throw new \LogicException("Unhandled decode for action: " . $action->name);
				}

				return $entry;
			});
			$this->type = $hasChange ? self::TYPE_CHANGE : self::TYPE_REMOVE;
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

	protected function encodePayload(ByteBufferWriter $out, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			// usa $entry->action si viene seteado, si no lo saca de $type
			CommonTypes::writeList($out, $this->entries, function(ByteBufferWriter $out, ScorePacketEntry $entry) : void{
				$entryType = $this->type === self::TYPE_REMOVE ? ScorePacketEntry::TYPE_REMOVE : $entry->type;
				$action = $entry->action ?? match($entryType){
					ScorePacketEntry::TYPE_REMOVE => ScorePacketEntryAction::REMOVE,
					ScorePacketEntry::TYPE_PLAYER => ScorePacketEntryAction::CHANGE_PLAYER,
					ScorePacketEntry::TYPE_ENTITY => ScorePacketEntryAction::CHANGE_ENTITY,
					ScorePacketEntry::TYPE_FAKE_PLAYER => ScorePacketEntryAction::CHANGE_FAKE_PLAYER,
					default => throw new \InvalidArgumentException("Unknown entry type $entryType"),
				};

				VarInt::writeUnsignedInt($out, $action->toOrdinal());
				CommonTypes::putString($out, $action->value);

				//same for all types
				VarInt::writeSignedLong($out, $entry->scoreboardId);

				if($action === ScorePacketEntryAction::REMOVE){
					CommonTypes::writeDoubleOptional($out, $entry->objectiveName, CommonTypes::putString(...));
				}elseif($action === ScorePacketEntryAction::CHANGE_PLAYER || $action === ScorePacketEntryAction::CHANGE_ENTITY){
					CommonTypes::putString($out, $entry->objectiveName ?? throw new \InvalidArgumentException("Objective name must be set for player/entity entry"));
					LE::writeSignedInt($out, $entry->score);
					CommonTypes::putActorUniqueId($out, $entry->actorUniqueId ?? throw new \InvalidArgumentException("actorUniqueId must be set"));
				}elseif($action === ScorePacketEntryAction::CHANGE_FAKE_PLAYER){
					CommonTypes::putString($out, $entry->objectiveName ?? throw new \InvalidArgumentException("Objective name must be set for fake player entry"));
					LE::writeSignedInt($out, $entry->score);
					CommonTypes::putString($out, $entry->customName ?? throw new \InvalidArgumentException("customName must be set"));
				}else{
					throw new \InvalidArgumentException("Unhandled encode for action: " . $action->name);
				}
			});
			return;
		}

		Byte::writeUnsigned($out, $this->type);
		VarInt::writeUnsignedInt($out, count($this->entries));
		foreach($this->entries as $entry){
			VarInt::writeSignedLong($out, $entry->scoreboardId);
			CommonTypes::putString($out, $entry->objectiveName ?? "");
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
