<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Tests\Unit\Network;

use PhpMiniDatabase\Network\Auth\PasswordHash;
use PhpMiniDatabase\Network\Auth\ScramChallenge;
use PhpMiniDatabase\Network\Auth\UserStore;
use PhpMiniDatabase\Network\Protocol\Codec;
use PhpMiniDatabase\Network\Protocol\FrameReader;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\Message\Auth;
use PhpMiniDatabase\Network\Protocol\Message\AuthFail;
use PhpMiniDatabase\Network\Protocol\Message\AuthOk;
use PhpMiniDatabase\Network\Protocol\Message\Hello;
use PhpMiniDatabase\Network\Protocol\Message\HelloAck;
use PhpMiniDatabase\Network\Protocol\Message\Query;
use PhpMiniDatabase\Network\Protocol\Message\QueryResultMessage;
use PhpMiniDatabase\Network\Server;
use PhpMiniDatabase\Network\ServerConfig;
use PhpMiniDatabase\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Authentication end to end, over a real socket — the same "drive `tick()`
 * by hand" approach `ServerTest` uses (see DECISIONS.md), with
 * `authEnabled: true` and a real `UserStore` this time.
 */
final class ServerAuthTest extends TestCase
{
    use TemporaryDirectory;

    private Server $server;

    /** @var resource */
    private mixed $client;

    private Codec $codec;

    private FrameReader $reader;

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();

        $config = new ServerConfig($this->path('mydb'), port: 0, authEnabled: true);

        // Database::open() (inside the Server constructor) is what creates
        // the data directory users.json needs to live in - so the user has
        // to be created only after that, not before.
        $this->server = new Server($config);
        (new UserStore($config->resolvedUserStorePath()))->create('alice', 'secret');

        $this->server->start();
        $this->codec = new Codec();
        $this->reader = new FrameReader();
    }

    protected function tearDown(): void
    {
        if (isset($this->client) && is_resource($this->client)) {
            fclose($this->client);
        }

        $this->server->shutdown();
        $this->tearDownTemporaryDirectory();
    }

    private function connect(): void
    {
        $address = 'tcp://' . $this->server->localAddress();
        $client = @stream_socket_client($address, $errorCode, $errorMessage, 1.0);
        self::assertNotFalse($client, (string) $errorMessage);
        stream_set_blocking($client, false);
        $this->client = $client;
    }

    private function sendToServer(Message $message): void
    {
        fwrite($this->client, $this->codec->encode($message)->toBytes());
    }

    private function readFromServer(): Message
    {
        for ($i = 0; $i < 100; $i++) {
            $this->server->tick(0.05);

            $chunk = @fread($this->client, 65536);

            if ($chunk !== false && $chunk !== '') {
                $this->reader->feed($chunk);
            }

            $frame = $this->reader->next();

            if ($frame !== null) {
                return $this->codec->decode($frame);
            }
        }

        self::fail('Timed out waiting for a response from the server.');
    }

    private function respondAs(string $username, string $password, string $nonce): string
    {
        return ScramChallenge::respond(PasswordHash::derive($password, PasswordHash::saltFor($username)), $nonce);
    }

    private function helloAndGetNonce(): string
    {
        $this->connect();
        $this->sendToServer(new Hello(1, 'phpunit', '1.0'));
        $ack = $this->readFromServer();
        self::assertInstanceOf(HelloAck::class, $ack);
        self::assertSame('challenge_response', $ack->authMethod);

        return $ack->nonce;
    }

    public function testHelloAckOffersChallengeResponseWithANonce(): void
    {
        $nonce = $this->helloAndGetNonce();

        self::assertNotSame('', $nonce);
    }

    public function testAQueryBeforeAuthenticatingIsRefused(): void
    {
        $this->helloAndGetNonce();

        $this->sendToServer(new Query('SELECT 1'));

        for ($i = 0; $i < 10 && $this->server->sessionCount() > 0; $i++) {
            $this->server->tick(0.05);
        }

        self::assertSame(0, $this->server->sessionCount());
    }

    public function testCorrectCredentialsAreAccepted(): void
    {
        $nonce = $this->helloAndGetNonce();

        $this->sendToServer(new Auth('alice', $this->respondAs('alice', 'secret', $nonce)));
        $response = $this->readFromServer();

        self::assertInstanceOf(AuthOk::class, $response);
    }

    public function testAQueryAfterAuthenticatingSucceeds(): void
    {
        $nonce = $this->helloAndGetNonce();
        $this->sendToServer(new Auth('alice', $this->respondAs('alice', 'secret', $nonce)));
        self::assertInstanceOf(AuthOk::class, $this->readFromServer());

        $this->sendToServer(new Query('SELECT 1 + 1 AS two'));
        $result = $this->readFromServer();

        self::assertInstanceOf(QueryResultMessage::class, $result);
        self::assertSame([[2]], $result->rows);
    }

    public function testAWrongPasswordIsRejected(): void
    {
        $nonce = $this->helloAndGetNonce();

        $this->sendToServer(new Auth('alice', $this->respondAs('alice', 'wrong-password', $nonce)));
        $response = $this->readFromServer();

        self::assertInstanceOf(AuthFail::class, $response);
    }

    public function testAnUnknownUsernameIsRejected(): void
    {
        $nonce = $this->helloAndGetNonce();

        $this->sendToServer(new Auth('nobody', $this->respondAs('nobody', 'whatever', $nonce)));
        $response = $this->readFromServer();

        self::assertInstanceOf(AuthFail::class, $response);
    }

    public function testAFailedAttemptDoesNotCloseTheConnectionAndARetrySucceeds(): void
    {
        $nonce = $this->helloAndGetNonce();

        $this->sendToServer(new Auth('alice', $this->respondAs('alice', 'wrong-password', $nonce)));
        self::assertInstanceOf(AuthFail::class, $this->readFromServer());
        self::assertSame(1, $this->server->sessionCount(), 'the connection must still be open after one failed attempt');

        $this->sendToServer(new Auth('alice', $this->respondAs('alice', 'secret', $nonce)));
        self::assertInstanceOf(AuthOk::class, $this->readFromServer());

        $this->sendToServer(new Query('SELECT 1'));
        self::assertInstanceOf(QueryResultMessage::class, $this->readFromServer());
    }

    public function testEnoughFailedAttemptsEventuallyLockTheAccountOut(): void
    {
        $nonce = $this->helloAndGetNonce();

        // LoginThrottle's default maxAttempts is 5; the 6th failure locks
        // the account, so even the *correct* password stops working right
        // after.
        for ($i = 0; $i < 6; $i++) {
            $this->sendToServer(new Auth('alice', $this->respondAs('alice', 'wrong-password', $nonce)));
            self::assertInstanceOf(AuthFail::class, $this->readFromServer());
        }

        $this->sendToServer(new Auth('alice', $this->respondAs('alice', 'secret', $nonce)));
        $response = $this->readFromServer();

        self::assertInstanceOf(AuthFail::class, $response);
    }

    public function testDevModeStillWorksWhenAuthIsNotEnabled(): void
    {
        $this->server->shutdown();
        $this->server = new Server(new ServerConfig($this->path('mydb-dev')));
        $this->server->start();

        $this->connect();
        $this->sendToServer(new Hello(1, 'phpunit', '1.0'));
        $ack = $this->readFromServer();

        self::assertInstanceOf(HelloAck::class, $ack);
        self::assertSame('none', $ack->authMethod);

        $this->sendToServer(new Query('SELECT 1'));
        self::assertInstanceOf(QueryResultMessage::class, $this->readFromServer());
    }
}
