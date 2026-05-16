<?php
declare(strict_types=1);

namespace RequestForwarder\Logger;

use RequestForwarder\ForwardResult;

interface LoggerInterface
{
    /**
     * @param array{
     *     source_label: ?string,
     *     method: string,
     *     target_url: string,
     *     request_headers: array<string,string>,
     *     request_body: string,
     *     client_ip: ?string
     * } $request
     */
    public function log(array $request, ForwardResult $result): void;
}
