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

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class GatheringJoinInfo{

	public function __construct(
		private UuidInterface $experienceId,
		private string $experienceName,
		private UuidInterface $experienceWorldId,
		private string $experienceWorldName,
		private string $creatorId,
		private UuidInterface $targetId,
		private string $scenarioId,
		private string $serverId,
	){}

	public function getExperienceId() : UuidInterface{ return $this->experienceId; }

	public function getExperienceName() : string{ return $this->experienceName; }

	public function getExperienceWorldId() : UuidInterface{ return $this->experienceWorldId; }

	public function getExperienceWorldName() : string{ return $this->experienceWorldName; }

	public function getCreatorId() : string{ return $this->creatorId; }

	public function getTargetId() : UuidInterface{ return $this->targetId; }

	public function getScenarioId() : string{ return $this->scenarioId; }

	public function getServerId() : string{ return $this->serverId; }

	public static function read(ByteBufferReader $in, int $protocolId) : self{
		$experienceId = CommonTypes::getUUID($in);
		$experienceName = CommonTypes::getString($in);
		//as of 1.26.40 everything but the experience ID, name and creator is optional
		$modern = $protocolId >= ProtocolInfo::PROTOCOL_1_26_40;
		$experienceWorldId = $modern ? CommonTypes::readOptional($in, CommonTypes::getUUID(...)) : CommonTypes::getUUID($in);
		$experienceWorldName = $modern ? CommonTypes::readOptional($in, CommonTypes::getString(...)) : CommonTypes::getString($in);
		$creatorId = CommonTypes::getString($in);
		if($modern){
			$targetId = CommonTypes::readOptional($in, CommonTypes::getUUID(...));
			$scenarioId = CommonTypes::readOptional($in, CommonTypes::getString(...));
			$serverId = CommonTypes::readOptional($in, CommonTypes::getString(...));
		}elseif($protocolId >= ProtocolInfo::PROTOCOL_1_26_10){
			$targetId = CommonTypes::getUUID($in);
			$scenarioId = CommonTypes::getString($in);
			$serverId = CommonTypes::getString($in);
		}

		return new self(
			$experienceId,
			$experienceName,
			$experienceWorldId ?? Uuid::uuid4(),
			$experienceWorldName ?? "",
			$creatorId,
			$targetId ?? Uuid::uuid4(),
			$scenarioId ?? "",
			$serverId ?? "",
		);
	}

	public function write(ByteBufferWriter $out, int $protocolId) : void{
		CommonTypes::putUUID($out, $this->experienceId);
		CommonTypes::putString($out, $this->experienceName);
		$modern = $protocolId >= ProtocolInfo::PROTOCOL_1_26_40;
		if($modern){
			CommonTypes::writeOptional($out, $this->experienceWorldId, CommonTypes::putUUID(...));
			CommonTypes::writeOptional($out, $this->experienceWorldName, CommonTypes::putString(...));
		}else{
			CommonTypes::putUUID($out, $this->experienceWorldId);
			CommonTypes::putString($out, $this->experienceWorldName);
		}
		CommonTypes::putString($out, $this->creatorId);
		if($modern){
			CommonTypes::writeOptional($out, $this->targetId, CommonTypes::putUUID(...));
			CommonTypes::writeOptional($out, $this->scenarioId, CommonTypes::putString(...));
			CommonTypes::writeOptional($out, $this->serverId, CommonTypes::putString(...));
		}elseif($protocolId >= ProtocolInfo::PROTOCOL_1_26_10){
			CommonTypes::putUUID($out, $this->targetId);
			CommonTypes::putString($out, $this->scenarioId);
			CommonTypes::putString($out, $this->serverId);
		}
	}
}
