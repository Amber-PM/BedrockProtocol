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

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\ChunkPosition;
use pocketmine\network\mcpe\protocol\types\DimensionData;
use pocketmine\network\mcpe\protocol\types\EmoteType;
use pocketmine\network\mcpe\protocol\types\inventory\HandSlot;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftReservedStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\DeprecatedCraftingResultsStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequest;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequestActionType;
use pocketmine\network\mcpe\protocol\types\PassengerOfBlockData;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

class Bedrock2660SerializationTest extends TestCase{
	private const OLD = ProtocolInfo::PROTOCOL_1_26_51;
	private const NEW = ProtocolInfo::PROTOCOL_1_26_60;

	private static function encode(DataPacket $packet, int $protocolId) : string{
		$writer = new ByteBufferWriter();
		$packet->encode($writer, $protocolId);
		return $writer->getData();
	}

	/**
	 * @phpstan-template T of DataPacket
	 * @phpstan-param T $empty
	 * @phpstan-return T
	 */
	private static function decode(DataPacket $empty, string $bytes, int $protocolId) : DataPacket{
		$reader = new ByteBufferReader($bytes);
		$empty->decode($reader, $protocolId);
		self::assertSame(strlen($bytes), $reader->getOffset(), 'trailing bytes after decode');
		return $empty;
	}

	public function testAnimateHandIsOnlyOnTheWireFor2660() : void{
		$packet = AnimatePacket::create(5, AnimatePacket::ACTION_SWING_ARM, 0.0, null, HandSlot::OFFHAND);
		$old = self::encode($packet, self::OLD);
		$new = self::encode($packet, self::NEW);
		self::assertSame(strlen($old) + 1, strlen($new));
		self::assertSame(HandSlot::OFFHAND, self::decode(new AnimatePacket(), $new, self::NEW)->hand);
		self::assertSame(HandSlot::MAINHAND, self::decode(new AnimatePacket(), $old, self::OLD)->hand);
	}

	public function testLevelChunkClientBiomeUpdate() : void{
		$packet = LevelChunkPacket::create(new ChunkPosition(1, 2), 0, 4, null, false, [], "payload", true);
		$old = self::encode($packet, self::OLD);
		$new = self::encode($packet, self::NEW);
		self::assertSame(strlen($old) + 1, strlen($new));
		self::assertTrue(self::decode(new LevelChunkPacket(), $new, self::NEW)->isClientBiomeUpdate());
		self::assertFalse(self::decode(new LevelChunkPacket(), $old, self::OLD)->isClientBiomeUpdate());
	}

	public function testPartyChangedUsesOptionalPartyInfoFor2660() : void{
		$packet = PartyChangedPacket::create("party", true);
		foreach([self::OLD, self::NEW] as $protocolId){
			$decoded = self::decode(new PartyChangedPacket(), self::encode($packet, $protocolId), $protocolId);
			self::assertSame("party", $decoded->getPartyId());
			self::assertTrue($decoded->isPartyLeader());
		}
		$left = self::decode(new PartyChangedPacket(), self::encode(PartyChangedPacket::create("", false), self::NEW), self::NEW);
		self::assertNull($left->getPartyInfo());
	}

	public function testDimensionCloudsAreOnlyOnTheWireFor2660() : void{
		$data = new DimensionData(320, -64, 1, 0, Uuid::uuid4(), 128, false);
		foreach([self::OLD, self::NEW] as $protocolId){
			$writer = new ByteBufferWriter();
			$data->write($writer, $protocolId);
			$decoded = DimensionData::read(new ByteBufferReader($writer->getData()), $protocolId);
			self::assertSame($protocolId === self::NEW ? 128 : DimensionData::DEFAULT_CLOUD_HEIGHT, $decoded->getCloudHeight());
			self::assertSame($protocolId !== self::NEW, $decoded->isRenderClouds());
		}
	}

	public function testPassengerOfBlockRoundTrip() : void{
		$data = new PassengerOfBlockData(new BlockPosition(1, 2, 3), new Vector3(0.5, 1.0, -0.5), 90.0, 45.0, EmoteType::LAYING);
		$packet = SetPassengerOfBlockPacket::create(7, $data);
		$decoded = self::decode(new SetPassengerOfBlockPacket(), self::encode($packet, self::NEW), self::NEW);
		self::assertEquals($packet, $decoded);
	}

	public function testItemStackRequestActionIdsShiftIn2660() : void{
		self::assertSame(17, ItemStackRequestActionType::toOuterType(ItemStackRequestActionType::CRAFTING_RESULTS_DEPRECATED_ASK_TY_LAING, self::OLD));
		self::assertSame(18, ItemStackRequestActionType::toOuterType(ItemStackRequestActionType::CRAFTING_RESULTS_DEPRECATED_ASK_TY_LAING, self::NEW));
		self::assertSame(19, ItemStackRequestActionType::toInnerType(ItemStackRequestActionType::CRAFTING_RESULTS_DEPRECATED_ASK_TY_LAING, self::OLD));
		self::assertSame(20, ItemStackRequestActionType::toInnerType(ItemStackRequestActionType::CRAFTING_RESULTS_DEPRECATED_ASK_TY_LAING, self::NEW));
		self::assertSame(16, ItemStackRequestActionType::toOuterType(ItemStackRequestActionType::CRAFTING_RESERVED, self::NEW));
		self::assertNull(ItemStackRequestActionType::toOuterType(ItemStackRequestActionType::CRAFTING_RESERVED, self::OLD));
		self::assertSame(ItemStackRequestActionType::CRAFTING_LOOM, ItemStackRequestActionType::fromOuterType(15, self::NEW));

		$request = new ItemStackRequest(1, [new CraftReservedStackRequestAction("reserved", 2), new DeprecatedCraftingResultsStackRequestAction([], 1)], [], 0);
		$writer = new ByteBufferWriter();
		$request->write($writer, self::NEW);
		$decoded = ItemStackRequest::read(new ByteBufferReader($writer->getData()), self::NEW);
		self::assertEquals($request, $decoded);

		$this->expectException(\InvalidArgumentException::class);
		$request->write(new ByteBufferWriter(), self::OLD);
	}

	public function testNewPacketsRoundTrip() : void{
		foreach([
			new ServerboundMatchmakingCancelPacket(),
		] as $packet){
			$decoded = self::decode(new ($packet::class)(), self::encode($packet, self::NEW), self::NEW);
			self::assertEquals($packet, $decoded);
		}
	}
}
