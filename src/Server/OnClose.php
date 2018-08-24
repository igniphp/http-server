<?php declare(strict_types=1);

namespace Igni\Network\Server;

use Igni\Network\Server;

/**
 * The event happens when the TCP connection between the client and the server is closed.
 */
interface OnClose extends Listener
{
    /**
     * Handles server close event.
     *
     * @param Server $server
     * @param int $clientId
     */
    public function onClose(Server $server, int $clientId): void;
}
