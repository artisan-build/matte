<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteClient\Facades;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http as LaravelHttp;

/**
 * @method static PendingRequest withClientIdentity()
 */
final class Http extends LaravelHttp {}
