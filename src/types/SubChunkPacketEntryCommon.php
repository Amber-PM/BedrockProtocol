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

namespace pocketmine\network\mcpe\protocol\types;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;

final class SubChunkPacketEntryCommon{

	public function __construct(
		private SubChunkPositionOffset $offset,
		private int $requestResult,
		private string $terrainData,
		private ?SubChunkPacketHeightMapInfo $heightMap,
		private ?SubChunkPacketHeightMapInfo $renderHeightMap
	){}

	public function getOffset() : SubChunkPositionOffset{ return $this->offset; }

	public function getRequestResult() : int{ return $this->requestResult; }

	public function getTerrainData() : string{ return $this->terrainData; }

	public function getHeightMap() : ?SubChunkPacketHeightMapInfo{ return $this->heightMap; }

	public function getRenderHeightMap() : ?SubChunkPacketHeightMapInfo{ return $this->renderHeightMap; }

	public static function read(ByteBufferReader $in, int $protocolId, bool $cacheEnabled) : self{
		$offset = SubChunkPositionOffset::read($in);

		$requestResult = Byte::readUnsigned($in);

		$modern = $protocolId >= ProtocolInfo::PROTOCOL_1_26_40;

		//as of 1.26.40 the payload and the height maps are optionals, no longer implied by the result/type
		if($modern){
			$data = CommonTypes::getBool($in) ? CommonTypes::getString($in) : "";
		}else{
			$data = !$cacheEnabled || $requestResult !== SubChunkRequestResult::SUCCESS_ALL_AIR ? CommonTypes::getString($in) : "";
		}

		$heightMapDataType = Byte::readUnsigned($in);
		$rawHeightMapData = $modern ?
			(CommonTypes::getBool($in) ? SubChunkPacketHeightMapInfo::read($in) : null) :
			null;
		$heightMapData = match ($heightMapDataType) {
			SubChunkPacketHeightMapType::NO_DATA => null,
			SubChunkPacketHeightMapType::DATA => $modern ? $rawHeightMapData : SubChunkPacketHeightMapInfo::read($in),
			SubChunkPacketHeightMapType::ALL_TOO_HIGH => SubChunkPacketHeightMapInfo::allTooHigh(),
			SubChunkPacketHeightMapType::ALL_TOO_LOW => SubChunkPacketHeightMapInfo::allTooLow(),
			default => throw new PacketDecodeException("Unknown heightmap data type $heightMapDataType")
		};

		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_90){
			$renderHeightMapDataType = Byte::readUnsigned($in);
			$rawRenderHeightMapData = $modern ?
				(CommonTypes::getBool($in) ? SubChunkPacketHeightMapInfo::read($in) : null) :
				null;
			$renderHeightMapData = match ($renderHeightMapDataType) {
				SubChunkPacketHeightMapType::NO_DATA => null,
				SubChunkPacketHeightMapType::DATA => $modern ? $rawRenderHeightMapData : SubChunkPacketHeightMapInfo::read($in),
				SubChunkPacketHeightMapType::ALL_TOO_HIGH => SubChunkPacketHeightMapInfo::allTooHigh(),
				SubChunkPacketHeightMapType::ALL_TOO_LOW => SubChunkPacketHeightMapInfo::allTooLow(),
				SubChunkPacketHeightMapType::ALL_COPIED => $heightMapData,
				default => throw new PacketDecodeException("Unknown render heightmap data type $renderHeightMapDataType")
			};
		}

		return new self(
			$offset,
			$requestResult,
			$data,
			$heightMapData,
			$renderHeightMapData ?? null,
		);
	}

	public function write(ByteBufferWriter $out, int $protocolId, bool $cacheEnabled) : void{
		$this->offset->write($out);

		Byte::writeUnsigned($out, $this->requestResult);

		$modern = $protocolId >= ProtocolInfo::PROTOCOL_1_26_40;

		if($modern){
			$hasTerrainData = $this->requestResult !== SubChunkRequestResult::SUCCESS_ALL_AIR;
			CommonTypes::putBool($out, $hasTerrainData);
			if($hasTerrainData){
				CommonTypes::putString($out, $this->terrainData);
			}
		}elseif(!$cacheEnabled || $this->requestResult !== SubChunkRequestResult::SUCCESS_ALL_AIR){
			CommonTypes::putString($out, $this->terrainData);
		}

		self::writeHeightMap($out, $modern, $this->heightMap, SubChunkPacketHeightMapType::NO_DATA);

		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_90){
			self::writeHeightMap($out, $modern, $this->renderHeightMap, SubChunkPacketHeightMapType::ALL_COPIED);
		}
	}

	/**
	 * @param int $nullType the type to send when the height map isn't set at all
	 */
	private static function writeHeightMap(ByteBufferWriter $out, bool $modern, ?SubChunkPacketHeightMapInfo $heightMap, int $nullType) : void{
		$data = null;
		if($heightMap === null){
			$type = $nullType;
		}elseif($heightMap->isAllTooLow()){
			$type = SubChunkPacketHeightMapType::ALL_TOO_LOW;
		}elseif($heightMap->isAllTooHigh()){
			$type = SubChunkPacketHeightMapType::ALL_TOO_HIGH;
		}else{
			$type = SubChunkPacketHeightMapType::DATA;
			$data = $heightMap;
		}

		Byte::writeUnsigned($out, $type);
		if($modern){
			//as of 1.26.40 the data is an optional, sent independently of the type
			CommonTypes::putBool($out, $data !== null);
		}
		$data?->write($out);
	}
}
