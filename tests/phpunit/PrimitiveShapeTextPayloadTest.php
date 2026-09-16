<?php

/*
 * This file is part of BedrockProtocol.
 * Copyright (C) 2014-2022 PocketMine Team <https://github.com/Amber-PM/BedrockProtocol>
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
use pmmp\encoding\LE;
use pocketmine\color\Color;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\shape\PacketShapeData;
use pocketmine\network\mcpe\protocol\types\shape\PrimitiveShapeTextPayload;

class PrimitiveShapeTextPayloadTest extends TestCase{

	public function testDirectSerializationLegacyProtocol() : void{
		$payload = new PrimitiveShapeTextPayload("hello", true, null, true, false, true, 2.5);
		$writer = new ByteBufferWriter();
		$payload->write($writer, ProtocolInfo::PROTOCOL_1_26_44);

		$reader = new ByteBufferReader($writer->getData());
		$decoded = PrimitiveShapeTextPayload::read($reader, ProtocolInfo::PROTOCOL_1_26_44);

		self::assertSame("hello", $decoded->getText());
		self::assertTrue($decoded->useRotation());
		self::assertNull($decoded->getBackgroundColor());
		self::assertTrue($decoded->hasDepthTest());
		self::assertFalse($decoded->hasShowBackface());
		self::assertTrue($decoded->hasShowTextBackface());
		self::assertSame(0.0, $decoded->getLineGapHeight());
		self::assertSame(0, $reader->getUnreadLength());
	}

	public function testDirectSerializationNewProtocols() : void{
		foreach([ProtocolInfo::PROTOCOL_1_26_50, ProtocolInfo::PROTOCOL_1_26_51] as $protocolId){
			$payload = new PrimitiveShapeTextPayload("world", false, new Color(255, 0, 128, 255), false, true, false, 3.75);
			$writer = new ByteBufferWriter();
			$payload->write($writer, $protocolId);

			$reader = new ByteBufferReader($writer->getData());
			$decoded = PrimitiveShapeTextPayload::read($reader, $protocolId);

			self::assertSame("world", $decoded->getText());
			self::assertFalse($decoded->useRotation());
			self::assertNotNull($decoded->getBackgroundColor());
			self::assertSame(255, $decoded->getBackgroundColor()->getR());
			self::assertSame(0, $decoded->getBackgroundColor()->getG());
			self::assertSame(128, $decoded->getBackgroundColor()->getB());
			self::assertFalse($decoded->hasDepthTest());
			self::assertTrue($decoded->hasShowBackface());
			self::assertFalse($decoded->hasShowTextBackface());
			self::assertSame(3.75, $decoded->getLineGapHeight());
			self::assertSame(0, $reader->getUnreadLength());
		}
	}

	public function testMissingProtocolThrows() : void{
		$payload = new PrimitiveShapeTextPayload("test", false, null, true, true, true, 1.0);
		$writer = new ByteBufferWriter();

		$this->expectException(\InvalidArgumentException::class);
		$payload->write($writer);
	}

	public function testPacketShapeDataRoundtripLegacy() : void{
		$shape = PacketShapeData::text(
			networkId: 42,
			location: new Vector3(10, 20, 30),
			text: "legacy test",
			lineGapHeight: 4.5
		);

		$writer = new ByteBufferWriter();
		$shape->write($writer, ProtocolInfo::PROTOCOL_1_26_44);

		$reader = new ByteBufferReader($writer->getData());
		$decoded = PacketShapeData::read($reader, ProtocolInfo::PROTOCOL_1_26_44);

		$payload = $decoded->getPayload();
		self::assertInstanceOf(PrimitiveShapeTextPayload::class, $payload);
		self::assertSame("legacy test", $payload->getText());
		self::assertSame(0.0, $payload->getLineGapHeight());
	}

	public function testPacketShapeDataRoundtripNew() : void{
		$shape = PacketShapeData::text(
			networkId: 42,
			location: new Vector3(10, 20, 30),
			text: "new test",
			lineGapHeight: 4.5
		);

		$writer = new ByteBufferWriter();
		$shape->write($writer, ProtocolInfo::PROTOCOL_1_26_50);

		$reader = new ByteBufferReader($writer->getData());
		$decoded = PacketShapeData::read($reader, ProtocolInfo::PROTOCOL_1_26_50);

		$payload = $decoded->getPayload();
		self::assertInstanceOf(PrimitiveShapeTextPayload::class, $payload);
		self::assertSame("new test", $payload->getText());
		self::assertSame(4.5, $payload->getLineGapHeight());
	}

	public function testWireBytePositioning() : void{
		$payload = new PrimitiveShapeTextPayload("A", false, null, true, true, true, 1.5);

		$writerLegacy = new ByteBufferWriter();
		$payload->write($writerLegacy, ProtocolInfo::PROTOCOL_1_26_44);
		$legacyBytes = $writerLegacy->getData();

		$writerNew = new ByteBufferWriter();
		$payload->write($writerNew, ProtocolInfo::PROTOCOL_1_26_50);
		$newBytes = $writerNew->getData();

		// new layout is exactly 4 bytes longer (LE float)
		self::assertSame(strlen($legacyBytes) + 4, strlen($newBytes));

		// "A" (VarInt 1 + 'A' = 2 bytes), bool useRotation (1 byte), optional color (1 byte \x00) -> prefix is 4 bytes
		$prefixLen = 4;
		self::assertSame(substr($legacyBytes, 0, $prefixLen), substr($newBytes, 0, $prefixLen));

		// next 4 bytes in new layout must be LE float 1.5
		$floatWriter = new ByteBufferWriter();
		LE::writeFloat($floatWriter, 1.5);
		self::assertSame($floatWriter->getData(), substr($newBytes, $prefixLen, 4));

		// remaining bytes (depthTest, showBackface, showTextBackface) must match legacy tail
		self::assertSame(substr($legacyBytes, $prefixLen), substr($newBytes, $prefixLen + 4));
	}
}
