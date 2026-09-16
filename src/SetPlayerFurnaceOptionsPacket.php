<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;

/** 1.26.50+: furnace screen options (left tab, filtering, layout). Sent by both sides. */
class SetPlayerFurnaceOptionsPacket extends DataPacket implements ClientboundPacket, ServerboundPacket{
	public const NETWORK_ID = ProtocolInfo::SET_PLAYER_FURNACE_OPTIONS_PACKET;

	public int $furnaceType = 0;
	public int $leftTabIndex = 0;
	public bool $filtering = false;
	public int $layout = 0;

	public static function create(int $furnaceType, int $leftTabIndex, bool $filtering, int $layout) : self{
		$result = new self;
		$result->furnaceType = $furnaceType;
		$result->leftTabIndex = $leftTabIndex;
		$result->filtering = $filtering;
		$result->layout = $layout;
		return $result;
	}

	protected function decodePayload(ByteBufferReader $in, int $protocolId) : void{
		$this->furnaceType = Byte::readUnsigned($in);
		$this->leftTabIndex = VarInt::readSignedInt($in);
		$this->filtering = CommonTypes::getBool($in);
		$this->layout = VarInt::readSignedInt($in);
	}

	protected function encodePayload(ByteBufferWriter $out, int $protocolId) : void{
		Byte::writeUnsigned($out, $this->furnaceType);
		VarInt::writeSignedInt($out, $this->leftTabIndex);
		CommonTypes::putBool($out, $this->filtering);
		VarInt::writeSignedInt($out, $this->layout);
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handleSetPlayerFurnaceOptions($this);
	}
}
