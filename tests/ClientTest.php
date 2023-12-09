<?php
declare(strict_types=1);
namespace Sequra\PhpClient\Tests;

use PHPUnit\Framework\TestCase;
use Sequra\PhpClient\Client;
use Symfony\Component\Dotenv\Dotenv;

final class ClientTest extends TestCase
{
    private static $username;
    private static $password;
    private static $endpoint;
    private static $merchant;

    public static function setUpBeforeClass(): void
    {
        $dotenv = new Dotenv();
        $dotenv->load(__DIR__ . '/../.env');
        self::$username = $_ENV['SEQURA_USERNAME'];
        self::$password = $_ENV['SEQURA_PASSWORD'];
        self::$endpoint = $_ENV['SEQURA_ENDPOINT'];
        self::$merchant = $_ENV['SEQURA_MERCHANT'];
    }

    public function testCanBeCreated(): void
    {
        $this->assertInstanceOf(
            Client::class,
            new Client(self::$username, self::$password, self::$endpoint)
        );
    }

    public function testCanBeCreatedWithLogs(): void
    {
        $logfile = "/tmp/test_sequra.log";
        $this->assertInstanceOf(
            Client::class,
            new Client(self::$username, self::$password, self::$endpoint, true, $logfile)
        );
        $this->assertFileExists($logfile);
        unlink($logfile);
    }

    public function testIsValidAuth(): void
    {
        $client = new Client(self::$username, self::$password, self::$endpoint);
        $this->assertTrue($client->isValidAuth(), "isValidAuth() should return true for " . self::$username . " " . self::$password);
        $client = new Client(self::$username, 'INVALID PASSWORD', self::$endpoint);
        $this->assertFalse($client->isValidAuth(), "isValidAuth() should return false");
    }

    public function testGetAvailableDisbursements(): void
    {
        $client = new Client(self::$username, self::$password, self::$endpoint);
        $client->getAvailableDisbursements(self::$merchant);
        $disbursements = $client->getJson();
        $this->assertIsList($disbursements, "getAvailableDisbursements() should return a list of available disbursements");
    }

    public function testGetDisbursementDetails(): void
    {
        $client = new Client(self::$username, self::$password, self::$endpoint);
        $client->getAvailableDisbursements(self::$merchant);
        $disbursements = $client->getJson();
        $client->getAvailableDisbursements($disbursements[0]['disbursement']['path']);
        $disbursement = $client->getJson();
        $this->assertIsArray($disbursement['disbursement'], "getDisbursementDetails() should return a disbursement");
    }
}
