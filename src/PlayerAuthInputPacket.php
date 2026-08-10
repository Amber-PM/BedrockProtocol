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

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\serializer\BitSet;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\InputMode;
use pocketmine\network\mcpe\protocol\types\InteractionMode;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequest;
use pocketmine\network\mcpe\protocol\types\ItemInteractionData;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputVehicleInfo;
use pocketmine\network\mcpe\protocol\types\PlayerBlockAction;
use pocketmine\network\mcpe\protocol\types\PlayerBlockActionStopBreak;
use pocketmine\network\mcpe\protocol\types\PlayerBlockActionWithBlockInfo;
use pocketmine\network\mcpe\protocol\types\PlayMode;
use function assert;
use function count;

class PlayerAuthInputPacket extends DataPacket implements ServerboundPacket{
	public const NETWORK_ID = ProtocolInfo::PLAYER_AUTH_INPUT_PACKET;

	public Vector3 $position;
	private float $pitch;
	private float $yaw;
	private float $headYaw;
	private float $moveVecX;
	private float $moveVecZ;
	private BitSet $inputFlags;
	private int $inputMode;
	private int $playMode;
	private int $interactionMode;
	private ?Vector3 $vrGazeDirection = null;
	private Vector2 $interactRotation;
	private int $tick;
	private Vector3 $delta;
	private ?ItemInteractionData $itemInteractionData = null;
	private ?ItemStackRequest $itemStackRequest = null;
	/** @var PlayerBlockAction[]|null */
	private ?array $blockActions = null;
	private ?PlayerAuthInputVehicleInfo $vehicleInfo = null;
	private float $analogMoveVecX;
	private float $analogMoveVecZ;
	private Vector3 $cameraOrientation;
	private Vector2 $rawMove;

	/**
	 * @generate-create-func
	 * @param PlayerBlockAction[] $blockActions
	 */
	private static function internalCreate(
		Vector3 $position,
		float $pitch,
		float $yaw,
		float $headYaw,
		float $moveVecX,
		float $moveVecZ,
		BitSet $inputFlags,
		int $inputMode,
		int $playMode,
		int $interactionMode,
		?Vector3 $vrGazeDirection,
		Vector2 $interactRotation,
		int $tick,
		Vector3 $delta,
		?ItemInteractionData $itemInteractionData,
		?ItemStackRequest $itemStackRequest,
		?array $blockActions,
		?PlayerAuthInputVehicleInfo $vehicleInfo,
		float $analogMoveVecX,
		float $analogMoveVecZ,
		Vector3 $cameraOrientation,
		Vector2 $rawMove,
	) : self{
		$result = new self;
		$result->position = $position;
		$result->pitch = $pitch;
		$result->yaw = $yaw;
		$result->headYaw = $headYaw;
		$result->moveVecX = $moveVecX;
		$result->moveVecZ = $moveVecZ;
		$result->inputFlags = $inputFlags;
		$result->inputMode = $inputMode;
		$result->playMode = $playMode;
		$result->interactionMode = $interactionMode;
		$result->vrGazeDirection = $vrGazeDirection;
		$result->interactRotation = $interactRotation;
		$result->tick = $tick;
		$result->delta = $delta;
		$result->itemInteractionData = $itemInteractionData;
		$result->itemStackRequest = $itemStackRequest;
		$result->blockActions = $blockActions;
		$result->vehicleInfo = $vehicleInfo;
		$result->analogMoveVecX = $analogMoveVecX;
		$result->analogMoveVecZ = $analogMoveVecZ;
		$result->cameraOrientation = $cameraOrientation;
		$result->rawMove = $rawMove;
		return $result;
	}

	/**
	 * @param BitSet                   $inputFlags @see PlayerAuthInputFlags
	 * @param int                      $inputMode @see InputMode
	 * @param int                      $playMode @see PlayMode
	 * @param int                      $interactionMode @see InteractionMode
	 * @param PlayerBlockAction[]|null $blockActions Blocks that the client has interacted with
	 */
	public static function create(
		Vector3 $position,
		float $pitch,
		float $yaw,
		float $headYaw,
		float $moveVecX,
		float $moveVecZ,
		BitSet $inputFlags,
		int $inputMode,
		int $playMode,
		int $interactionMode,
		?Vector3 $vrGazeDirection,
		Vector2 $interactRotation,
		int $tick,
		Vector3 $delta,
		?ItemInteractionData $itemInteractionData,
		?ItemStackRequest $itemStackRequest,
		?array $blockActions,
		?PlayerAuthInputVehicleInfo $vehicleInfo,
		float $analogMoveVecX,
		float $analogMoveVecZ,
		Vector3 $cameraOrientation,
		Vector2 $rawMove
	) : self{
		if($inputFlags->getLength() !== PlayerAuthInputFlags::NUMBER_OF_FLAGS && $inputFlags->getLength() !== PlayerAuthInputFlags::NUMBER_OF_FLAGS_1_26_40){
			throw new \InvalidArgumentException("Input flags must be " . PlayerAuthInputFlags::NUMBER_OF_FLAGS . " or " . PlayerAuthInputFlags::NUMBER_OF_FLAGS_1_26_40 . " bits long");
		}

		if($playMode === PlayMode::VR and $vrGazeDirection === null){
			//yuck, can we get a properly written packet just once? ...
			throw new \InvalidArgumentException("Gaze direction must be provided for VR play mode");
		}

		$inputFlags->set(PlayerAuthInputFlags::PERFORM_ITEM_STACK_REQUEST, $itemStackRequest !== null);
		$inputFlags->set(PlayerAuthInputFlags::PERFORM_ITEM_INTERACTION, $itemInteractionData !== null);
		$inputFlags->set(PlayerAuthInputFlags::PERFORM_BLOCK_ACTIONS, $blockActions !== null);
		$inputFlags->set(PlayerAuthInputFlags::IN_CLIENT_PREDICTED_VEHICLE, $vehicleInfo !== null);

		return self::internalCreate(
			$position,
			$pitch,
			$yaw,
			$headYaw,
			$moveVecX,
			$moveVecZ,
			$inputFlags,
			$inputMode,
			$playMode,
			$interactionMode,
			$vrGazeDirection?->asVector3(),
			$interactRotation,
			$tick,
			$delta,
			$itemInteractionData,
			$itemStackRequest,
			$blockActions,
			$vehicleInfo,
			$analogMoveVecX,
			$analogMoveVecZ,
			$cameraOrientation,
			$rawMove
		);
	}

	public function getPosition() : Vector3{
		return $this->position;
	}

	public function getPitch() : float{
		return $this->pitch;
	}

	public function getYaw() : float{
		return $this->yaw;
	}

	public function getHeadYaw() : float{
		return $this->headYaw;
	}

	public function getMoveVecX() : float{
		return $this->moveVecX;
	}

	public function getMoveVecZ() : float{
		return $this->moveVecZ;
	}

	/**
	 * @see PlayerAuthInputFlags
	 */
	public function getInputFlags() : BitSet{
		return $this->inputFlags;
	}

	/**
	 * @see InputMode
	 */
	public function getInputMode() : int{
		return $this->inputMode;
	}

	/**
	 * @see PlayMode
	 */
	public function getPlayMode() : int{
		return $this->playMode;
	}

	/**
	 * @see InteractionMode
	 */
	public function getInteractionMode() : int{
		return $this->interactionMode;
	}

	public function getVrGazeDirection() : ?Vector3{
		return $this->vrGazeDirection;
	}

	public function getInteractRotation() : Vector2{ return $this->interactRotation; }

	public function getTick() : int{
		return $this->tick;
	}

	public function getDelta() : Vector3{
		return $this->delta;
	}

	public function getItemInteractionData() : ?ItemInteractionData{
		return $this->itemInteractionData;
	}

	public function getItemStackRequest() : ?ItemStackRequest{
		return $this->itemStackRequest;
	}

	/**
	 * @return PlayerBlockAction[]|null
	 */
	public function getBlockActions() : ?array{
		return $this->blockActions;
	}

	public function getVehicleInfo() : ?PlayerAuthInputVehicleInfo{ return $this->vehicleInfo; }

	public function getAnalogMoveVecX() : float{ return $this->analogMoveVecX; }

	public function getAnalogMoveVecZ() : float{ return $this->analogMoveVecZ; }

	public function getCameraOrientation() : Vector3{ return $this->cameraOrientation; }

	public function getRawMove() : Vector2{ return $this->rawMove; }

	/**
	 * As of 1.26.40 the input flags are no longer a bitset, but a list of the IDs of the flags that are set, preceded
	 * by a flag telling whether the list is there at all.
	 *
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	private static function readInputFlags(ByteBufferReader $in) : BitSet{
		$flags = new BitSet(PlayerAuthInputFlags::NUMBER_OF_FLAGS_1_26_40);
		if(!CommonTypes::getBool($in)){
			return $flags;
		}

		$count = VarInt::readUnsignedInt($in);
		if($count > PlayerAuthInputFlags::NUMBER_OF_FLAGS_1_26_40){
			throw new PacketDecodeException("Too many input flags ($count)");
		}
		for($i = 0; $i < $count; ++$i){
			$id = VarInt::readSignedInt($in);
			if($id < 0 || $id >= PlayerAuthInputFlags::NUMBER_OF_FLAGS_1_26_40){
				throw new PacketDecodeException("Unknown input flag $id");
			}
			$flags->set($id, true);
		}

		return $flags;
	}

	private static function writeInputFlags(ByteBufferWriter $out, BitSet $flags) : void{
		$ids = [];
		for($i = 0, $length = $flags->getLength(); $i < $length; ++$i){
			if($flags->get($i)){
				$ids[] = $i;
			}
		}

		CommonTypes::putBool($out, true);
		VarInt::writeUnsignedInt($out, count($ids));
		foreach($ids as $id){
			VarInt::writeSignedInt($out, $id);
		}
	}

	/**
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	private function readBlockAction(ByteBufferReader $in, int $protocolId) : PlayerBlockAction{
		$actionType = VarInt::readSignedInt($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			//as of 1.26.40 the block info is sent for every action, including the ones that don't use it
			$blockPosition = CommonTypes::getBlockPosition($in);
			$face = VarInt::readSignedInt($in);

			return match(true){
				PlayerBlockActionWithBlockInfo::isValidActionType($actionType) => new PlayerBlockActionWithBlockInfo($actionType, $blockPosition, $face),
				$actionType === PlayerAction::STOP_BREAK => new PlayerBlockActionStopBreak(),
				default => throw new PacketDecodeException("Unexpected block action type $actionType")
			};
		}

		return match(true){
			PlayerBlockActionWithBlockInfo::isValidActionType($actionType) => PlayerBlockActionWithBlockInfo::read($in, $actionType),
			$actionType === PlayerAction::STOP_BREAK => new PlayerBlockActionStopBreak(),
			default => throw new PacketDecodeException("Unexpected block action type $actionType")
		};
	}

	private function writeBlockAction(ByteBufferWriter $out, int $protocolId, PlayerBlockAction $blockAction) : void{
		VarInt::writeSignedInt($out, $blockAction->getActionType());
		if($blockAction instanceof PlayerBlockActionWithBlockInfo){
			$blockAction->write($out);
		}elseif($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			//actions without block info still have to send a placeholder
			CommonTypes::putBlockPosition($out, new BlockPosition(0, 0, 0));
			VarInt::writeSignedInt($out, 0);
		}else{
			$blockAction->write($out);
		}
	}

	protected function decodePayload(ByteBufferReader $in, int $protocolId) : void{
		$this->pitch = LE::readFloat($in);
		$this->yaw = LE::readFloat($in);
		$this->position = CommonTypes::getVector3($in);
		$this->moveVecX = LE::readFloat($in);
		$this->moveVecZ = LE::readFloat($in);
		$this->headYaw = LE::readFloat($in);
		$this->inputFlags = $protocolId >= ProtocolInfo::PROTOCOL_1_26_40 ?
			self::readInputFlags($in) :
			BitSet::read($in, $protocolId >= ProtocolInfo::PROTOCOL_1_21_50 ? PlayerAuthInputFlags::NUMBER_OF_FLAGS : 64);
		$this->inputMode = VarInt::readUnsignedInt($in);
		$this->playMode = VarInt::readUnsignedInt($in);
		$this->interactionMode = $protocolId >= ProtocolInfo::PROTOCOL_1_26_40 ? VarInt::readSignedInt($in) : VarInt::readUnsignedInt($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_40){
			$this->interactRotation = CommonTypes::getVector2($in);
		}elseif($this->playMode === PlayMode::VR){
			$this->vrGazeDirection = CommonTypes::getVector3($in);
		}
		$this->tick = VarInt::readUnsignedLong($in);
		$this->delta = CommonTypes::getVector3($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			//every one of these became an optional inside an always-present optional, instead of being gated by an
			//input flag
			if(CommonTypes::getBool($in) && CommonTypes::getBool($in)){
				$this->itemInteractionData = ItemInteractionData::read($in, $protocolId);
			}
			if(CommonTypes::getBool($in) && CommonTypes::getBool($in)){
				$this->itemStackRequest = ItemStackRequest::read($in, $protocolId);
			}
			if(CommonTypes::getBool($in) && CommonTypes::getBool($in)){
				$this->blockActions = [];
				$max = VarInt::readUnsignedInt($in);
				for($i = 0; $i < $max; ++$i){
					$this->blockActions[] = $this->readBlockAction($in, $protocolId);
				}
			}
			$vehicleRotationX = null;
			$vehicleRotationZ = null;
			if(CommonTypes::getBool($in) && CommonTypes::getBool($in)){
				$vehicleRotationX = LE::readFloat($in);
				$vehicleRotationZ = LE::readFloat($in);
			}
			$vehicleUniqueId = CommonTypes::getBool($in) && CommonTypes::getBool($in) ? CommonTypes::getActorUniqueId($in) : null;
			if($vehicleRotationX !== null || $vehicleUniqueId !== null){
				$this->vehicleInfo = new PlayerAuthInputVehicleInfo($vehicleRotationX, $vehicleRotationZ, $vehicleUniqueId ?? 0);
			}
		}else{
			if($this->inputFlags->get(PlayerAuthInputFlags::PERFORM_ITEM_INTERACTION)){
				$this->itemInteractionData = ItemInteractionData::read($in, $protocolId);
			}
			if($this->inputFlags->get(PlayerAuthInputFlags::PERFORM_ITEM_STACK_REQUEST)){
				$this->itemStackRequest = ItemStackRequest::read($in, $protocolId);
			}
			if($this->inputFlags->get(PlayerAuthInputFlags::PERFORM_BLOCK_ACTIONS)){
				$this->blockActions = [];
				$max = VarInt::readSignedInt($in);
				for($i = 0; $i < $max; ++$i){
					$this->blockActions[] = $this->readBlockAction($in, $protocolId);
				}
			}
			if($this->inputFlags->get(PlayerAuthInputFlags::IN_CLIENT_PREDICTED_VEHICLE) && $protocolId >= ProtocolInfo::PROTOCOL_1_20_60){
				$this->vehicleInfo = PlayerAuthInputVehicleInfo::read($in, $protocolId);
			}
		}
		$this->analogMoveVecX = LE::readFloat($in);
		$this->analogMoveVecZ = LE::readFloat($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_40){
			$this->cameraOrientation = CommonTypes::getVector3($in);
			if($protocolId >= ProtocolInfo::PROTOCOL_1_21_50){
				$this->rawMove = CommonTypes::getVector2($in);
			}
		}
	}

	protected function encodePayload(ByteBufferWriter $out, int $protocolId) : void{
		$inputFlags = $this->inputFlags;

		if($this->vehicleInfo !== null && $protocolId >= ProtocolInfo::PROTOCOL_1_20_60){
			$inputFlags->set(PlayerAuthInputFlags::IN_CLIENT_PREDICTED_VEHICLE, true);
		}

		LE::writeFloat($out, $this->pitch);
		LE::writeFloat($out, $this->yaw);
		CommonTypes::putVector3($out, $this->position);
		LE::writeFloat($out, $this->moveVecX);
		LE::writeFloat($out, $this->moveVecZ);
		LE::writeFloat($out, $this->headYaw);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			self::writeInputFlags($out, $this->inputFlags);
		}else{
			$this->inputFlags->write($out, $protocolId >= ProtocolInfo::PROTOCOL_1_21_50 ? PlayerAuthInputFlags::NUMBER_OF_FLAGS : 64);
		}
		VarInt::writeUnsignedInt($out, $this->inputMode);
		VarInt::writeUnsignedInt($out, $this->playMode);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			VarInt::writeSignedInt($out, $this->interactionMode);
		}else{
			VarInt::writeUnsignedInt($out, $this->interactionMode);
		}
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_40){
			CommonTypes::putVector2($out, $this->interactRotation);
		}elseif($this->playMode === PlayMode::VR){
			assert($this->vrGazeDirection !== null);
			CommonTypes::putVector3($out, $this->vrGazeDirection);
		}
		VarInt::writeUnsignedLong($out, $this->tick);
		CommonTypes::putVector3($out, $this->delta);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			CommonTypes::putBool($out, true);
			CommonTypes::putBool($out, $this->itemInteractionData !== null);
			$this->itemInteractionData?->write($out, $protocolId);

			CommonTypes::putBool($out, true);
			CommonTypes::putBool($out, $this->itemStackRequest !== null);
			$this->itemStackRequest?->write($out, $protocolId);

			CommonTypes::putBool($out, true);
			CommonTypes::putBool($out, $this->blockActions !== null);
			if($this->blockActions !== null){
				VarInt::writeUnsignedInt($out, count($this->blockActions));
				foreach($this->blockActions as $blockAction){
					$this->writeBlockAction($out, $protocolId, $blockAction);
				}
			}

			CommonTypes::putBool($out, true);
			CommonTypes::putBool($out, $this->vehicleInfo !== null);
			if($this->vehicleInfo !== null){
				LE::writeFloat($out, $this->vehicleInfo->getVehicleRotationX() ?? 0.0);
				LE::writeFloat($out, $this->vehicleInfo->getVehicleRotationZ() ?? 0.0);
			}
			CommonTypes::putBool($out, true);
			CommonTypes::putBool($out, $this->vehicleInfo !== null);
			if($this->vehicleInfo !== null){
				CommonTypes::putActorUniqueId($out, $this->vehicleInfo->getPredictedVehicleActorUniqueId());
			}
		}else{
			if($this->itemInteractionData !== null){
				$this->itemInteractionData->write($out, $protocolId);
			}
			if($this->itemStackRequest !== null){
				$this->itemStackRequest->write($out, $protocolId);
			}
			if($this->blockActions !== null){
				VarInt::writeSignedInt($out, count($this->blockActions));
				foreach($this->blockActions as $blockAction){
					$this->writeBlockAction($out, $protocolId, $blockAction);
				}
			}
			if($this->vehicleInfo !== null && $protocolId >= ProtocolInfo::PROTOCOL_1_20_60){
				$this->vehicleInfo->write($out, $protocolId);
			}
		}
		LE::writeFloat($out, $this->analogMoveVecX);
		LE::writeFloat($out, $this->analogMoveVecZ);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_40){
			CommonTypes::putVector3($out, $this->cameraOrientation);
			if($protocolId >= ProtocolInfo::PROTOCOL_1_21_50){
				CommonTypes::putVector2($out, $this->rawMove);
			}
		}
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handlePlayerAuthInput($this);
	}
}
