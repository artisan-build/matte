<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteClient\Exceptions;

use RuntimeException;

final class MatteException extends RuntimeException
{
    public static function unexpectedResponse(int $status, string $body): self
    {
        return new self(sprintf('Matte server returned an unexpected %d response: %s', $status, $body));
    }
}
