<?php

namespace Pterodactyl\Services\MinecraftPlayers;

/**
 * Minimal, bounded Minecraft Java player-list client.
 *
 * Tries the UDP Query protocol (full name list) when the server has it enabled,
 * then falls back to the TCP Server List Ping (a capped sample of names).
 * Replaces the GameDig/Node helper the original addon shelled out to.
 */
class ServerQuery
{
    private const NAME = '/\A[A-Za-z0-9_]{1,16}\z/';

    private const UUID = '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i';

    private const MAX_RESPONSE = 262144;

    /**
     * @param array{statusHost: string, statusPort: int, queryHost: string, queryPort: int|null} $target
     */
    public function players(array $target): array
    {
        if ($target['queryPort'] !== null) {
            try {
                return $this->normalize($this->fullStat($target['queryHost'], $target['queryPort']), 'query');
            } catch (\Throwable) {
                // Fall through to the TCP status sample.
            }
        }

        try {
            return $this->normalize($this->status($target['statusHost'], $target['statusPort']), 'status');
        } catch (\Throwable) {
            return self::unavailable();
        }
    }

    public static function unavailable(): array
    {
        return ['status' => 'unavailable', 'source' => null, 'complete' => false, 'online' => null, 'maximum' => null, 'players' => []];
    }

    /**
     * @param array{online: mixed, maximum: mixed, players: array} $result
     */
    public function normalize(array $result, string $source): array
    {
        foreach (['online', 'maximum'] as $key) {
            if (!is_int($result[$key]) || $result[$key] < 0 || $result[$key] > 1000000) {
                throw new \UnexpectedValueException('Invalid player count.');
            }
        }

        $seen = [];
        $players = [];
        foreach (array_slice($result['players'], 0, 10000) as $player) {
            $name = $player['name'] ?? null;
            if (!is_string($name) || !preg_match(self::NAME, $name) || isset($seen[strtolower($name)])) {
                continue;
            }
            $seen[strtolower($name)] = true;
            $uuid = $player['uuid'] ?? null;
            $players[] = [
                'name' => $name,
                'uuid' => is_string($uuid) && preg_match(self::UUID, $uuid) ? strtolower($uuid) : null,
            ];
        }
        usort($players, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return [
            'status' => 'available',
            'source' => $source,
            // A status reply is a sample, even when it happens to contain everyone.
            'complete' => $source === 'query' && count($players) === $result['online'],
            'online' => $result['online'],
            'maximum' => $result['maximum'],
            'players' => $players,
        ];
    }

    /**
     * Server List Ping over TCP.
     */
    private function status(string $host, int $port): array
    {
        $socket = $this->open('tcp', $host, $port);
        try {
            $handshake = "\x00" . $this->varint(-1) . $this->varint(strlen($host)) . $host . pack('n', $port) . $this->varint(1);
            fwrite($socket, $this->varint(strlen($handshake)) . $handshake . "\x01\x00");

            $length = $this->readVarint($socket);
            if ($length < 3 || $length > self::MAX_RESPONSE) {
                throw new \UnexpectedValueException('Invalid status length.');
            }
            $packet = $this->readExactly($socket, $length);
            $offset = 0;
            if ($this->varintFrom($packet, $offset) !== 0) {
                throw new \UnexpectedValueException('Unexpected status packet.');
            }
            $jsonLength = $this->varintFrom($packet, $offset);
            $json = substr($packet, $offset, $jsonLength);
            if (strlen($json) !== $jsonLength) {
                throw new \UnexpectedValueException('Truncated status JSON.');
            }
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } finally {
            fclose($socket);
        }

        $players = [];
        foreach ((array) ($data['players']['sample'] ?? []) as $entry) {
            if (is_array($entry)) {
                $players[] = ['name' => $entry['name'] ?? null, 'uuid' => $entry['id'] ?? null];
            }
        }

        return [
            'online' => $data['players']['online'] ?? null,
            'maximum' => $data['players']['max'] ?? null,
            'players' => $players,
        ];
    }

    /**
     * GameSpy4 "full stat" Query over UDP.
     */
    private function fullStat(string $host, int $port): array
    {
        $socket = $this->open('udp', $host, $port);
        try {
            $session = random_int(0, 0x7FFFFFFF) & 0x0F0F0F0F;

            fwrite($socket, "\xFE\xFD\x09" . pack('N', $session));
            $reply = $this->datagram($socket);
            if (strlen($reply) < 6 || $reply[0] !== "\x09" || unpack('N', substr($reply, 1, 4))[1] !== $session) {
                throw new \UnexpectedValueException('Invalid Query handshake.');
            }
            $token = rtrim(substr($reply, 5), "\0");
            if (!preg_match('/\A-?\d{1,11}\z/', $token)) {
                throw new \UnexpectedValueException('Invalid Query token.');
            }

            fwrite($socket, "\xFE\xFD\x00" . pack('N', $session) . pack('N', ((int) $token) & 0xFFFFFFFF) . "\x00\x00\x00\x00");
            $reply = $this->datagram($socket);
        } finally {
            fclose($socket);
        }

        if (strlen($reply) < 16 || $reply[0] !== "\x00" || unpack('N', substr($reply, 1, 4))[1] !== $session) {
            throw new \UnexpectedValueException('Invalid Query reply.');
        }

        // 5-byte header, then 11 bytes of "splitnum" padding.
        $body = substr($reply, 16);
        $parts = explode("\x00\x01player_\x00\x00", $body, 2);
        if (count($parts) !== 2) {
            throw new \UnexpectedValueException('Malformed Query reply.');
        }

        $fields = explode("\x00", $parts[0]);
        $info = [];
        for ($i = 0; $i + 1 < count($fields); $i += 2) {
            if ($fields[$i] === '') {
                break;
            }
            $info[strtolower($fields[$i])] = $fields[$i + 1];
        }
        // Other games speak GameSpy too; never list a non-Minecraft reply.
        if (strtolower($info['game_id'] ?? $info['gamename'] ?? '') !== 'minecraft') {
            throw new \UnexpectedValueException('Not a Minecraft Query reply.');
        }

        $players = [];
        foreach (explode("\x00", $parts[1]) as $name) {
            if ($name === '') {
                break;
            }
            $players[] = ['name' => $name, 'uuid' => null];
        }

        return [
            'online' => ctype_digit($info['numplayers'] ?? '') ? (int) $info['numplayers'] : null,
            'maximum' => ctype_digit($info['maxplayers'] ?? '') ? (int) $info['maxplayers'] : null,
            'players' => $players,
        ];
    }

    /**
     * @return resource
     */
    private function open(string $transport, string $host, int $port)
    {
        if ($port < 1 || $port > 65535 || $host === '' || strlen($host) > 253 || preg_match('/[\s\/\\\\]/', $host)) {
            throw new \UnexpectedValueException('Invalid target.');
        }
        $address = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host;
        $socket = @stream_socket_client("{$transport}://{$address}:{$port}", $errno, $error, 2);
        if ($socket === false) {
            throw new \RuntimeException("Connection failed: {$error}");
        }
        stream_set_timeout($socket, $transport === 'udp' ? 1 : 2);

        return $socket;
    }

    /**
     * @param resource $socket
     */
    private function datagram($socket): string
    {
        $data = fread($socket, 65535);
        if ($data === false || $data === '' || stream_get_meta_data($socket)['timed_out']) {
            throw new \RuntimeException('Query timed out.');
        }

        return $data;
    }

    /**
     * @param resource $socket
     */
    private function readExactly($socket, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($socket, min(8192, $length - strlen($data)));
            if ($chunk === false || $chunk === '' || stream_get_meta_data($socket)['timed_out']) {
                throw new \RuntimeException('Status read timed out.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * @param resource $socket
     */
    private function readVarint($socket): int
    {
        $value = 0;
        for ($i = 0; $i < 5; $i++) {
            $byte = ord($this->readExactly($socket, 1));
            $value |= ($byte & 0x7F) << (7 * $i);
            if (($byte & 0x80) === 0) {
                return $value;
            }
        }

        throw new \UnexpectedValueException('VarInt too long.');
    }

    private function varintFrom(string $data, int &$offset): int
    {
        $value = 0;
        for ($i = 0; $i < 5; $i++) {
            if (!isset($data[$offset])) {
                throw new \UnexpectedValueException('Truncated VarInt.');
            }
            $byte = ord($data[$offset++]);
            $value |= ($byte & 0x7F) << (7 * $i);
            if (($byte & 0x80) === 0) {
                return $value;
            }
        }

        throw new \UnexpectedValueException('VarInt too long.');
    }

    private function varint(int $value): string
    {
        $value &= 0xFFFFFFFF;
        $out = '';
        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            $out .= chr($value !== 0 ? $byte | 0x80 : $byte);
        } while ($value !== 0);

        return $out;
    }
}
