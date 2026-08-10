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

namespace pocketmine\network\mcpe\protocol\types\inventory;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;

/**
 * Item format used by the deprecated craft-results item stack request action as of 1.26.40. Unlike a regular item
 * stack, it identifies the item by name rather than by network ID.
 */
final class StackRequestItem{

	private const VARIANT_EMPTY = 0;
	private const VARIANT_ITEM = 1;

	public function __construct(
		private string $identifier,
		private int $meta,
		private int $count,
		private int $blockRuntimeId,
		private string $rawExtraData
	){}

	public function getIdentifier() : string{ return $this->identifier; }

	public function getMeta() : int{ return $this->meta; }

	public function getCount() : int{ return $this->count; }

	public function getBlockRuntimeId() : int{ return $this->blockRuntimeId; }

	public function getRawExtraData() : string{ return $this->rawExtraData; }

	/**
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	public static function read(ByteBufferReader $in) : self{
		$variant = VarInt::readUnsignedInt($in);
		$legacyVariant = Byte::readUnsigned($in);
		if($variant !== $legacyVariant){
			throw new PacketDecodeException("Stack request item legacy variant $legacyVariant does not match variant $variant");
		}
		if($variant === self::VARIANT_ITEM){
			$identifier = CommonTypes::getString($in);
			$meta = VarInt::readSignedInt($in);
		}elseif($variant === self::VARIANT_EMPTY){
			$identifier = "";
			$meta = 0;
		}else{
			throw new PacketDecodeException("Unknown stack request item variant $variant");
		}
		$count = LE::readSignedShort($in);
		$blockRuntimeId = VarInt::readUnsignedInt($in);
		$rawExtraData = CommonTypes::getString($in);

		return new self($identifier, $meta, $count, $blockRuntimeId, $rawExtraData);
	}

	public function write(ByteBufferWriter $out) : void{
		$variant = $this->identifier !== "" ? self::VARIANT_ITEM : self::VARIANT_EMPTY;
		VarInt::writeUnsignedInt($out, $variant);
		Byte::writeUnsigned($out, $variant);
		if($variant === self::VARIANT_ITEM){
			CommonTypes::putString($out, $this->identifier);
			VarInt::writeSignedInt($out, $this->meta);
		}
		LE::writeSignedShort($out, $this->count);
		VarInt::writeUnsignedInt($out, $this->blockRuntimeId);
		CommonTypes::putString($out, $this->rawExtraData);
	}
}
