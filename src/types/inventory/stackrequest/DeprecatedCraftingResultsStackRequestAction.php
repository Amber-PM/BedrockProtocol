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
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\GetTypeIdFromConstTrait;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\StackRequestItem;
use function count;

/**
 * Not clear what this is needed for, but it is very clearly marked as deprecated, so hopefully it'll go away before I
 * have to write a proper description for it.
 */
final class DeprecatedCraftingResultsStackRequestAction extends ItemStackRequestAction{
	use GetTypeIdFromConstTrait;

	public const ID = ItemStackRequestActionType::CRAFTING_RESULTS_DEPRECATED_ASK_TY_LAING;

	/**
	 * As of 1.26.40 the results are sent as name-based descriptors instead of item stacks. Those are kept separately
	 * so that neither representation has to be lossily converted into the other.
	 *
	 * @var StackRequestItem[]
	 * @phpstan-var list<StackRequestItem>
	 */
	private array $descriptorResults = [];

	/**
	 * @param ItemStack[] $results
	 */
	public function __construct(
		private array $results,
		private int $iterations
	){}

	/** @return ItemStack[] */
	public function getResults() : array{ return $this->results; }

	/**
	 * Only populated for protocol 1.26.40 and newer.
	 *
	 * @return StackRequestItem[]
	 * @phpstan-return list<StackRequestItem>
	 */
	public function getDescriptorResults() : array{ return $this->descriptorResults; }

	public function getIterations() : int{ return $this->iterations; }

	public static function read(ByteBufferReader $in, int $protocolId) : self{
		$results = [];
		$descriptorResults = [];
		for($i = 0, $len = VarInt::readUnsignedInt($in); $i < $len; ++$i){
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				$descriptorResults[] = StackRequestItem::read($in);
			}else{
				$results[] = CommonTypes::getItemStackWithoutStackId($in, $protocolId);
			}
		}
		$iterations = Byte::readUnsigned($in);
		$result = new self($results, $iterations);
		$result->descriptorResults = $descriptorResults;
		return $result;
	}

	public function write(ByteBufferWriter $out, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			VarInt::writeUnsignedInt($out, count($this->descriptorResults));
			foreach($this->descriptorResults as $result){
				$result->write($out);
			}
		}else{
			VarInt::writeUnsignedInt($out, count($this->results));
			foreach($this->results as $result){
				CommonTypes::putItemStackWithoutStackId($out, $protocolId, $result);
			}
		}
		Byte::writeUnsigned($out, $this->iterations);
	}
}
