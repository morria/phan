<?php
declare(strict_types=1);

namespace Phan\Tests\LanguageServer;

use Phan\LanguageServer\Protocol\Message;
use Phan\LanguageServer\ProtocolReader;
use Phan\LanguageServer\ProtocolWriter;
use Sabre\Event\Emitter;
use Sabre\Event\Loop;
use Sabre\Event\Promise;

/**
 * A fake duplex protocol stream
 */
class MockProtocolStream extends Emitter implements ProtocolReader, ProtocolWriter
{
    /**
     * Sends a Message to the client
     *
     * @param Message $msg
     * @return Promise<void>
     */
    public function write(Message $msg): Promise
    {
        Loop\nextTick(function () use ($msg) {
            $this->emit('message', [Message::parse((string)$msg)]);
        });
        return Promise\resolve(null);
    }

    /**
     * @var bool TODO: use in tests
     * @suppress PhanWriteOnlyPublicProperty not yet used in tests
     */
    public $did_stop_accepting_new_requests = false;

    /**
     * @return void
     */
    public function stopAcceptingNewRequests()
    {
        $this->did_stop_accepting_new_requests = true;
    }
}
