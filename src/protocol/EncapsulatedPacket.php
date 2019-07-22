<?php

/*
 * RakLib network library
 *
 *
 * This project is not affiliated with Jenkins Software LLC nor RakNet.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 */

declare(strict_types=1);

namespace raklib\protocol;

use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;
use function ceil;
use function chr;
use function ord;
use function strlen;
use function substr;

#ifndef COMPILE
use pocketmine\utils\Binary;
#endif

#include <rules/RakLibPacket.h>

class EncapsulatedPacket{
	private const RELIABILITY_SHIFT = 5;
	private const RELIABILITY_FLAGS = 0b111 << self::RELIABILITY_SHIFT;

	private const SPLIT_FLAG = 0b00010000;

	/** @var int */
	public $reliability;
	/** @var bool */
	public $hasSplit = false;
	/** @var int */
	public $length = 0;
	/** @var int|null */
	public $messageIndex;
	/** @var int|null */
	public $sequenceIndex;
	/** @var int|null */
	public $orderIndex;
	/** @var int|null */
	public $orderChannel;
	/** @var int|null */
	public $splitCount;
	/** @var int|null */
	public $splitID;
	/** @var int|null */
	public $splitIndex;
	/** @var string */
	public $buffer = "";
	/** @var bool */
	public $needACK = false;
	/** @var int|null */
	public $identifierACK;

	/**
	 * Decodes an EncapsulatedPacket from bytes generated by toInternalBinary().
	 *
	 * @param string $bytes
	 *
	 * @return EncapsulatedPacket
	 */
	public static function fromInternalBinary(string $bytes) : EncapsulatedPacket{
		$packet = new EncapsulatedPacket();

		$offset = 0;
		$packet->reliability = ord($bytes[$offset++]);

		$packet->identifierACK = Binary::readInt(substr($bytes, $offset, 4)); //TODO: don't read this for non-ack-receipt reliabilities
		$offset += 4;

		if(PacketReliability::isSequencedOrOrdered($packet->reliability)){
			$packet->orderChannel = ord($bytes[$offset++]);
		}

		$packet->buffer = substr($bytes, $offset);
		return $packet;
	}

	/**
	 * Encodes data needed for the EncapsulatedPacket to be transmitted from RakLib to the implementation's thread.
	 * @return string
	 */
	public function toInternalBinary() : string{
		return
			chr($this->reliability) .
			Binary::writeInt($this->identifierACK ?? -1) . //TODO: don't write this for non-ack-receipt reliabilities
			(PacketReliability::isSequencedOrOrdered($this->reliability) ? chr($this->orderChannel) : "") .
			$this->buffer;
	}

	/**
	 * @param BinaryStream $stream
	 *
	 * @return EncapsulatedPacket
	 * @throws BinaryDataException
	 */
	public static function fromBinary(BinaryStream $stream) : EncapsulatedPacket{
		$packet = new EncapsulatedPacket();

		$flags = $stream->getByte();
		$packet->reliability = $reliability = ($flags & self::RELIABILITY_FLAGS) >> self::RELIABILITY_SHIFT;
		$packet->hasSplit = $hasSplit = ($flags & self::SPLIT_FLAG) > 0;

		$length = (int) ceil($stream->getShort() / 8);
		if($length === 0){
			throw new BinaryDataException("Encapsulated payload length cannot be zero");
		}

		if($reliability > PacketReliability::UNRELIABLE){
			if(PacketReliability::isReliable($reliability)){
				$packet->messageIndex = $stream->getLTriad();
			}

			if(PacketReliability::isSequenced($reliability)){
				$packet->sequenceIndex = $stream->getLTriad();
			}

			if(PacketReliability::isSequencedOrOrdered($reliability)){
				$packet->orderIndex = $stream->getLTriad();
				$packet->orderChannel = $stream->getByte();
			}
		}

		if($hasSplit){
			$packet->splitCount = $stream->getInt();
			$packet->splitID = $stream->getShort();
			$packet->splitIndex = $stream->getInt();
		}

		$packet->buffer = $stream->get($length);
		return $packet;
	}

	/**
	 * @return string
	 */
	public function toBinary() : string{
		return
			chr(($this->reliability << self::RELIABILITY_SHIFT) | ($this->hasSplit ? self::SPLIT_FLAG : 0)) .
			Binary::writeShort(strlen($this->buffer) << 3) .
			($this->reliability > PacketReliability::UNRELIABLE ?
				(PacketReliability::isReliable($this->reliability) ? Binary::writeLTriad($this->messageIndex) : "") .
				(PacketReliability::isSequenced($this->reliability) ? Binary::writeLTriad($this->sequenceIndex) : "") .
				(PacketReliability::isSequencedOrOrdered($this->reliability) ? Binary::writeLTriad($this->orderIndex) . chr($this->orderChannel) : "")
				: ""
			) .
			($this->hasSplit ? Binary::writeInt($this->splitCount) . Binary::writeShort($this->splitID) . Binary::writeInt($this->splitIndex) : "")
			. $this->buffer;
	}

	public function getTotalLength() : int{
		return 3 + strlen($this->buffer) + ($this->messageIndex !== null ? 3 : 0) + ($this->orderIndex !== null ? 4 : 0) + ($this->hasSplit ? 10 : 0);
	}

	public function __toString() : string{
		return $this->toBinary();
	}
}
