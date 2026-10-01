<?php

declare(strict_types=1);

namespace Assembly\Ai;

/** AI patch failures (provider down, malformed ops) surface as user-facing errors. */
final class PatchException extends \RuntimeException
{
}
