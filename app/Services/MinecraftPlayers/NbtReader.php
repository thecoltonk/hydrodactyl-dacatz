<?php

/*
 * Ported from Aquadactyl/MCPlayersJava (https://github.com/Aquadactyl/MCPlayersJava).
 * Copyright (c) Aquadactyl contributors. Licensed under the BSD 2-Clause License;
 * see licenses/MCPlayersJava.LICENSE for the full text.
 */

namespace Pterodactyl\Services\MinecraftPlayers;

/** Bounded reader for Java's named, big-endian NBT player files. */
class NbtReader
{
    private string $data = '';

    private int $offset = 0;

    private int $nodes = 0;

    public function parse(string $bytes): array
    {
        if (strlen($bytes) > 2097152) {
            throw new \UnexpectedValueException('Compressed player data exceeds 2 MiB.');
        }
        if (str_starts_with($bytes, "\x1f\x8b")) {
            $bytes = @gzdecode($bytes, 8388608);
            if ($bytes === false) {
                throw new \UnexpectedValueException('Invalid or oversized gzip player data.');
            }
        }
        if (strlen($bytes) > 8388608) {
            throw new \UnexpectedValueException('Player data exceeds 8 MiB.');
        }
        $this->data = $bytes;
        $this->offset = 0;
        $this->nodes = 0;
        if ($this->byte() !== 10) {
            throw new \UnexpectedValueException('Expected an NBT compound root.');
        }
        $this->string();
        $root = $this->payload(10, 0);
        if ($this->offset !== strlen($this->data)) {
            throw new \UnexpectedValueException('Unexpected trailing NBT bytes.');
        }

        return $root;
    }

    private function read(int $length): string
    {
        if ($length < 0 || $this->offset + $length > strlen($this->data)) {
            throw new \UnexpectedValueException('Truncated NBT data.');
        }
        $value = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $value;
    }

    private function byte(): int
    {
        return ord($this->read(1));
    }

    private function integer(): int
    {
        $value = unpack('N', $this->read(4))[1];

        return $value >= 2147483648 ? $value - 4294967296 : $value;
    }

    private function string(): string
    {
        return $this->read(unpack('n', $this->read(2))[1]);
    }

    private function length(int $maximum = 10000): int
    {
        $value = $this->integer();
        if ($value < 0 || $value > $maximum) {
            throw new \UnexpectedValueException('NBT collection exceeds its limit.');
        }

        return $value;
    }

    private function payload(int $type, int $depth): mixed
    {
        if ($depth > 64 || ++$this->nodes > 50000) {
            throw new \UnexpectedValueException('NBT structure exceeds its limit.');
        }
        switch ($type) {
            case 1:
                $byte = $this->byte();

                return $byte >= 128 ? $byte - 256 : $byte;
            case 2:
                $short = unpack('n', $this->read(2))[1];

                return $short >= 32768 ? $short - 65536 : $short;
            case 3:
                return $this->integer();
            case 4:
                // Preserve 64-bit metadata without losing precision in browser JSON.
                return (string) unpack('J', $this->read(8))[1];
            case 5:
                return unpack('G', $this->read(4))[1];
            case 6:
                return unpack('E', $this->read(8))[1];
            case 7:
                return ['byte_array_base64' => base64_encode($this->read($this->length(8388608)))];
            case 8:
                return $this->string();
            case 9:
                $subtype = $this->byte();
                $length = $this->length();
                if ($subtype === 0 && $length > 0) {
                    throw new \UnexpectedValueException('Invalid nonempty TAG_End list.');
                }
                $list = [];
                for ($index = 0; $index < $length; $index++) {
                    $list[] = $this->payload($subtype, $depth + 1);
                }

                return $list;
            case 10:
                $compound = [];
                while (($subtype = $this->byte()) !== 0) {
                    $name = $this->string();
                    $compound[$name] = $this->payload($subtype, $depth + 1);
                }

                return $compound;
            case 11:
            case 12:
                $length = $this->length();
                $array = [];
                for ($index = 0; $index < $length; $index++) {
                    $array[] = $this->payload($type === 11 ? 3 : 4, $depth + 1);
                }

                return $array;
            default:
                throw new \UnexpectedValueException('Unsupported NBT tag type.');
        }
    }
}
