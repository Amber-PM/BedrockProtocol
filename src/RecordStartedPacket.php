<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\BlockPosition;

/** 1.26.50+: a jukebox record started playing, with the server sound handle that controls it. */
class RecordStartedPacket extends DataPacket implements ClientboundPacket{
	public const NETWORK_ID = ProtocolInfo::RECORD_STARTED_PACKET;

	public BlockPosition $blockPosition;
	public int $serverSoundHandle;

	public static function create(BlockPosition $blockPosition, int $serverSoundHandle) : self{
		$result = new self;
		$result->blockPosition = $blockPosition;
		$result->serverSoundHandle = $serverSoundHandle;
		return $result;
	}

	protected function decodePayload(ByteBufferReader $in, int $protocolId) : void{
		$this->blockPosition = CommonTypes::getBlockPosition($in, true);
		$this->serverSoundHandle = LE::readUnsignedLong($in);
	}

	protected function encodePayload(ByteBufferWriter $out, int $protocolId) : void{
		CommonTypes::putBlockPosition($out, $this->blockPosition, true);
		LE::writeUnsignedLong($out, $this->serverSoundHandle);
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handleRecordStarted($this);
	}
}
