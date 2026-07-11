<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;

final class HqAuthoritySource
{
    private $type;
    private $id;

    private function __construct(string $type, string $id)
    {
        $this->type = $type;
        $this->id = $id;
    }

    public static function fromTrusted(string $type, $id, array $untrustedPayload = []): self
    {
        if (array_key_exists('source_unique_key', $untrustedPayload)) {
            throw new ApiException('authority_client_source_key_forbidden');
        }
        $type = trim($type);
        if ($type === '' || !preg_match('/^[a-z][a-z0-9_]{1,63}$/', $type)) {
            throw new ApiException('authority_source_type_invalid');
        }
        if (is_int($id) || (is_string($id) && preg_match('/^[0-9]+$/', trim($id)))) {
            $id = ltrim((string)$id, '0');
            $id = $id === '' ? '0' : $id;
        } else {
            throw new ApiException('authority_source_id_invalid');
        }
        return new self($type, $id);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function id(): string
    {
        return $this->id;
    }
}
