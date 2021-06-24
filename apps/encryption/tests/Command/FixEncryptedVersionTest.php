<?php
/**
 * @author Sujith Haridasan <sharidasan@owncloud.com>
 *
 * @copyright Copyright (c) 2019, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Encryption\Tests\Command;

use OC\Files\Filesystem;
use OC\Files\View;
use OCA\Encryption\AppInfo\Application;
use OCA\Encryption\Command\FixEncryptedVersion;
use Symfony\Component\Console\Tester\CommandTester;
use Test\TestCase;
use Test\Traits\UserTrait;

/**
 * Class FixEncryptedVersionTest
 *
 * @group DB
 * @package OCA\Encryption\Tests\Command
 */
class FixEncryptedVersionTest extends TestCase {
	use UserTrait;

	private $userId;

	/** @var FixEncryptedVersion */
	private $fixEncryptedVersion;

	/** @var CommandTester */
	private $commandTester;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		//Enable encryption
		\OC::$server->getConfig()->setAppValue('core', 'encryption_enabled', 'yes');
		//Enable Masterkey
		\OC::$server->getConfig()->setAppValue('encryption', 'useMasterKey', '1');
	}

	public static function tearDownAfterClass(): void {
		\OC::$server->getConfig()->deleteAppValue('core', 'encryption_enabled');
		\OC::$server->getConfig()->deleteAppValue('core', 'default_encryption_module');
		\OC::$server->getConfig()->deleteAppValues('encryption');
		parent::tearDownAfterClass();
	}

	public function setUp(): void {
		parent::setUp();

		$app = \OC::$server->query(Application::class);
		$encryptionManager = \OC::$server->getEncryptionManager();
		$this->assertTrue($encryptionManager->isReady());

		\OC_Hook::clear();
		$app->registerHooks(\OC::$server->getConfig());
		$app->setUp($encryptionManager);

		$this->userId = $this->getUniqueId('user_');

		$this->createUser($this->userId, 'foo12345678');
		self::loginAsUser($this->userId);

		\OC::$server->getEncryptionManager()->setupStorage();

		$this->fixEncryptedVersion = new FixEncryptedVersion(
			\OC::$server->getConfig(),
			\OC::$server->getLogger(),
			\OC::$server->getRootFolder(),
			\OC::$server->getUserManager(),
			new View('/')
		);
		$this->commandTester = new CommandTester($this->fixEncryptedVersion);

		$this->assertTrue(\OC::$server->getEncryptionManager()->isEnabled());
		$this->assertTrue(\OC::$server->getEncryptionManager()->isReady());
		$this->assertTrue(\OC::$server->getEncryptionManager()->isReadyForUser($this->userId));
	}

	public function tearDown(): void {
		self::logout();
		Filesystem::clearMounts();
		Filesystem::getLoader()->removeStorageWrapper('oc_encryption');
		\OC_Hook::clear();
		parent::tearDown();
	}

	/**
	 * In this test the encrypted version of the file is less than the original value
	 * but greater than zero
	 */
	public function testEncryptedVersionLessThanOriginalValue() {
		$view = new View("/" . $this->userId . "/files");

		$view->touch('hello.txt');
		$view->touch('world.txt');
		$view->touch('foo.txt');
		$view->file_put_contents('hello.txt', 'a test string for hello');
		$view->file_put_contents('hello.txt', 'Yet another value');
		$view->file_put_contents('hello.txt', 'Lets modify again1');
		$view->file_put_contents('hello.txt', 'Lets modify again2');
		$view->file_put_contents('hello.txt', 'Lets modify again3');
		$view->file_put_contents('world.txt', 'a test string for world');
		$view->file_put_contents('world.txt', 'a test string for world');
		$view->file_put_contents('world.txt', 'a test string for world');
		$view->file_put_contents('world.txt', 'a test string for world');
		$view->file_put_contents('foo.txt', 'a foo test');

		$fileInfo1 = $view->getFileInfo('hello.txt');

		$storage1 = $fileInfo1->getStorage();
		$cache1 = $storage1->getCache();
		$fileCache1 = $cache1->get($fileInfo1->getId());

		//Now change the encrypted version to two
		$cacheInfo = ['encryptedVersion' => 2, 'encrypted' => 2];
		$cache1->put($fileCache1->getPath(), $cacheInfo);

		$fileInfo2 = $view->getFileInfo('world.txt');
		$storage2 = $fileInfo2->getStorage();
		$cache2 = $storage2->getCache();
		$filecache2 = $cache2->get($fileInfo2->getId());

		//Now change the encrypted version to 1
		$cacheInfo = ['encryptedVersion' => 1, 'encrypted' => 1];
		$cache2->put($filecache2->getPath(), $cacheInfo);

		$this->commandTester->execute([
			'user' => $this->userId
		]);

		$output = $this->commandTester->getDisplay();

		$this->assertStringContainsString("Verifying the content of file \"/$this->userId/files/foo.txt\"
The file \"/$this->userId/files/foo.txt\" is: OK", $output);
		$this->assertStringContainsString("Verifying the content of file \"/$this->userId/files/hello.txt\"
Attempting to fix the path: \"/$this->userId/files/hello.txt\"
Decrement the encrypted version to 1
Increment the encrypted version to 3
Increment the encrypted version to 4
Increment the encrypted version to 5
The file \"/$this->userId/files/hello.txt\" is: OK
Fixed the file: \"/$this->userId/files/hello.txt\" with version 5", $output);
		$this->assertStringContainsString("Verifying the content of file \"/$this->userId/files/world.txt\"
Attempting to fix the path: \"/$this->userId/files/world.txt\"
Increment the encrypted version to 2
Increment the encrypted version to 3
Increment the encrypted version to 4
The file \"/$this->userId/files/world.txt\" is: OK
Fixed the file: \"/$this->userId/files/world.txt\" with version 4", $output);
	}

	/**
	 * In this test the encrypted version of the file is greater than the original value
	 * but greater than zero
	 */
	public function testEncryptedVersionGreaterThanOriginalValue() {
		$view = new View("/" . $this->userId . "/files");

		$view->touch('hello.txt');
		$view->touch('world.txt');
		$view->touch('foo.txt');
		$view->file_put_contents('hello.txt', 'a test string for hello');
		$view->file_put_contents('hello.txt', 'Lets modify again2');
		$view->file_put_contents('hello.txt', 'Lets modify again3');
		$view->file_put_contents('world.txt', 'a test string for world');
		$view->file_put_contents('world.txt', 'a test string for world');
		$view->file_put_contents('world.txt', 'a test string for world');
		$view->file_put_contents('world.txt', 'a test string for world');
		$view->file_put_contents('foo.txt', 'a foo test');

		$fileInfo1 = $view->getFileInfo('hello.txt');

		$storage1 = $fileInfo1->getStorage();
		$cache1 = $storage1->getCache();
		$fileCache1 = $cache1->get($fileInfo1->getId());

		//Now change the encrypted version to fifteen
		$cacheInfo = ['encryptedVersion' => 5, 'encrypted' => 5];
		$cache1->put($fileCache1->getPath(), $cacheInfo);

		$fileInfo2 = $view->getFileInfo('world.txt');
		$storage2 = $fileInfo2->getStorage();
		$cache2 = $storage2->getCache();
		$filecache2 = $cache2->get($fileInfo2->getId());

		//Now change the encrypted version to 1
		$cacheInfo = ['encryptedVersion' => 6, 'encrypted' => 6];
		$cache2->put($filecache2->getPath(), $cacheInfo);

		$this->commandTester->execute([
			'user' => $this->userId
		]);

		$output = $this->commandTester->getDisplay();

		$this->assertStringContainsString("Verifying the content of file \"/$this->userId/files/foo.txt\"
The file \"/$this->userId/files/foo.txt\" is: OK", $output);
		$this->assertStringContainsString("Verifying the content of file \"/$this->userId/files/hello.txt\"
Attempting to fix the path: \"/$this->userId/files/hello.txt\"
Decrement the encrypted version to 4
Decrement the encrypted version to 3
The file \"/$this->userId/files/hello.txt\" is: OK
Fixed the file: \"/$this->userId/files/hello.txt\" with version 3", $output);
		$this->assertStringContainsString("Verifying the content of file \"/$this->userId/files/world.txt\"
Attempting to fix the path: \"/$this->userId/files/world.txt\"
Decrement the encrypted version to 5
Decrement the encrypted version to 4
The file \"/$this->userId/files/world.txt\" is: OK
Fixed the file: \"/$this->userId/files/world.txt\" with version 4", $output);
	}

	public function testVersionIsRestoredToOriginalIfNoFixIsFound() {
		$view = new View("/" . $this->userId . "/files");

		$view->touch('bar.txt');
		for ($i = 0; $i < 40; $i++) {
			$view->file_put_contents('bar.txt', 'a test string for hello ' . $i);
		}

		$fileInfo = $view->getFileInfo('bar.txt');

		$storage = $fileInfo->getStorage();
		$cache = $storage->getCache();
		$fileCache = $cache->get($fileInfo->getId());

		$cacheInfo = ['encryptedVersion' => 15, 'encrypted' => 15];
		$cache->put($fileCache->getPath(), $cacheInfo);

		$this->commandTester->execute([
			'user' => $this->userId
		]);

		$cacheInfo = $cache->get($fileInfo->getId());
		$encryptedVersion = $cacheInfo["encryptedVersion"];

		$this->assertEquals(15, $encryptedVersion);
	}

	/**
	 * Test commands with a file path
	 */
	public function testExecuteWithFilePathOption() {
		$view = new View("/" . $this->userId . "/files");

		$view->touch('hello.txt');
		$view->touch('world.txt');

		$this->commandTester->execute([
			'user' => $this->userId,
			'--path' => "/hello.txt"
		]);

		$output = $this->commandTester->getDisplay();

		$this->assertStringContainsString("Verifying the content of file \"/$this->userId/files/hello.txt\"
The file \"/$this->userId/files/hello.txt\" is: OK", $output);
		$this->assertStringNotContainsString('world.txt', $output);
	}

	/**
	 * Test commands with a directory path
	 */
	public function testExecuteWithDirectoryPathOption() {
		$view = new View("/" . $this->userId . "/files");

		$view->mkdir('sub');
		$view->touch('sub/hello.txt');
		$view->touch('world.txt');

		$this->commandTester->execute([
			'user' => $this->userId,
			'--path' => "/sub"
		]);

		$output = $this->commandTester->getDisplay();

		$this->assertStringContainsString("Verifying the content of file \"/$this->userId/files/sub/hello.txt\"
The file \"/$this->userId/files/sub/hello.txt\" is: OK", $output);
		$this->assertStringNotContainsString('world.txt', $output);
	}

	/**
	 * Test commands with a directory path
	 */
	public function testExecuteWithNoUser() {
		$this->commandTester->execute([
			'user' => null,
			'--path' => "/"
		]);

		$output = $this->commandTester->getDisplay();

		$this->assertStringContainsString('does not exist', $output);
	}

	/**
	 * Test commands with a directory path
	 */
	public function testExecuteWithNonExistentPath() {
		$this->commandTester->execute([
			'user' => $this->userId,
			'--path' => '/non-exist'
		]);

		$output = $this->commandTester->getDisplay();

		$this->assertStringContainsString('Please provide a valid path.', $output);
	}
}
