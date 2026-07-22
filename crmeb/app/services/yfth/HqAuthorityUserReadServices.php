<?php

namespace app\services\yfth;

class HqAuthorityUserReadServices
{
    private $authority;

    public function __construct(UserRelationshipAuthorityServices $authority)
    {
        $this->authority = $authority;
    }

    public function me(int $uid): array
    {
        return $this->authority->resolve($uid);
    }
}
