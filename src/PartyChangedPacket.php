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

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\PlayerPartyInfo;

class PartyChangedPacket extends DataPacket implements ServerboundPacket{
	public const NETWORK_ID = ProtocolInfo::PARTY_CHANGED_PACKET;

	private string $partyId;
	private bool $partyLeader;

	/**
	 * @generate-create-func
	 */
	public static function create(string $partyId, bool $partyLeader) : self{
		$result = new self;
		$result->partyId = $partyId;
		$result->partyLeader = $partyLeader;
		return $result;
	}

	public function getPartyId() : string{ return $this->partyId; }

	public function isPartyLeader() : bool{ return $this->partyLeader; }

	//an empty party id means the player left the party
	public function getPartyInfo() : ?PlayerPartyInfo{
		return $this->partyId === "" ? null : new PlayerPartyInfo($this->partyId, $this->partyLeader);
	}

	protected function decodePayload(ByteBufferReader $in, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_60){
			$info = CommonTypes::readOptional($in, PlayerPartyInfo::read(...));
			$this->partyId = $info?->getPartyId() ?? "";
			$this->partyLeader = $info?->isPartyLeader() ?? false;
			return;
		}
		$this->partyId = CommonTypes::getString($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_20){
			$this->partyLeader = CommonTypes::getBool($in);
		}
	}

	protected function encodePayload(ByteBufferWriter $out, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_60){
			CommonTypes::writeOptional($out, $this->getPartyInfo(), static fn(ByteBufferWriter $out, PlayerPartyInfo $info) => $info->write($out));
			return;
		}
		CommonTypes::putString($out, $this->partyId);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_20){
			CommonTypes::putBool($out, $this->partyLeader);
		}
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handlePartyChanged($this);
	}
}
