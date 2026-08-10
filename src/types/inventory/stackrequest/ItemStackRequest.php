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

namespace pocketmine\network\mcpe\protocol\types\inventory\stackrequest;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use function count;

final class ItemStackRequest{
	/**
	 * @param ItemStackRequestAction[] $actions
	 * @param string[]                 $filterStrings
	 * @phpstan-param list<string> $filterStrings
	 */
	public function __construct(
		private int $requestId,
		private array $actions,
		private array $filterStrings,
		private int $filterStringCause
	){}

	public function getRequestId() : int{ return $this->requestId; }

	/** @return ItemStackRequestAction[] */
	public function getActions() : array{ return $this->actions; }

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getFilterStrings() : array{ return $this->filterStrings; }

	public function getFilterStringCause() : int{ return $this->filterStringCause; }

	/**
	 * @throws DataDecodeException
	 * @throws PacketDecodeException
	 */
	private static function readAction(ByteBufferReader $in, int $protocolId, int $typeId) : ItemStackRequestAction{
		return match($typeId){
			TakeStackRequestAction::ID => TakeStackRequestAction::read($in, $protocolId),
			PlaceStackRequestAction::ID => PlaceStackRequestAction::read($in, $protocolId),
			SwapStackRequestAction::ID => SwapStackRequestAction::read($in, $protocolId),
			DropStackRequestAction::ID => DropStackRequestAction::read($in, $protocolId),
			DestroyStackRequestAction::ID => DestroyStackRequestAction::read($in, $protocolId),
			CraftingConsumeInputStackRequestAction::ID => CraftingConsumeInputStackRequestAction::read($in, $protocolId),
			CraftingCreateSpecificResultStackRequestAction::ID => CraftingCreateSpecificResultStackRequestAction::read($in, $protocolId),
			PlaceIntoBundleStackRequestAction::ID => PlaceIntoBundleStackRequestAction::read($in, $protocolId),
			TakeFromBundleStackRequestAction::ID => TakeFromBundleStackRequestAction::read($in, $protocolId),
			LabTableCombineStackRequestAction::ID => LabTableCombineStackRequestAction::read($in, $protocolId),
			BeaconPaymentStackRequestAction::ID => BeaconPaymentStackRequestAction::read($in, $protocolId),
			MineBlockStackRequestAction::ID => MineBlockStackRequestAction::read($in, $protocolId),
			CraftRecipeStackRequestAction::ID => CraftRecipeStackRequestAction::read($in, $protocolId),
			CraftRecipeAutoStackRequestAction::ID => CraftRecipeAutoStackRequestAction::read($in, $protocolId),
			CreativeCreateStackRequestAction::ID => CreativeCreateStackRequestAction::read($in, $protocolId),
			CraftRecipeOptionalStackRequestAction::ID => CraftRecipeOptionalStackRequestAction::read($in, $protocolId),
			GrindstoneStackRequestAction::ID => GrindstoneStackRequestAction::read($in, $protocolId),
			LoomStackRequestAction::ID => LoomStackRequestAction::read($in, $protocolId),
			DeprecatedCraftingNonImplementedStackRequestAction::ID => DeprecatedCraftingNonImplementedStackRequestAction::read($in, $protocolId),
			DeprecatedCraftingResultsStackRequestAction::ID => DeprecatedCraftingResultsStackRequestAction::read($in, $protocolId),
			default => throw new PacketDecodeException("Unhandled item stack request action type $typeId"),
		};
	}

	/**
	 * As of 1.26.40 each action is prefixed by the index of its type in the cereal union it's sent under. The two
	 * container actions in the middle of the type list are never sent and don't have a slot in that union, so every
	 * type after them is shifted down by two.
	 */
	private static function actionTypeToVariant(int $typeId) : int{
		return $typeId > ItemStackRequestActionType::TAKE_FROM_BUNDLE ? $typeId - 2 : $typeId;
	}

	private static function variantToActionType(int $variant) : int{
		return $variant >= ItemStackRequestActionType::PLACE_INTO_BUNDLE ? $variant + 2 : $variant;
	}

	public static function read(ByteBufferReader $in, int $protocolId) : self{
		$requestId = CommonTypes::readItemStackRequestId($in);
		$actions = [];
		for($i = 0, $len = VarInt::readUnsignedInt($in); $i < $len; ++$i){
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				$variant = VarInt::readUnsignedInt($in);
				if($variant > self::actionTypeToVariant(ItemStackRequestActionType::CRAFTING_RESULTS_DEPRECATED_ASK_TY_LAING)){
					throw new PacketDecodeException("Unknown item stack request action variant $variant");
				}
				//the type is sent again as a legacy byte, which is the authoritative one
				$typeId = Byte::readUnsigned($in);
				$expectedTypeId = self::variantToActionType($variant);
				if($typeId !== $expectedTypeId){
					throw new PacketDecodeException("Item stack request action type $typeId does not match variant $variant");
				}
			}else{
				$typeId = Byte::readUnsigned($in);
			}
			$actions[] = self::readAction($in, $protocolId, $typeId);
		}
		$filterStrings = [];
		for($i = 0, $len = VarInt::readUnsignedInt($in); $i < $len; ++$i){
			$filterStrings[] = CommonTypes::getString($in);
		}
		$filterStringCause = LE::readSignedInt($in);
		return new self($requestId, $actions, $filterStrings, $filterStringCause);
	}

	public function write(ByteBufferWriter $out, int $protocolId) : void{
		CommonTypes::writeItemStackRequestId($out, $this->requestId);
		VarInt::writeUnsignedInt($out, count($this->actions));
		foreach($this->actions as $action){
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				VarInt::writeUnsignedInt($out, self::actionTypeToVariant($action->getTypeId()));
			}
			Byte::writeUnsigned($out, $action->getTypeId());
			$action->write($out, $protocolId);
		}
		VarInt::writeUnsignedInt($out, count($this->filterStrings));
		foreach($this->filterStrings as $string){
			CommonTypes::putString($out, $string);
		}
		LE::writeSignedInt($out, $this->filterStringCause);
	}
}
