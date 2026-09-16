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

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\math\Vector2;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\camera\CameraPreset;

class CameraPresetTest extends TestCase{

	private function createSamplePreset() : CameraPreset{
		return new CameraPreset(
			name: "test:camera",
			parent: "minecraft:free",
			xPosition: 1.0,
			yPosition: 2.0,
			zPosition: 3.0,
			pitch: 10.0,
			yaw: 20.0,
			rotationSpeed: null,
			snapToTarget: null,
			horizontalRotationLimit: null,
			verticalRotationLimit: null,
			continueTargeting: null,
			blockListeningRadius: null,
			viewOffset: null,
			entityOffset: null,
			radius: null,
			yawLimitMin: null,
			yawLimitMax: null,
			audioListenerType: null,
			playerEffects: null,
			alignTargetAndCameraForward: null,
			aimAssist: null,
			controlScheme: null,
		);
	}

	public function testStartingRotationNullWireEquivalence() : void{
		foreach([ProtocolInfo::PROTOCOL_1_26_50, ProtocolInfo::PROTOCOL_1_26_51] as $protocolId){
			$wOptional = new ByteBufferWriter();
			/** @var Vector2|null $nullVector */
			$nullVector = null;
			CommonTypes::writeOptional($wOptional, $nullVector, CommonTypes::putVector2(...));

			$wBool = new ByteBufferWriter();
			CommonTypes::putBool($wBool, false);

			self::assertSame($wOptional->getData(), $wBool->getData(), "Protocol $protocolId optional null must match putBool(false)");
			self::assertSame("\x00", $wBool->getData());
		}
	}

	public function testCameraPresetWriteWireIdentityAcrossProtocols() : void{
		$preset = $this->createSamplePreset();

		foreach([ProtocolInfo::PROTOCOL_1_26_50, ProtocolInfo::PROTOCOL_1_26_51] as $protocolId){
			$writerActual = new ByteBufferWriter();
			$preset->write($writerActual, $protocolId);

			$reader = new ByteBufferReader($writerActual->getData());
			$decoded = CameraPreset::read($reader, $protocolId);

			self::assertSame(0, $reader->getUnreadLength(), "Stream must be fully consumed for protocol $protocolId");
			self::assertSame($preset->getName(), $decoded->getName());
			self::assertSame($preset->getParent(), $decoded->getParent());
		}
	}
}
