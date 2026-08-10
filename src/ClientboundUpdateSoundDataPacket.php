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
use pmmp\encoding\LE;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\SoundDataUpdate;

class ClientboundUpdateSoundDataPacket extends DataPacket implements ClientboundPacket{
	public const NETWORK_ID = ProtocolInfo::CLIENTBOUND_UPDATE_SOUND_DATA_PACKET;

	/**
	 * As of 1.26.40 the packet carries one optional slot per update kind. The slot doesn't constrain which update it
	 * holds, so they're kept as a plain list here.
	 */
	public const MODERN_UPDATE_SLOTS = 7;

	private int $serverSoundHandle;
	private string $soundEvent;
	/**
	 * @var (SoundDataUpdate|null)[]
	 * @phpstan-var list<SoundDataUpdate|null>
	 */
	private array $updates = [];

	/**
	 * @generate-create-func
	 */
	public static function create(int $serverSoundHandle, string $soundEvent) : self{
		$result = new self;
		$result->serverSoundHandle = $serverSoundHandle;
		$result->soundEvent = $soundEvent;
		return $result;
	}

	/**
	 * @param (SoundDataUpdate|null)[] $updates
	 * @phpstan-param list<SoundDataUpdate|null> $updates
	 */
	public static function createModern(int $serverSoundHandle, array $updates) : self{
		$result = new self;
		$result->serverSoundHandle = $serverSoundHandle;
		$result->soundEvent = "";
		$result->updates = $updates;
		return $result;
	}

	public function getServerSoundHandle() : int{ return $this->serverSoundHandle; }

	/** Only used before 1.26.40. */
	public function getSoundEvent() : string{ return $this->soundEvent; }

	/**
	 * Only used as of 1.26.40.
	 *
	 * @return (SoundDataUpdate|null)[]
	 * @phpstan-return list<SoundDataUpdate|null>
	 */
	public function getUpdates() : array{ return $this->updates; }

	protected function decodePayload(ByteBufferReader $in, int $protocolId) : void{
		$this->serverSoundHandle = LE::readUnsignedLong($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			$this->soundEvent = "";
			$this->updates = [];
			for($i = 0; $i < self::MODERN_UPDATE_SLOTS; ++$i){
				$this->updates[] = CommonTypes::readOptional($in, SoundDataUpdate::read(...));
			}
			return;
		}
		$this->soundEvent = CommonTypes::getString($in);
	}

	protected function encodePayload(ByteBufferWriter $out, int $protocolId) : void{
		LE::writeUnsignedLong($out, $this->serverSoundHandle);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			for($i = 0; $i < self::MODERN_UPDATE_SLOTS; ++$i){
				CommonTypes::writeOptional($out, $this->updates[$i] ?? null, fn(ByteBufferWriter $out, SoundDataUpdate $update) => $update->write($out));
			}
			return;
		}
		CommonTypes::putString($out, $this->soundEvent);
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handleClientboundUpdateSoundData($this);
	}
}
