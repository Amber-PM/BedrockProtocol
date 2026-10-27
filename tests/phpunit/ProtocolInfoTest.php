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
use function array_unique;
use function array_values;
use function count;
use function explode;
use function sprintf;
use function str_starts_with;

final class ProtocolInfoTest extends TestCase{

	public function testMinecraftVersionNetwork() : void{
		self::assertMatchesRegularExpression(
			'/^(?:\d+\.)?(?:\d+\.)?(?:\d+\.)?\d+$/',
			ProtocolInfo::MINECRAFT_VERSION_NETWORK,
			"Network version should only contain 0-9 and \".\", and no more than 4 groups of digits"
		);
	}

	public function testEveryDeclaredProtocolStaysAccepted() : void{
		$constants = (new \ReflectionClass(ProtocolInfo::class))->getConstants();
		$declared = [];
		foreach($constants as $name => $value){
			if(str_starts_with($name, 'PROTOCOL_')){
				$declared[$name] = $value;
			}
		}
		$accepted = $constants['ACCEPTED_PROTOCOL'];
		self::assertIsArray($accepted);
		self::assertCount(33, $accepted);
		self::assertSame(array_values(array_unique($accepted)), $accepted);
		self::assertCount(count($declared), array_unique($declared), 'protocol IDs must stay distinct for admission');
		foreach($declared as $name => $value){
			self::assertContains($value, $accepted, "$name is no longer accepted");
		}
		self::assertContains($constants['CURRENT_PROTOCOL'], $accepted);
		self::assertSame(2225, $constants['CURRENT_PROTOCOL']);
		//released 1.26.50 and its 1.26.51 hotfix stay 2193 and the 2192 preview stays separate
		self::assertSame(2193, $constants['PROTOCOL_1_26_51']);
		self::assertNotSame($constants['PROTOCOL_1_26_50'], $constants['PROTOCOL_1_26_51']);
	}

	public function testAdvertisedVersionsMatchEachOther() : void{
		//same derivation as tools/generate-protocol-info.php for 1.26+
		$parts = explode('.', ProtocolInfo::MINECRAFT_VERSION_NETWORK);
		self::assertCount(3, $parts);
		self::assertSame('1', $parts[0]);
		self::assertSame(sprintf('v%d.%d', (int) $parts[1], (int) $parts[2]), ProtocolInfo::MINECRAFT_VERSION);
	}

	private static function encodePlaySound(PlaySoundPacket $packet, int $protocolId) : string{
		$writer = new ByteBufferWriter();
		$packet->encode($writer, $protocolId);
		return $writer->getData();
	}

	public function testDefaultProtocolSerializesWithThe2650Format() : void{
		$packet = PlaySoundPacket::create('random.click', 1.0, 2.0, 3.0, 1.0, 1.0, 2, 7);
		$packet->bypassListenerRangeCheck = true;
		$packet->playbackPositionSeconds = 1.5;

		$current = self::encodePlaySound($packet, ProtocolInfo::CURRENT_PROTOCOL);
		self::assertSame(self::encodePlaySound($packet, ProtocolInfo::PROTOCOL_1_26_50), $current);
		self::assertNotSame(self::encodePlaySound($packet, ProtocolInfo::PROTOCOL_1_26_44), $current);

		$decoded = new PlaySoundPacket();
		$decoded->decode(new ByteBufferReader($current), ProtocolInfo::CURRENT_PROTOCOL);
		self::assertTrue($decoded->bypassListenerRangeCheck);
		self::assertSame(1.5, $decoded->playbackPositionSeconds);
		self::assertSame(7, $decoded->serverSoundHandle);
		self::assertSame(2, $decoded->loopCount);

		$legacy = new PlaySoundPacket();
		$legacy->decode(new ByteBufferReader(self::encodePlaySound($packet, ProtocolInfo::PROTOCOL_1_26_44)), ProtocolInfo::PROTOCOL_1_26_44);
		self::assertFalse($legacy->bypassListenerRangeCheck);
		self::assertNull($legacy->playbackPositionSeconds);
		self::assertSame(7, $legacy->serverSoundHandle);
	}
}
