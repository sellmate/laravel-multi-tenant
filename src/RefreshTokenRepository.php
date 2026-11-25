<?php

namespace Sellmate\Laravel\MultiTenant;

use Laravel\Passport\Bridge\RefreshTokenRepository as BaseRepository;

/**
 * @deprecated Use Laravel\Passport\Bridge\RefreshTokenRepository; it behaves identically.
 */
class RefreshTokenRepository extends BaseRepository
{
}
