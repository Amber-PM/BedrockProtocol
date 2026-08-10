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
use function count;

class ResourcePackClientResponsePacket extends DataPacket implements ServerboundPacket{
	public const NETWORK_ID = ProtocolInfo::RESOURCE_PACK_CLIENT_RESPONSE_PACKET;

	public const STATUS_REFUSED = 1;
	public const STATUS_SEND_PACKS = 2;
	public const STATUS_HAVE_ALL_PACKS = 3;
	public const STATUS_COMPLETED = 4;

	public int $status;
	/** @var string[] */
	public array $packIds = [];

	/**
	 * @generate-create-func
	 * @param string[] $packIds
	 */
	public static function create(int $status, array $packIds) : self{
		$result = new self;
		$result->status = $status;
		$result->packIds = $packIds;
		return $result;
	}

	/**
	 * As of 1.26.40 the status is zero-based and followed by the name of the variant it was sent under. The pack list
	 * is only sent when packs are actually being requested.
	 */
	private const MODERN_STATUS_NAMES = [
		self::STATUS_REFUSED => "cancel",
		self::STATUS_SEND_PACKS => "downloading",
		self::STATUS_HAVE_ALL_PACKS => "downloadingfinished",
		self::STATUS_COMPLETED => "resourcepackstackfinished",
	];

	protected function decodePayload(ByteBufferReader $in, int $protocolId) : void{
		$this->packIds = [];
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			$this->status = VarInt::readUnsignedInt($in) + 1;
			if(!isset(self::MODERN_STATUS_NAMES[$this->status])){
				throw new PacketDecodeException("Unknown resource pack response status $this->status");
			}
			CommonTypes::getString($in); //variant name
			if($this->status === self::STATUS_SEND_PACKS){
				for($i = 0, $count = VarInt::readUnsignedInt($in); $i < $count; ++$i){
					$this->packIds[] = CommonTypes::getString($in);
				}
			}
			return;
		}

		$this->status = Byte::readUnsigned($in);
		$entryCount = LE::readUnsignedShort($in);
		while($entryCount-- > 0){
			$this->packIds[] = CommonTypes::getString($in);
		}
	}

	protected function encodePayload(ByteBufferWriter $out, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			if(!isset(self::MODERN_STATUS_NAMES[$this->status])){
				throw new \InvalidArgumentException("Unknown resource pack response status $this->status");
			}
			VarInt::writeUnsignedInt($out, $this->status - 1);
			CommonTypes::putString($out, self::MODERN_STATUS_NAMES[$this->status]);
			if($this->status === self::STATUS_SEND_PACKS){
				VarInt::writeUnsignedInt($out, count($this->packIds));
				foreach($this->packIds as $id){
					CommonTypes::putString($out, $id);
				}
			}
			return;
		}

		Byte::writeUnsigned($out, $this->status);
		LE::writeUnsignedShort($out, count($this->packIds));
		foreach($this->packIds as $id){
			CommonTypes::putString($out, $id);
		}
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handleResourcePackClientResponse($this);
	}
}
