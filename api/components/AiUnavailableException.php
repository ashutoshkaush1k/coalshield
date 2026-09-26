<?php

declare(strict_types=1);

namespace app\components;

/** ai-service is down, slow or answered with an error. */
final class AiUnavailableException extends \RuntimeException
{
}
