<?php

namespace Pterodactyl\Services\MinecraftPlayers;

/**
 * Lossless NBT codec for editing player files. Unlike NbtReader (which decodes
 * values for display), every tag keeps its type and numeric tags keep their raw
 * big-endian bytes, so anything we don't touch is written back byte-for-byte.
 *
 * Node shapes:
 *   ['t' => 1..6, 'r' => raw bytes]                 byte/short/int/long/float/double
 *   ['t' => 7|11|12, 'n' => count, 'r' => raw bytes] byte/int/long arrays
 *   ['t' => 8, 'v' => string]
 *   ['t' => 9, 's' => subtype, 'v' => node[]]
 *   ['t' => 10, 'v' => [name => node, ...]]
 */
class TypedNbt
{
    private const MAX_RAW = 8388608;

    private string $data = '';

    private int $offset = 0;

    private int $nodes = 0;

    /**
     * @return array{name: string, root: array}
     */
    public function decode(string $bytes): array
    {
        if (strlen($bytes) > 2097152) {
            throw new \UnexpectedValueException('Compressed player data exceeds 2 MiB.');
        }
        if (str_starts_with($bytes, "\x1f\x8b")) {
            $bytes = @gzdecode($bytes, self::MAX_RAW);
            if ($bytes === false) {
                throw new \UnexpectedValueException('Invalid or oversized gzip player data.');
            }
        }
        $this->data = $bytes;
        $this->offset = 0;
        $this->nodes = 0;
        if (ord($this->read(1)) !== 10) {
            throw new \UnexpectedValueException('Expected an NBT compound root.');
        }
        $name = $this->readString();
        $root = $this->payload(10, 0);
        if ($this->offset !== strlen($this->data)) {
            throw new \UnexpectedValueException('Unexpected trailing NBT bytes.');
        }

        return ['name' => $name, 'root' => $root];
    }

    /**
     * Serialize back to a gzip-compressed named root compound.
     */
    public function encode(string $name, array $root): string
    {
        $body = "\x0a" . $this->writeString($name) . $this->writePayload($root);

        return gzencode($body, 6);
    }

    public static function byte(int $value): array
    {
        return ['t' => 1, 'r' => pack('c', $value)];
    }

    public static function short(int $value): array
    {
        return ['t' => 2, 'r' => pack('n', $value & 0xFFFF)];
    }

    public static function int(int $value): array
    {
        return ['t' => 3, 'r' => pack('N', $value & 0xFFFFFFFF)];
    }

    public static function str(string $value): array
    {
        return ['t' => 8, 'v' => $value];
    }

    /**
     * @param array<string, array> $children name => node
     */
    public static function compound(array $children = []): array
    {
        $prefixed = [];
        foreach ($children as $name => $child) {
            $prefixed['k' . $name] = $child;
        }

        return ['t' => 10, 'v' => $prefixed];
    }

    public static function get(?array $compound, string $name): ?array
    {
        return is_array($compound) && ($compound['t'] ?? null) === 10 ? ($compound['v']['k' . $name] ?? null) : null;
    }

    public static function set(array &$compound, string $name, array $child): void
    {
        $compound['v']['k' . $name] = $child;
    }

    public static function remove(array &$compound, string $name): void
    {
        unset($compound['v']['k' . $name]);
    }

    /** Decoded integer value of a numeric node, or null. */
    public static function intValue(?array $node): ?int
    {
        if (!is_array($node)) {
            return null;
        }

        return match ($node['t'] ?? null) {
            1 => unpack('c', $node['r'])[1],
            2 => (($v = unpack('n', $node['r'])[1]) >= 32768 ? $v - 65536 : $v),
            3 => (($v = unpack('N', $node['r'])[1]) >= 2147483648 ? $v - 4294967296 : $v),
            default => null,
        };
    }

    public static function stringValue(?array $node): ?string
    {
        return is_array($node) && ($node['t'] ?? null) === 8 ? $node['v'] : null;
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

    private function readString(): string
    {
        return $this->read(unpack('n', $this->read(2))[1]);
    }

    private function length(int $maximum): int
    {
        $value = unpack('N', $this->read(4))[1];
        if ($value >= 2147483648 || $value > $maximum) {
            throw new \UnexpectedValueException('NBT collection exceeds its limit.');
        }

        return $value;
    }

    private function payload(int $type, int $depth): array
    {
        if ($depth > 512 || ++$this->nodes > 500000) {
            throw new \UnexpectedValueException('NBT structure exceeds its limit.');
        }

        switch ($type) {
            case 1:
                return ['t' => 1, 'r' => $this->read(1)];
            case 2:
                return ['t' => 2, 'r' => $this->read(2)];
            case 3:
            case 5:
                return ['t' => $type, 'r' => $this->read(4)];
            case 4:
            case 6:
                return ['t' => $type, 'r' => $this->read(8)];
            case 7:
                $count = $this->length(self::MAX_RAW);

                return ['t' => 7, 'n' => $count, 'r' => $this->read($count)];
            case 11:
                $count = $this->length(self::MAX_RAW >> 2);

                return ['t' => 11, 'n' => $count, 'r' => $this->read($count * 4)];
            case 12:
                $count = $this->length(self::MAX_RAW >> 3);

                return ['t' => 12, 'n' => $count, 'r' => $this->read($count * 8)];
            case 8:
                return ['t' => 8, 'v' => $this->readString()];
            case 9:
                $subtype = ord($this->read(1));
                $count = $this->length(1000000);
                if ($subtype === 0 && $count > 0) {
                    throw new \UnexpectedValueException('Invalid nonempty TAG_End list.');
                }
                $items = [];
                for ($index = 0; $index < $count; $index++) {
                    $items[] = $this->payload($subtype, $depth + 1);
                }

                return ['t' => 9, 's' => $subtype, 'v' => $items];
            case 10:
                $children = [];
                while (($subtype = ord($this->read(1))) !== 0) {
                    $name = $this->readString();
                    // Prefix keeps numeric-looking names as strings and preserves order.
                    $children['k' . $name] = $this->payload($subtype, $depth + 1);
                }

                return ['t' => 10, 'v' => $children];
            default:
                throw new \UnexpectedValueException('Unsupported NBT tag type.');
        }
    }

    private function writeString(string $value): string
    {
        if (strlen($value) > 65535) {
            throw new \UnexpectedValueException('NBT string is too long.');
        }

        return pack('n', strlen($value)) . $value;
    }

    private function writePayload(array $node): string
    {
        $type = $node['t'];

        switch ($type) {
            case 1:
            case 2:
            case 3:
            case 4:
            case 5:
            case 6:
                return $node['r'];
            case 7:
            case 11:
            case 12:
                return pack('N', $node['n']) . $node['r'];
            case 8:
                return $this->writeString($node['v']);
            case 9:
                $subtype = $node['v'] === [] ? $node['s'] : $node['v'][0]['t'];
                $out = chr($subtype) . pack('N', count($node['v']));
                foreach ($node['v'] as $item) {
                    if ($item['t'] !== $subtype) {
                        throw new \UnexpectedValueException('Mixed NBT list types.');
                    }
                    $out .= $this->writePayload($item);
                }

                return $out;
            case 10:
                $out = '';
                foreach ($node['v'] as $key => $child) {
                    $out .= chr($child['t']) . $this->writeString(substr((string) $key, 1)) . $this->writePayload($child);
                }

                return $out . "\x00";
            default:
                throw new \UnexpectedValueException('Unsupported NBT tag type.');
        }
    }
}
