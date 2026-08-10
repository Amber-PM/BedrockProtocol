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

namespace pocketmine\network\mcpe\protocol\serializer;

use pmmp\encoding\BE;
use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\BoolGameRule;
use pocketmine\network\mcpe\protocol\types\command\CommandOriginData;
use pocketmine\network\mcpe\protocol\types\entity\BlockPosMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\ByteMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\CompoundTagMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\EntityLink;
use pocketmine\network\mcpe\protocol\types\entity\FloatMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\IntMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\MetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\ShortMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\Vec3MetadataProperty;
use pocketmine\network\mcpe\protocol\types\FloatGameRule;
use pocketmine\network\mcpe\protocol\types\GameRule;
use pocketmine\network\mcpe\protocol\types\IntGameRule;
use pocketmine\network\mcpe\protocol\types\NullGameRule;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\recipe\ComplexAliasItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\IntIdMetaItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\ItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\ItemDescriptorType;
use pocketmine\network\mcpe\protocol\types\recipe\MolangItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeIngredient;
use pocketmine\network\mcpe\protocol\types\recipe\StringIdMetaItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\TagItemDescriptor;
use pocketmine\network\mcpe\protocol\types\skin\PersonaPieceTintColor;
use pocketmine\network\mcpe\protocol\types\skin\PersonaSkinPiece;
use pocketmine\network\mcpe\protocol\types\skin\PersonaSkinPieceType;
use pocketmine\network\mcpe\protocol\types\skin\SkinAnimation;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\protocol\types\skin\SkinImage;
use pocketmine\network\mcpe\protocol\types\StructureEditorData;
use pocketmine\network\mcpe\protocol\types\StructureSettings;
use pocketmine\utils\Binary;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use function count;
use function get_class;
use function hexdec;
use function ltrim;
use function preg_match;
use function sprintf;
use function strlen;
use function strrev;
use function strtolower;
use function substr;

final class CommonTypes{

	/**
	 * Variants of the cereal union item descriptors are sent under as of 1.26.40. Recipe ingredients only ever use
	 * INVALID and DEFAULT (the descriptor type is then carried by a name string), while item stack requests send the
	 * descriptor type as the variant itself.
	 */
	private const ITEM_DESCRIPTOR_VARIANT_INVALID = 0;
	private const ITEM_DESCRIPTOR_VARIANT_DEFAULT = 1;
	private const ITEM_DESCRIPTOR_VARIANT_MOLANG = 2;
	private const ITEM_DESCRIPTOR_VARIANT_TAG = 3;

	private const ITEM_DESCRIPTOR_NAME_DEFAULT = "name";
	private const ITEM_DESCRIPTOR_NAME_MOLANG = "molang";
	private const ITEM_DESCRIPTOR_NAME_TAG = "item_tag";

	/** Sent by vanilla in the trailing slot of invalid and tag descriptors. */
	private const ITEM_DESCRIPTOR_RESERVED = 32767;

	private function __construct(){
		//NOOP
	}

	/** @throws DataDecodeException */
	public static function getString(ByteBufferReader $in) : string{
		return $in->readByteArray(VarInt::readUnsignedInt($in));
	}

	public static function putString(ByteBufferWriter $out, string $v) : void{
		VarInt::writeUnsignedInt($out, strlen($v));
		$out->writeByteArray($v);
	}

	/** @throws DataDecodeException */
	public static function getBool(ByteBufferReader $in) : bool{
		return Byte::readUnsigned($in) !== 0;
	}

	public static function putBool(ByteBufferWriter $out, bool $v) : void{
		Byte::writeUnsigned($out, $v ? 1 : 0);
	}

	/** @throws DataDecodeException */
	public static function getUUID(ByteBufferReader $in) : UuidInterface{
		//This is two little-endian longs: bytes 7-0 followed by bytes 15-8
		$p1 = strrev($in->readByteArray(8));
		$p2 = strrev($in->readByteArray(8));
		return Uuid::fromBytes($p1 . $p2);
	}

	public static function putUUID(ByteBufferWriter $out, UuidInterface $uuid) : void{
		$bytes = $uuid->getBytes();
		$out->writeByteArray(strrev(substr($bytes, 0, 8)));
		$out->writeByteArray(strrev(substr($bytes, 8, 8)));
	}

	/**
	 * Reads an ARGB colour written as a big endian int32. Note that the byte order on the wire is B, G, R, A.
	 *
	 * @throws DataDecodeException
	 */
	public static function getBeArgb(ByteBufferReader $in) : int{
		$value = BE::readSignedInt($in);

		$a = $value & 0xff;
		$r = ($value >> 8) & 0xff;
		$g = ($value >> 16) & 0xff;
		$b = ($value >> 24) & 0xff;

		return ($a << 24) | ($r << 16) | ($g << 8) | $b;
	}

	/** Writes an ARGB colour (0xAARRGGBB) as a big endian int32. */
	public static function putBeArgb(ByteBufferWriter $out, int $argb) : void{
		$a = ($argb >> 24) & 0xff;
		$r = ($argb >> 16) & 0xff;
		$g = ($argb >> 8) & 0xff;
		$b = $argb & 0xff;

		BE::writeSignedInt($out, Binary::signInt($a | ($r << 8) | ($g << 16) | ($b << 24)));
	}

	/**
	 * Skin colours are exchanged as hex strings in the login chain, but as ARGB integers on the wire as of 1.26.40.
	 */
	private static function hexColorToArgb(string $color) : int{
		$hex = ltrim($color, "#");
		if($hex === "" || preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1){
			return 0;
		}
		$value = (int) hexdec($hex);

		return strlen($hex) > 6 ? $value : ($value | 0xff000000);
	}

	private static function argbToHexColor(int $argb) : string{
		return (($argb >> 24) & 0xff) === 0xff ?
			sprintf("#%06x", $argb & 0xffffff) :
			sprintf("#%08x", $argb);
	}

	/** @throws DataDecodeException */
	public static function getSkin(ByteBufferReader $in, int $protocolId) : SkinData{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			return self::getSkinModern($in);
		}

		$skinId = self::getString($in);
		$skinPlayFabId = self::getString($in);
		$skinResourcePatch = self::getString($in);
		$skinData = self::getSkinImage($in);
		$animationCount = LE::readUnsignedInt($in);
		$animations = [];
		for($i = 0; $i < $animationCount; ++$i){
			$skinImage = self::getSkinImage($in);
			$animationType = LE::readUnsignedInt($in);
			$animationFrames = LE::readFloat($in);
			$expressionType = LE::readUnsignedInt($in);
			$animations[] = new SkinAnimation($skinImage, $animationType, $animationFrames, $expressionType);
		}
		$capeData = self::getSkinImage($in);
		$geometryData = self::getString($in);
		$geometryDataVersion = self::getString($in);
		$animationData = self::getString($in);
		$capeId = self::getString($in);
		$fullSkinId = self::getString($in);
		$armSize = self::getString($in);
		$skinColor = self::getString($in);
		$personaPieceCount = LE::readUnsignedInt($in);
		$personaPieces = [];
		for($i = 0; $i < $personaPieceCount; ++$i){
			$pieceId = self::getString($in);
			$pieceType = self::getString($in);
			$packId = self::getString($in);
			$isDefaultPiece = self::getBool($in);
			$productId = self::getString($in);
			$personaPieces[] = new PersonaSkinPiece($pieceId, $pieceType, $packId, $isDefaultPiece, $productId);
		}
		$pieceTintColorCount = LE::readUnsignedInt($in);
		$pieceTintColors = [];
		for($i = 0; $i < $pieceTintColorCount; ++$i){
			$pieceType = self::getString($in);
			$colorCount = LE::readUnsignedInt($in);
			$colors = [];
			for($j = 0; $j < $colorCount; ++$j){
				$colors[] = self::getString($in);
			}
			$pieceTintColors[] = new PersonaPieceTintColor(
				$pieceType,
				$colors
			);
		}

		$premium = self::getBool($in);
		$persona = self::getBool($in);
		$capeOnClassic = self::getBool($in);
		$isPrimaryUser = self::getBool($in);
		$override = self::getBool($in);

		return new SkinData(
			$skinId,
			$skinPlayFabId,
			$skinResourcePatch,
			$skinData,
			$animations,
			$capeData,
			$geometryData,
			$geometryDataVersion,
			$animationData,
			$capeId,
			$fullSkinId,
			$armSize,
			$skinColor,
			$personaPieces,
			$pieceTintColors,
			true,
			$premium,
			$persona,
			$capeOnClassic,
			$isPrimaryUser,
			$override,
		);
	}

	/**
	 * Reads a skin in the 1.26.40+ format. Lists are varint-prefixed, the arm size and colours became numeric, and
	 * the trusted flag (previously written by the packets themselves) is now part of the skin.
	 *
	 * @throws DataDecodeException
	 */
	private static function getSkinModern(ByteBufferReader $in) : SkinData{
		$skinId = self::getString($in);
		$skinPlayFabId = self::getString($in);
		$skinResourcePatch = self::getString($in);
		$skinData = self::getSkinImage($in);
		$animationCount = VarInt::readUnsignedInt($in);
		$animations = [];
		for($i = 0; $i < $animationCount; ++$i){
			$skinImage = self::getSkinImage($in);
			$animationType = VarInt::readUnsignedInt($in);
			$animationFrames = LE::readFloat($in);
			$expressionType = VarInt::readUnsignedInt($in);
			$animations[] = new SkinAnimation($skinImage, $animationType, $animationFrames, $expressionType);
		}
		$capeData = self::getSkinImage($in);
		$geometryData = self::getString($in);
		$geometryDataVersion = self::getString($in);
		$animationData = self::getString($in);
		$capeId = self::getString($in);
		$fullSkinId = self::getString($in);
		$armSize = Byte::readUnsigned($in) === SkinData::ARM_SIZE_ID_SLIM ? SkinData::ARM_SIZE_SLIM : SkinData::ARM_SIZE_WIDE;
		$skinColor = self::argbToHexColor(self::getBeArgb($in));
		$personaPieceCount = VarInt::readUnsignedInt($in);
		$personaPieces = [];
		for($i = 0; $i < $personaPieceCount; ++$i){
			$pieceId = self::getString($in);
			$pieceType = PersonaSkinPieceType::nameFromId(LE::readUnsignedInt($in));
			$packId = self::getUUID($in)->toString();
			$isDefaultPiece = self::getBool($in);
			$productId = self::getString($in);
			$personaPieces[] = new PersonaSkinPiece($pieceId, $pieceType, $packId, $isDefaultPiece, $productId);
		}
		$pieceTintColorCount = VarInt::readUnsignedInt($in);
		$pieceTintColors = [];
		for($i = 0; $i < $pieceTintColorCount; ++$i){
			$pieceType = PersonaSkinPieceType::nameFromShortName(self::getString($in));
			$colors = [];
			for($j = 0; $j < SkinData::PIECE_TINT_COLOR_COUNT; ++$j){
				$colors[] = self::argbToHexColor(self::getBeArgb($in));
			}
			$pieceTintColors[] = new PersonaPieceTintColor($pieceType, $colors);
		}

		$premium = self::getBool($in);
		$persona = self::getBool($in);
		$capeOnClassic = self::getBool($in);
		$isPrimaryUser = self::getBool($in);
		$override = self::getBool($in);
		$trusted = strtolower(self::getString($in)) === "true";
		$profileHash = self::getString($in);

		return new SkinData(
			$skinId,
			$skinPlayFabId,
			$skinResourcePatch,
			$skinData,
			$animations,
			$capeData,
			$geometryData,
			$geometryDataVersion,
			$animationData,
			$capeId,
			$fullSkinId,
			$armSize,
			$skinColor,
			$personaPieces,
			$pieceTintColors,
			$trusted,
			$premium,
			$persona,
			$capeOnClassic,
			$isPrimaryUser,
			$override,
			$profileHash,
		);
	}

	private static function putSkinModern(ByteBufferWriter $out, SkinData $skin) : void{
		self::putString($out, $skin->getSkinId());
		self::putString($out, $skin->getPlayFabId());
		self::putString($out, $skin->getResourcePatch());
		self::putSkinImage($out, $skin->getSkinImage());
		VarInt::writeUnsignedInt($out, count($skin->getAnimations()));
		foreach($skin->getAnimations() as $animation){
			self::putSkinImage($out, $animation->getImage());
			VarInt::writeUnsignedInt($out, $animation->getType());
			LE::writeFloat($out, $animation->getFrames());
			VarInt::writeUnsignedInt($out, $animation->getExpressionType());
		}
		self::putSkinImage($out, $skin->getCapeImage());
		self::putString($out, $skin->getGeometryData());
		self::putString($out, $skin->getGeometryDataEngineVersion());
		self::putString($out, $skin->getAnimationData());
		self::putString($out, $skin->getCapeId());
		self::putString($out, $skin->getFullSkinId());
		Byte::writeUnsigned($out, $skin->getArmSize() === SkinData::ARM_SIZE_SLIM ? SkinData::ARM_SIZE_ID_SLIM : SkinData::ARM_SIZE_ID_WIDE);
		self::putBeArgb($out, self::hexColorToArgb($skin->getSkinColor()));
		VarInt::writeUnsignedInt($out, count($skin->getPersonaPieces()));
		foreach($skin->getPersonaPieces() as $piece){
			self::putString($out, $piece->getPieceId());
			LE::writeUnsignedInt($out, PersonaSkinPieceType::idFromName($piece->getPieceType()));
			self::putUUID($out, Uuid::isValid($piece->getPackId()) ? Uuid::fromString($piece->getPackId()) : Uuid::fromString(Uuid::NIL));
			self::putBool($out, $piece->isDefaultPiece());
			self::putString($out, $piece->getProductId());
		}
		VarInt::writeUnsignedInt($out, count($skin->getPieceTintColors()));
		foreach($skin->getPieceTintColors() as $tint){
			self::putString($out, PersonaSkinPieceType::shortNameFromName($tint->getPieceType()));
			$colors = $tint->getColors();
			for($i = 0; $i < SkinData::PIECE_TINT_COLOR_COUNT; ++$i){
				self::putBeArgb($out, isset($colors[$i]) ? self::hexColorToArgb($colors[$i]) : 0);
			}
		}
		self::putBool($out, $skin->isPremium());
		self::putBool($out, $skin->isPersona());
		self::putBool($out, $skin->isPersonaCapeOnClassic());
		self::putBool($out, $skin->isPrimaryUser());
		self::putBool($out, $skin->isOverride());
		self::putString($out, $skin->isVerified() ? "true" : "false");
		self::putString($out, $skin->getProfileHash());
	}

	public static function putSkin(ByteBufferWriter $out, int $protocolId, SkinData $skin) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			self::putSkinModern($out, $skin);
			return;
		}

		self::putString($out, $skin->getSkinId());
		self::putString($out, $skin->getPlayFabId());
		self::putString($out, $skin->getResourcePatch());
		self::putSkinImage($out, $skin->getSkinImage());
		LE::writeUnsignedInt($out, count($skin->getAnimations()));
		foreach($skin->getAnimations() as $animation){
			self::putSkinImage($out, $animation->getImage());
			LE::writeUnsignedInt($out, $animation->getType());
			LE::writeFloat($out, $animation->getFrames());
			LE::writeUnsignedInt($out, $animation->getExpressionType());
		}
		self::putSkinImage($out, $skin->getCapeImage());
		self::putString($out, $skin->getGeometryData());
		self::putString($out, $skin->getGeometryDataEngineVersion());
		self::putString($out, $skin->getAnimationData());
		self::putString($out, $skin->getCapeId());
		self::putString($out, $skin->getFullSkinId());
		self::putString($out, $skin->getArmSize());
		self::putString($out, $skin->getSkinColor());
		LE::writeUnsignedInt($out, count($skin->getPersonaPieces()));
		foreach($skin->getPersonaPieces() as $piece){
			self::putString($out, $piece->getPieceId());
			self::putString($out, $piece->getPieceType());
			self::putString($out, $piece->getPackId());
			self::putBool($out, $piece->isDefaultPiece());
			self::putString($out, $piece->getProductId());
		}
		LE::writeUnsignedInt($out, count($skin->getPieceTintColors()));
		foreach($skin->getPieceTintColors() as $tint){
			self::putString($out, $tint->getPieceType());
			LE::writeUnsignedInt($out, count($tint->getColors()));
			foreach($tint->getColors() as $color){
				self::putString($out, $color);
			}
		}
		self::putBool($out, $skin->isPremium());
		self::putBool($out, $skin->isPersona());
		self::putBool($out, $skin->isPersonaCapeOnClassic());
		self::putBool($out, $skin->isPrimaryUser());
		self::putBool($out, $skin->isOverride());
	}

	/** @throws DataDecodeException */
	private static function getSkinImage(ByteBufferReader $in) : SkinImage{
		$width = LE::readUnsignedInt($in);
		$height = LE::readUnsignedInt($in);
		$data = self::getString($in);
		try{
			return new SkinImage($height, $width, $data);
		}catch(\InvalidArgumentException $e){
			throw new PacketDecodeException($e->getMessage(), 0, $e);
		}
	}

	private static function putSkinImage(ByteBufferWriter $out, SkinImage $image) : void{
		LE::writeUnsignedInt($out, $image->getWidth());
		LE::writeUnsignedInt($out, $image->getHeight());
		self::putString($out, $image->getData());
	}

	/**
	 * @return int[]
	 * @phpstan-return array{0: int, 1: int, 2: int}
	 * @throws DataDecodeException
	 */
	private static function getItemStackHeader(ByteBufferReader $in) : array{
		$id = VarInt::readSignedInt($in);
		if($id === 0){
			return [0, 0, 0];
		}

		$count = LE::readUnsignedShort($in);
		$meta = VarInt::readUnsignedInt($in);

		return [$id, $count, $meta];
	}

	private static function putItemStackHeader(ByteBufferWriter $out, ItemStack $itemStack) : bool{
		if($itemStack->getId() === 0){
			VarInt::writeSignedInt($out, 0);
			return false;
		}

		VarInt::writeSignedInt($out, $itemStack->getId());
		LE::writeUnsignedShort($out, $itemStack->getCount());
		VarInt::writeUnsignedInt($out, $itemStack->getMeta());

		return true;
	}

	/** @throws DataDecodeException */
	private static function getItemStackFooter(ByteBufferReader $in, int $id, int $meta, int $count) : ItemStack{
		$blockRuntimeId = VarInt::readSignedInt($in);
		$rawExtraData = self::getString($in);

		return new ItemStack($id, $meta, $count, $blockRuntimeId, $rawExtraData);
	}

	private static function putItemStackFooter(ByteBufferWriter $out, ItemStack $itemStack) : void{
		VarInt::writeSignedInt($out, $itemStack->getBlockRuntimeId());
		self::putString($out, $itemStack->getRawExtraData());
	}

	/**
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	public static function getItemStackWithoutStackId(ByteBufferReader $in, int $protocolId) : ItemStack{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			//as of 1.26.40 the air shortcut is gone: every field is always present
			$id = VarInt::readSignedInt($in);
			$count = LE::readUnsignedShort($in);
			$meta = VarInt::readUnsignedInt($in);
			$stack = self::getItemStackFooter($in, $id, $meta, $count);

			return $id !== 0 ? $stack : ItemStack::null();
		}

		[$id, $count, $meta] = self::getItemStackHeader($in);

		return $id !== 0 ? self::getItemStackFooter($in, $id, $meta, $count) : ItemStack::null();

	}

	public static function putItemStackWithoutStackId(ByteBufferWriter $out, int $protocolId, ItemStack $itemStack) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			VarInt::writeSignedInt($out, $itemStack->getId());
			LE::writeUnsignedShort($out, $itemStack->getCount());
			VarInt::writeUnsignedInt($out, $itemStack->getMeta());
			self::putItemStackFooter($out, $itemStack);
			return;
		}

		if(self::putItemStackHeader($out, $itemStack)){
			self::putItemStackFooter($out, $itemStack);
		}
	}

	/** @throws DataDecodeException */
	public static function getItemStackWrapper(ByteBufferReader $in, int $protocolId) : ItemStackWrapper{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			//the legacy format was dropped in 1.26.40; everything uses the network descriptor now
			return self::getNetworkItemStackDescriptor($in);
		}

		[$id, $count, $meta] = self::getItemStackHeader($in);
		if($id === 0){
			return new ItemStackWrapper(0, ItemStack::null());
		}

		$hasNetId = self::getBool($in);
		$stackId = $hasNetId ? self::readServerItemStackId($in) : 0;

		$itemStack = self::getItemStackFooter($in, $id, $meta, $count);

		return new ItemStackWrapper($stackId, $itemStack);
	}

	public static function putItemStackWrapper(ByteBufferWriter $out, int $protocolId, ItemStackWrapper $itemStackWrapper) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			self::putNetworkItemStackDescriptor($out, $itemStackWrapper);
			return;
		}

		$itemStack = $itemStackWrapper->getItemStack();
		if(self::putItemStackHeader($out, $itemStack)){
			$hasNetId = $itemStackWrapper->getStackId() !== 0;
			self::putBool($out, $hasNetId);
			if($hasNetId){
				self::writeServerItemStackId($out, $itemStackWrapper->getStackId());
			}

			self::putItemStackFooter($out, $itemStack);
		}
	}

	public static function getNetworkItemStackDescriptor(ByteBufferReader $in) : ItemStackWrapper{
		$id = LE::readSignedShort($in);
		$count = LE::readUnsignedShort($in);
		$meta = VarInt::readUnsignedInt($in);

		$hasNetId = self::getBool($in);
		$stackId = $hasNetId ? VarInt::readSignedInt($in) : 0;

		$blockRuntimeId = VarInt::readUnsignedInt($in);
		$rawExtraData = self::getString($in);

		return new ItemStackWrapper($stackId, new ItemStack($id, $meta, $count, $blockRuntimeId, $rawExtraData));
	}

	public static function putNetworkItemStackDescriptor(ByteBufferWriter $out, ItemStackWrapper $itemStackWrapper) : void{
		LE::writeSignedShort($out, $itemStackWrapper->getItemStack()->getId());
		LE::writeUnsignedShort($out, $itemStackWrapper->getItemStack()->getCount());
		VarInt::writeUnsignedInt($out, $itemStackWrapper->getItemStack()->getMeta());

		self::putBool($out, $hasNetId = $itemStackWrapper->getStackId() !== 0);
		if($hasNetId){
			VarInt::writeSignedInt($out, $itemStackWrapper->getStackId());
		}

		VarInt::writeUnsignedInt($out, $itemStackWrapper->getItemStack()->getBlockRuntimeId());
		self::putString($out, $itemStackWrapper->getItemStack()->getRawExtraData());
	}

	/**
	 * As of 1.26.40 item descriptors are serialized as a cereal union. The union only has two variants (invalid and
	 * "default"), and the actual descriptor type is carried by a name string inside the default variant.
	 *
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	private static function getItemDescriptorBody(ByteBufferReader $in, string $name) : ItemDescriptor{
		switch($name){
			case self::ITEM_DESCRIPTOR_NAME_DEFAULT:
				$id = self::getString($in);
				$meta = VarInt::readSignedInt($in);
				if($meta < 0){
					throw new PacketDecodeException("Item descriptor meta cannot be negative, got $meta");
				}
				return new StringIdMetaItemDescriptor($id, $meta);
			case self::ITEM_DESCRIPTOR_NAME_MOLANG:
				$expression = self::getString($in);
				$version = LE::readSignedShort($in);
				return new MolangItemDescriptor($expression, $version);
			case self::ITEM_DESCRIPTOR_NAME_TAG:
				return new TagItemDescriptor(self::getString($in));
			default:
				throw new PacketDecodeException("Unknown item descriptor type \"$name\"");
		}
	}

	/**
	 * Writes the name string and body of an item descriptor in the 1.26.40+ format. Returns whether a trailing
	 * reserved value has to be written after it.
	 */
	private static function putItemDescriptorBody(ByteBufferWriter $out, ItemDescriptor $descriptor) : bool{
		if($descriptor instanceof StringIdMetaItemDescriptor){
			self::putString($out, self::ITEM_DESCRIPTOR_NAME_DEFAULT);
			self::putString($out, $descriptor->getId());
			VarInt::writeSignedInt($out, $descriptor->getMeta());
			return false;
		}
		if($descriptor instanceof MolangItemDescriptor){
			self::putString($out, self::ITEM_DESCRIPTOR_NAME_MOLANG);
			self::putString($out, $descriptor->getMolangExpression());
			LE::writeSignedShort($out, $descriptor->getMolangVersion());
			return false;
		}
		if($descriptor instanceof TagItemDescriptor){
			self::putString($out, self::ITEM_DESCRIPTOR_NAME_TAG);
			self::putString($out, $descriptor->getTag());
			return true;
		}

		throw new \InvalidArgumentException(get_class($descriptor) . " cannot be encoded for protocol " . ProtocolInfo::PROTOCOL_1_26_40 . " and newer; item descriptors must identify items by name");
	}

	/**
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	public static function getRecipeIngredient(ByteBufferReader $in, int $protocolId) : RecipeIngredient{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			$variant = VarInt::readUnsignedInt($in);
			if($variant === self::ITEM_DESCRIPTOR_VARIANT_INVALID){
				$descriptor = null;
				VarInt::readSignedInt($in); //reserved
			}elseif($variant === self::ITEM_DESCRIPTOR_VARIANT_DEFAULT){
				$name = self::getString($in);
				$descriptor = self::getItemDescriptorBody($in, $name);
				if($descriptor instanceof TagItemDescriptor){
					VarInt::readSignedInt($in); //reserved
				}
			}else{
				throw new PacketDecodeException("Unknown item descriptor variant $variant");
			}
			$count = VarInt::readSignedInt($in);

			return new RecipeIngredient($descriptor, $count);
		}

		$descriptorType = Byte::readUnsigned($in);
		$descriptor = match($descriptorType){
			ItemDescriptorType::INT_ID_META => IntIdMetaItemDescriptor::read($in),
			ItemDescriptorType::STRING_ID_META => StringIdMetaItemDescriptor::read($in),
			ItemDescriptorType::TAG => TagItemDescriptor::read($in),
			ItemDescriptorType::MOLANG => MolangItemDescriptor::read($in),
			ItemDescriptorType::COMPLEX_ALIAS => ComplexAliasItemDescriptor::read($in),
			default => null
		};
		$count = VarInt::readSignedInt($in);

		return new RecipeIngredient($descriptor, $count);
	}

	public static function putRecipeIngredient(ByteBufferWriter $out, int $protocolId, RecipeIngredient $ingredient) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			$descriptor = $ingredient->getDescriptor();
			if($descriptor === null){
				VarInt::writeUnsignedInt($out, self::ITEM_DESCRIPTOR_VARIANT_INVALID);
				VarInt::writeSignedInt($out, self::ITEM_DESCRIPTOR_RESERVED);
			}else{
				VarInt::writeUnsignedInt($out, self::ITEM_DESCRIPTOR_VARIANT_DEFAULT);
				if(self::putItemDescriptorBody($out, $descriptor)){
					VarInt::writeSignedInt($out, self::ITEM_DESCRIPTOR_RESERVED);
				}
			}
			VarInt::writeSignedInt($out, $ingredient->getCount());
			return;
		}

		$type = $ingredient->getDescriptor();

		Byte::writeUnsigned($out, $type?->getTypeId() ?? 0);
		$type?->write($out);

		VarInt::writeSignedInt($out, $ingredient->getCount());
	}

	/**
	 * Item descriptors inside item stack requests (auto-craft ingredients) are framed differently from the ones in
	 * recipes: the descriptor type is sent as the union variant itself, followed by a legacy type byte.
	 *
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	public static function getStackRequestRecipeIngredient(ByteBufferReader $in) : RecipeIngredient{
		$variant = VarInt::readUnsignedInt($in);
		$legacyType = Byte::readUnsigned($in);
		if($variant !== $legacyType){
			throw new PacketDecodeException("Item descriptor legacy type $legacyType does not match variant $variant");
		}
		$descriptor = match($variant){
			self::ITEM_DESCRIPTOR_VARIANT_INVALID => null,
			self::ITEM_DESCRIPTOR_VARIANT_DEFAULT => self::getItemDescriptorBody($in, self::ITEM_DESCRIPTOR_NAME_DEFAULT),
			self::ITEM_DESCRIPTOR_VARIANT_MOLANG => self::getItemDescriptorBody($in, self::ITEM_DESCRIPTOR_NAME_MOLANG),
			self::ITEM_DESCRIPTOR_VARIANT_TAG => self::getItemDescriptorBody($in, self::ITEM_DESCRIPTOR_NAME_TAG),
			default => throw new PacketDecodeException("Unknown item descriptor variant $variant")
		};
		$count = LE::readUnsignedShort($in);

		return new RecipeIngredient($descriptor, $count);
	}

	public static function putStackRequestRecipeIngredient(ByteBufferWriter $out, RecipeIngredient $ingredient) : void{
		$descriptor = $ingredient->getDescriptor();
		$variant = match(true){
			$descriptor === null => self::ITEM_DESCRIPTOR_VARIANT_INVALID,
			$descriptor instanceof StringIdMetaItemDescriptor => self::ITEM_DESCRIPTOR_VARIANT_DEFAULT,
			$descriptor instanceof MolangItemDescriptor => self::ITEM_DESCRIPTOR_VARIANT_MOLANG,
			$descriptor instanceof TagItemDescriptor => self::ITEM_DESCRIPTOR_VARIANT_TAG,
			default => throw new \InvalidArgumentException(get_class($descriptor) . " cannot be encoded in an item stack request")
		};
		VarInt::writeUnsignedInt($out, $variant);
		Byte::writeUnsigned($out, $variant);
		if($descriptor !== null){
			//the name string isn't sent here - the variant already identifies the descriptor
			if($descriptor instanceof StringIdMetaItemDescriptor){
				self::putString($out, $descriptor->getId());
				VarInt::writeSignedInt($out, $descriptor->getMeta());
			}elseif($descriptor instanceof MolangItemDescriptor){
				self::putString($out, $descriptor->getMolangExpression());
				LE::writeSignedShort($out, $descriptor->getMolangVersion());
			}else{
				self::putString($out, $descriptor->getTag());
			}
		}
		LE::writeUnsignedShort($out, $ingredient->getCount());
	}

	/**
	 * Decodes entity metadata from the stream.
	 *
	 * @return MetadataProperty[]
	 * @phpstan-return array<int, MetadataProperty>
	 *
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	public static function getEntityMetadata(ByteBufferReader $in, int $protocolId) : array{
		$count = VarInt::readUnsignedInt($in);
		$data = [];
		for($i = 0; $i < $count; ++$i){
			$key = VarInt::readUnsignedInt($in);
			$type = VarInt::readUnsignedInt($in);
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				//the type is sent twice: once as the cereal union variant, once as the legacy type byte
				$legacyType = Byte::readUnsigned($in);
				if($legacyType !== $type){
					throw new PacketDecodeException("Entity metadata legacy type $legacyType does not match variant $type");
				}
			}

			$data[$key] = self::readMetadataProperty($in, $type);
		}

		return $data;
	}

	/** @throws DataDecodeException */
	private static function readMetadataProperty(ByteBufferReader $in, int $type) : MetadataProperty{
		return match($type){
			ByteMetadataProperty::ID => ByteMetadataProperty::read($in),
			ShortMetadataProperty::ID => ShortMetadataProperty::read($in),
			IntMetadataProperty::ID => IntMetadataProperty::read($in),
			FloatMetadataProperty::ID => FloatMetadataProperty::read($in),
			StringMetadataProperty::ID => StringMetadataProperty::read($in),
			CompoundTagMetadataProperty::ID => CompoundTagMetadataProperty::read($in),
			BlockPosMetadataProperty::ID => BlockPosMetadataProperty::read($in),
			LongMetadataProperty::ID => LongMetadataProperty::read($in),
			Vec3MetadataProperty::ID => Vec3MetadataProperty::read($in),
			default => throw new PacketDecodeException("Unknown entity metadata type " . $type),
		};
	}

	/**
	 * Writes entity metadata to the packet buffer.
	 *
	 * @param MetadataProperty[] $metadata
	 *
	 * @phpstan-param array<int, MetadataProperty> $metadata
	 */
	public static function putEntityMetadata(ByteBufferWriter $out, int $protocolId, array $metadata) : void{
		VarInt::writeUnsignedInt($out, count($metadata));
		foreach($metadata as $key => $d){
			VarInt::writeUnsignedInt($out, $key);
			VarInt::writeUnsignedInt($out, $d->getTypeId());
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				Byte::writeUnsigned($out, $d->getTypeId());
			}
			$d->write($out);
		}
	}

	/** @throws DataDecodeException */
	public static function getActorUniqueId(ByteBufferReader $in) : int{
		return VarInt::readSignedLong($in);
	}

	public static function putActorUniqueId(ByteBufferWriter $out, int $eid) : void{
		VarInt::writeSignedLong($out, $eid);
	}

	/** @throws DataDecodeException */
	public static function getActorRuntimeId(ByteBufferReader $in) : int{
		return VarInt::readUnsignedLong($in);
	}

	public static function putActorRuntimeId(ByteBufferWriter $out, int $eid) : void{
		VarInt::writeUnsignedLong($out, $eid);
	}

	/**
	 * Reads a block position
	 *
	 * @throws DataDecodeException
	 */
	public static function getBlockPosition(ByteBufferReader $in, bool $signedY = true) : BlockPosition{
		$x = VarInt::readSignedInt($in);
		$y = $signedY ? VarInt::readSignedInt($in) : Binary::signInt(VarInt::readUnsignedInt($in));
		$z = VarInt::readSignedInt($in);
		return new BlockPosition($x, $y, $z);
	}

	/**
	 * Writes a block position
	 */
	public static function putBlockPosition(ByteBufferWriter $out, BlockPosition $blockPosition, bool $signedY = true) : void{
		VarInt::writeSignedInt($out, $blockPosition->getX());
		if($signedY){
			VarInt::writeSignedInt($out, $blockPosition->getY());
		}else{
			VarInt::writeUnsignedInt($out, Binary::unsignInt($blockPosition->getY()));
		}
		VarInt::writeSignedInt($out, $blockPosition->getZ());
	}

	/**
	 * Reads a floating-point Vector3 object with coordinates rounded to 4 decimal places.
	 *
	 * @throws DataDecodeException
	 */
	public static function getVector3(ByteBufferReader $in) : Vector3{
		$x = LE::readFloat($in);
		$y = LE::readFloat($in);
		$z = LE::readFloat($in);
		return new Vector3($x, $y, $z);
	}

	/**
	 * Reads a floating-point Vector2 object with coordinates rounded to 4 decimal places.
	 *
	 * @throws DataDecodeException
	 */
	public static function getVector2(ByteBufferReader $in) : Vector2{
		$x = LE::readFloat($in);
		$y = LE::readFloat($in);
		return new Vector2($x, $y);
	}

	/**
	 * Writes a floating-point Vector3 object, or 3x zero if null is given.
	 *
	 * Note: ONLY use this where it is reasonable to allow not specifying the vector.
	 * For all other purposes, use the non-nullable version.
	 *
	 * @see CommonTypes::putVector3()
	 */
	public static function putVector3Nullable(ByteBufferWriter $out, ?Vector3 $vector) : void{
		if($vector !== null){
			self::putVector3($out, $vector);
		}else{
			LE::writeFloat($out, 0.0);
			LE::writeFloat($out, 0.0);
			LE::writeFloat($out, 0.0);
		}
	}

	/**
	 * Writes a floating-point Vector3 object
	 */
	public static function putVector3(ByteBufferWriter $out, Vector3 $vector) : void{
		LE::writeFloat($out, $vector->x);
		LE::writeFloat($out, $vector->y);
		LE::writeFloat($out, $vector->z);
	}

	/**
	 * Writes a floating-point Vector2 object
	 */
	public static function putVector2(ByteBufferWriter $out, Vector2 $vector2) : void{
		LE::writeFloat($out, $vector2->x);
		LE::writeFloat($out, $vector2->y);
	}

	/** @throws DataDecodeException */
	public static function getRotationByte(ByteBufferReader $in) : float{
		return Byte::readUnsigned($in) * (360 / 256);
	}

	public static function putRotationByte(ByteBufferWriter $out, float $rotation) : void{
		Byte::writeUnsigned($out, (int) ($rotation / (360 / 256)));
	}

	/** @throws DataDecodeException */
	private static function readGameRule(ByteBufferReader $in, int $protocolId, int $type, bool $isPlayerModifiable, bool $isStartGame) : GameRule{
		return match($type){
			NullGameRule::ID => NullGameRule::decode($in, $protocolId, $isPlayerModifiable),
			BoolGameRule::ID => BoolGameRule::decode($in, $protocolId, $isPlayerModifiable),
			IntGameRule::ID => IntGameRule::decode($in, $protocolId, $isPlayerModifiable, $isStartGame),
			FloatGameRule::ID => FloatGameRule::decode($in, $protocolId, $isPlayerModifiable),
			default => throw new PacketDecodeException("Unknown gamerule type $type"),
		};
	}

	/**
	 * Reads gamerules
	 *
	 * @return GameRule[] game rule name => value
	 * @phpstan-return array<string, GameRule>
	 *
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	public static function getGameRules(ByteBufferReader $in, int $protocolId, bool $isStartGame) : array{
		$count = VarInt::readUnsignedInt($in);
		$rules = [];
		for($i = 0; $i < $count; ++$i){
			$name = self::getString($in);
			$isPlayerModifiable = self::getBool($in);
			$type = VarInt::readUnsignedInt($in);
			$rules[$name] = self::readGameRule($in, $protocolId, $type, $isPlayerModifiable, $isStartGame);
		}

		return $rules;
	}

	/**
	 * Writes a gamerule array
	 *
	 * @param GameRule[] $rules
	 * @phpstan-param array<string, GameRule> $rules
	 */
	public static function putGameRules(ByteBufferWriter $out, int $protocolId, array $rules, bool $isStartGame) : void{
		VarInt::writeUnsignedInt($out, count($rules));
		foreach($rules as $name => $rule){
			self::putString($out, $name);
			self::putBool($out, $rule->isPlayerModifiable());
			VarInt::writeUnsignedInt($out, $rule->getTypeId());
			$rule->encode($out, $protocolId, $isStartGame);
		}
	}

	/** @throws DataDecodeException */
	public static function getEntityLink(ByteBufferReader $in, int $protocolId) : EntityLink{
		$fromActorUniqueId = self::getActorUniqueId($in);
		$toActorUniqueId = self::getActorUniqueId($in);
		$type = Byte::readUnsigned($in);
		$immediate = self::getBool($in);
		$causedByRider = self::getBool($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_20){
			$vehicleAngularVelocity = LE::readFloat($in);
		}
		return new EntityLink($fromActorUniqueId, $toActorUniqueId, $type, $immediate, $causedByRider, $vehicleAngularVelocity ?? 0);
	}

	public static function putEntityLink(ByteBufferWriter $out, int $protocolId, EntityLink $link) : void{
		self::putActorUniqueId($out, $link->fromActorUniqueId);
		self::putActorUniqueId($out, $link->toActorUniqueId);
		Byte::writeUnsigned($out, $link->type);
		self::putBool($out, $link->immediate);
		self::putBool($out, $link->causedByRider);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_20){
			LE::writeFloat($out, $link->vehicleAngularVelocity);
		}
	}

	/** @throws DataDecodeException */
	public static function getCommandOriginData(ByteBufferReader $in, int $protocolId) : CommandOriginData{
		$result = new CommandOriginData();

		$result->type = $protocolId >= ProtocolInfo::PROTOCOL_1_21_130 ? CommonTypes::getString($in) : CommandOriginData::getTypeFromId(VarInt::readUnsignedInt($in));
		$result->uuid = self::getUUID($in);
		$result->requestId = self::getString($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_130){
			$result->playerActorUniqueId = LE::readSignedLong($in);
		}elseif($result->type === CommandOriginData::ORIGIN_DEV_CONSOLE or $result->type === CommandOriginData::ORIGIN_TEST){
			$result->playerActorUniqueId = VarInt::readSignedLong($in);
		}

		return $result;
	}

	public static function putCommandOriginData(ByteBufferWriter $out, CommandOriginData $data, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_130){
			self::putString($out, $data->type);
		}else{
			VarInt::writeUnsignedInt($out, CommandOriginData::getIdFromType($data->type));
		}
		self::putUUID($out, $data->uuid);
		self::putString($out, $data->requestId);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_130){
			LE::writeSignedLong($out, $data->playerActorUniqueId);
		}elseif($data->type === CommandOriginData::ORIGIN_DEV_CONSOLE or $data->type === CommandOriginData::ORIGIN_TEST){
			VarInt::writeSignedLong($out, $data->playerActorUniqueId);
		}
	}

	/** @throws DataDecodeException */
	public static function getStructureSettings(ByteBufferReader $in, int $protocolId) : StructureSettings{
		$result = new StructureSettings();

		$result->paletteName = self::getString($in);

		$result->ignoreEntities = self::getBool($in);
		$result->ignoreBlocks = self::getBool($in);
		$result->allowNonTickingChunks = self::getBool($in);

		$result->dimensions = self::getBlockPosition($in, $protocolId >= ProtocolInfo::PROTOCOL_1_26_10);
		$result->offset = self::getBlockPosition($in, $protocolId >= ProtocolInfo::PROTOCOL_1_26_10);

		$result->lastTouchedByPlayerID = self::getActorUniqueId($in);
		$result->rotation = Byte::readUnsigned($in);
		$result->mirror = Byte::readUnsigned($in);
		$result->animationMode = Byte::readUnsigned($in);
		$result->animationSeconds = LE::readFloat($in);
		$result->integrityValue = LE::readFloat($in);
		$result->integritySeed = LE::readUnsignedInt($in);
		$result->pivot = self::getVector3($in);

		return $result;
	}

	public static function putStructureSettings(ByteBufferWriter $out, StructureSettings $structureSettings, int $protocolId) : void{
		self::putString($out, $structureSettings->paletteName);

		self::putBool($out, $structureSettings->ignoreEntities);
		self::putBool($out, $structureSettings->ignoreBlocks);
		self::putBool($out, $structureSettings->allowNonTickingChunks);

		self::putBlockPosition($out, $structureSettings->dimensions, $protocolId >= ProtocolInfo::PROTOCOL_1_26_10);
		self::putBlockPosition($out, $structureSettings->offset, $protocolId >= ProtocolInfo::PROTOCOL_1_26_10);

		self::putActorUniqueId($out, $structureSettings->lastTouchedByPlayerID);
		Byte::writeUnsigned($out, $structureSettings->rotation);
		Byte::writeUnsigned($out, $structureSettings->mirror);
		Byte::writeUnsigned($out, $structureSettings->animationMode);
		LE::writeFloat($out, $structureSettings->animationSeconds);
		LE::writeFloat($out, $structureSettings->integrityValue);
		LE::writeUnsignedInt($out, $structureSettings->integritySeed);
		self::putVector3($out, $structureSettings->pivot);
	}

	/** @throws DataDecodeException */
	public static function getStructureEditorData(ByteBufferReader $in, int $protocolId) : StructureEditorData{
		$result = new StructureEditorData();

		$result->structureName = self::getString($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_60){
			$result->filteredStructureName = self::getString($in);
		}
		$result->structureDataField = self::getString($in);

		$result->includePlayers = self::getBool($in);
		$result->showBoundingBox = self::getBool($in);

		$result->structureBlockType = VarInt::readSignedInt($in);
		$result->structureSettings = self::getStructureSettings($in, $protocolId);
		//became a single byte in 1.26.40
		$result->structureRedstoneSaveMode = $protocolId >= ProtocolInfo::PROTOCOL_1_26_40 ? Byte::readUnsigned($in) : VarInt::readSignedInt($in);

		return $result;
	}

	public static function putStructureEditorData(ByteBufferWriter $out, int $protocolId, StructureEditorData $structureEditorData) : void{
		self::putString($out, $structureEditorData->structureName);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_60){
			self::putString($out, $structureEditorData->filteredStructureName);
		}
		self::putString($out, $structureEditorData->structureDataField);

		self::putBool($out, $structureEditorData->includePlayers);
		self::putBool($out, $structureEditorData->showBoundingBox);

		VarInt::writeSignedInt($out, $structureEditorData->structureBlockType);
		self::putStructureSettings($out, $structureEditorData->structureSettings, $protocolId);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			Byte::writeUnsigned($out, $structureEditorData->structureRedstoneSaveMode);
		}else{
			VarInt::writeSignedInt($out, $structureEditorData->structureRedstoneSaveMode);
		}
	}

	/** @throws PacketDecodeException */
	public static function getNbtRoot(ByteBufferReader $in) : TreeRoot{
		$offset = $in->getOffset();
		try{
			return (new NetworkNbtSerializer())->read($in->getData(), $offset, 512);
		}catch(NbtDataException $e){
			throw PacketDecodeException::wrap($e, "Failed decoding NBT root");
		}finally{
			$in->setOffset($offset);
		}
	}

	public static function getNbtCompoundRoot(ByteBufferReader $in) : CompoundTag{
		try{
			return self::getNbtRoot($in)->mustGetCompoundTag();
		}catch(NbtDataException $e){
			throw PacketDecodeException::wrap($e, "Expected TAG_Compound NBT root");
		}
	}

	/** @throws DataDecodeException */
	public static function readRecipeNetId(ByteBufferReader $in) : int{
		return VarInt::readUnsignedInt($in);
	}

	public static function writeRecipeNetId(ByteBufferWriter $out, int $id) : void{
		VarInt::writeUnsignedInt($out, $id);
	}

	/** @throws DataDecodeException */
	public static function readCreativeItemNetId(ByteBufferReader $in) : int{
		return VarInt::readUnsignedInt($in);
	}

	public static function writeCreativeItemNetId(ByteBufferWriter $out, int $id) : void{
		VarInt::writeUnsignedInt($out, $id);
	}

	/**
	 * This is a union of ItemStackRequestId, LegacyItemStackRequestId, and ServerItemStackId, used in serverbound
	 * packets to allow the client to refer to server known items, or items which may have been modified by a previous
	 * as-yet unacknowledged request from the client.
	 *
	 * - Server itemstack ID is positive
	 * - InventoryTransaction "legacy" request ID is negative and even
	 * - ItemStackRequest request ID is negative and odd
	 * - 0 refers to an empty itemstack (air)
	 *
	 * @throws DataDecodeException
	 */
	public static function readItemStackNetIdVariant(ByteBufferReader $in, int $protocolId) : int{
		//this became a plain little-endian int in 1.26.40
		return $protocolId >= ProtocolInfo::PROTOCOL_1_26_40 ? LE::readSignedInt($in) : VarInt::readSignedInt($in);
	}

	/**
	 * This is a union of ItemStackRequestId, LegacyItemStackRequestId, and ServerItemStackId, used in serverbound
	 * packets to allow the client to refer to server known items, or items which may have been modified by a previous
	 * as-yet unacknowledged request from the client.
	 */
	public static function writeItemStackNetIdVariant(ByteBufferWriter $out, int $protocolId, int $id) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			LE::writeSignedInt($out, $id);
		}else{
			VarInt::writeSignedInt($out, $id);
		}
	}

	/** @throws DataDecodeException */
	public static function readItemStackRequestId(ByteBufferReader $in) : int{
		return VarInt::readSignedInt($in);
	}

	public static function writeItemStackRequestId(ByteBufferWriter $out, int $id) : void{
		VarInt::writeSignedInt($out, $id);
	}

	/** @throws DataDecodeException */
	public static function readLegacyItemStackRequestId(ByteBufferReader $in) : int{
		return VarInt::readSignedInt($in);
	}

	public static function writeLegacyItemStackRequestId(ByteBufferWriter $out, int $id) : void{
		VarInt::writeSignedInt($out, $id);
	}

	/** @throws DataDecodeException */
	public static function readServerItemStackId(ByteBufferReader $in) : int{
		return VarInt::readSignedInt($in);
	}

	public static function writeServerItemStackId(ByteBufferWriter $out, int $id) : void{
		VarInt::writeSignedInt($out, $id);
	}

	/**
	 * @phpstan-template T
	 * @phpstan-param \Closure(ByteBufferReader) : (T|null) $reader
	 * @phpstan-return T|null
	 * @throws DataDecodeException
	 */
	public static function readOptional(ByteBufferReader $in, \Closure $reader) : mixed{
		if(self::getBool($in)){
			return $reader($in);
		}
		return null;
	}

	/**
	 * @phpstan-template T
	 * @phpstan-param T|null $value
	 * @phpstan-param \Closure(ByteBufferWriter, T) : void $writer
	 */
	public static function writeOptional(ByteBufferWriter $out, mixed $value, \Closure $writer) : void{
		if($value !== null){
			self::putBool($out, true);
			$writer($out, $value);
		}else{
			self::putBool($out, false);
		}
	}
}
