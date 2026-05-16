<?php
declare(strict_types=1);

namespace RequestForwarder\Logger;

use RequestForwarder\ForwardResult;

final class NullLogger implements LoggerInterface
{
    public function log(array $request, ForwardResult $result): void {}
}
