<?php

namespace App\GitLab\Enums;

/**
 * See more in https://docs.gitlab.com/ee/api/access_requests.html#valid-access-levels
 */
enum AccessLevel: int
{
    case Undefined = -1;
    case NoAccess = 0;
    case MinimalAccess = 5;
    case Guest = 10;
    case Reporter = 20;
    case Developer = 30;
    case Maintainer = 40;
    case Owner = 50;

    public function getLabel(): string
    {
        return match ($this) {
            self::Undefined => 'Undefined',
            self::NoAccess => "No access ({$this->value})",
            self::MinimalAccess => 'Minimal access',
            self::Guest => 'Guest',
            self::Reporter => 'Reporter',
            self::Developer => 'Developer',
            self::Maintainer => 'Maintainer',
            self::Owner => 'Owner',
        };
    }

    public function hasAccessToSettings(): bool
    {
        return in_array($this, [
            self::Maintainer,
            self::Owner,
        ]);
    }
}
