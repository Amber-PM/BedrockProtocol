<?php

/*
 * This file is part of BedrockProtocol.
 * Copyright (C) 2014-2022 PocketMine Team <https://github.com/Amber-PM/BedrockProtocol>
 *
 * BedrockProtocol is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function basename;
use function bin2hex;
use function chmod;
use function clearstatcache;
use function copy;
use function dirname;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function glob;
use function hash_file;
use function is_file;
use function json_decode;
use function json_encode;
use function mkdir;
use function proc_close;
use function proc_open;
use function random_bytes;
use function rmdir;
use function str_replace;
use function stream_get_contents;
use function strtolower;
use function sys_get_temp_dir;
use function trim;
use function umask;
use function unlink;
use const PHP_OS_FAMILY;

final class ProtocolInfoGeneratorTest extends TestCase{
	private string $directory;

	protected function setUp() : void{
		$this->directory = sys_get_temp_dir() . '/amber-protocol-info-' . bin2hex(random_bytes(8));
		mkdir($this->directory . '/tools', 0777, true);
		mkdir($this->directory . '/src');
		foreach(self::files(dirname(__DIR__, 2) . '/tools/*') as $file){
			if(is_file($file)){
				copy($file, $this->directory . '/tools/' . basename($file));
			}
		}
		foreach(self::files(dirname(__DIR__, 2) . '/src/*.php') as $file){
			copy($file, $this->directory . '/src/' . basename($file));
		}
	}

	protected function tearDown() : void{
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
		foreach($files as $file){
			if(!$file instanceof \SplFileInfo){
				throw new \RuntimeException('Unexpected directory entry');
			}
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($this->directory);
	}

	/** @return list<string> */
	private static function files(string $pattern) : array{
		$files = glob($pattern);
		if($files === false){
			throw new \RuntimeException("Cannot glob $pattern");
		}
		return $files;
	}

	private static function readFile(string $path) : string{
		$contents = file_get_contents($path);
		if($contents === false){
			throw new \RuntimeException("Cannot read $path");
		}
		return $contents;
	}

	/** @return array<string, string> */
	private function snapshot() : array{
		$result = [];
		foreach(self::files($this->directory . '/src/*') as $file){
			$hash = hash_file('sha256', $file);
			self::assertIsString($hash);
			$result[basename($file)] = $hash;
		}
		return $result;
	}

	/** @return array{int, string} */
	private function runGenerator(string $input, string $script = 'generate-protocol-info.php') : array{
		file_put_contents($this->directory . '/protocol_info.json', $input);
		$command = [PHP_BINARY, '-n', $this->directory . '/tools/' . $script, $script === 'update-from-bedrock-data.php' ? $this->directory : $this->directory . '/protocol_info.json'];
		$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
		self::assertIsResource($process);
		$output = stream_get_contents($pipes[1]);
		self::assertIsString($output);
		fclose($pipes[1]);
		return [proc_close($process), $output];
	}

	/** @return array{version: array<string, int|bool>, packets: array<string, int>} */
	private static function validInput() : array{
		/** @var array{version: array<string, int|bool>, packets: array<string, int>} $data */
		$data = json_decode(self::readFile(__DIR__ . '/fixtures/protocol-info.json'), true, flags: JSON_THROW_ON_ERROR);
		return $data;
	}

	public function testValidGenerationPreservesAllDefinitionsAndIsDeterministic() : void{
		$original = file_get_contents($this->directory . '/src/ProtocolInfo.php');
		[$status, $output] = $this->runGenerator(json_encode(self::validInput(), JSON_THROW_ON_ERROR));
		self::assertSame(0, $status, $output);
		self::assertSame($original, file_get_contents($this->directory . '/src/ProtocolInfo.php'));
		$first = $this->snapshot();
		[$status, $output] = $this->runGenerator(json_encode(self::validInput(), JSON_THROW_ON_ERROR));
		self::assertSame(0, $status, $output);
		self::assertSame($first, $this->snapshot());
	}

	public function testGenerationDoesNotDependOnDestinationDefinitions() : void{
		$original = file_get_contents($this->directory . '/src/ProtocolInfo.php');
		unlink($this->directory . '/src/ProtocolInfo.php');
		[$status, $output] = $this->runGenerator(json_encode(self::validInput(), JSON_THROW_ON_ERROR));
		self::assertSame(0, $status, $output);
		self::assertSame($original, file_get_contents($this->directory . '/src/ProtocolInfo.php'));
	}

	/** @return iterable<string, array{string}> */
	public static function invalidInputs() : iterable{
		$valid = self::validInput();
		yield 'malformed JSON' => ['{'];
		yield 'missing version' => ['{"packets":{}}'];
		foreach(['protocol_version' => 1001, 'patch' => 30, 'major' => -1, 'beta' => true] as $key => $value){
			$input = $valid;
			$input['version'][$key] = $value;
			yield 'version ' . $key => [json_encode($input, JSON_THROW_ON_ERROR)];
		}
		foreach(['empty', 'missing', 'changed', 'duplicate ID', 'namespaced', 'invalid name', 'invalid packet suffix', 'new packet'] as $case){
			$input = $valid;
			switch($case){
				case 'empty': $input['packets'] = []; break;
				case 'missing': unset($input['packets']['LoginPacket']); break;
				case 'changed': $input['packets']['LoginPacket'] = 511; break;
				case 'duplicate ID': $input['packets']['PlayStatusPacket'] = 1; break;
				case 'namespaced': $input['packets']['Other::LoginPacket'] = 1; break;
				case 'invalid name': $input['packets']['Bad/Packet'] = 3; break;
				case 'invalid packet suffix': unset($input['packets']['RecordStartedPacket']); $input['packets']['RecordStartedPACKET'] = 352; break;
				case 'new packet': $input['packets']['NewPacket'] = 510; break;
			}
			yield $case => [json_encode($input, JSON_THROW_ON_ERROR)];
		}
	}

	/** @dataProvider invalidInputs */
	#[DataProvider('invalidInputs')]
	public function testInvalidInputDoesNotModifyAnyOutput(string $input) : void{
		$before = $this->snapshot();
		[$status, $output] = $this->runGenerator($input);
		self::assertNotSame(0, $status, $output);
		self::assertNotSame('', trim($output));
		self::assertSame($before, $this->snapshot());
	}

	public function testStaleBedrockDataIsRejected() : void{
		$before = $this->snapshot();
		$input = self::validInput();
		$input['version'] = ['major' => 1, 'minor' => 26, 'patch' => 30, 'revision' => 31, 'beta' => false, 'protocol_version' => 1001];
		[$status, $output] = $this->runGenerator(json_encode($input, JSON_THROW_ON_ERROR));
		self::assertNotSame(0, $status, $output);
		self::assertStringContainsString('version', strtolower($output));
		self::assertSame($before, $this->snapshot());
	}

	public function testExplicitTemplateVersionUpdateCanBeRegenerated() : void{
		$path = $this->directory . '/tools/ProtocolInfo.php.template';
		$source = str_replace(['CURRENT_PROTOCOL = self::PROTOCOL_1_26_44', "'v26.44'", "'1.26.44'"], ['CURRENT_PROTOCOL = self::PROTOCOL_1_26_50', "'v26.50'", "'1.26.50'"], self::readFile($path));
		file_put_contents($path, $source);
		$input = self::validInput();
		$input['version']['protocol_version'] = 2192;
		$input['version']['patch'] = 50;
		[$status, $output] = $this->runGenerator(json_encode($input, JSON_THROW_ON_ERROR));
		self::assertSame(0, $status, $output);
		self::assertSame($source, self::readFile($this->directory . '/src/ProtocolInfo.php'));
	}

	public function testPacketChangesRequireExplicitCanonicalUpdate() : void{
		$path = $this->directory . '/tools/ProtocolInfo.php.template';
		$source = str_replace('public const RECORD_STARTED_PACKET = 0x160;', "public const RECORD_STARTED_PACKET = 0x160;\n\tpublic const EXAMPLE_PACKET = 0x161;", self::readFile($path));
		file_put_contents($path, $source);
		$input = self::validInput();
		$input['packets']['ExamplePacket'] = 353;
		[$status, $output] = $this->runGenerator(json_encode($input, JSON_THROW_ON_ERROR));
		self::assertSame(0, $status, $output);
		self::assertSame($source, self::readFile($this->directory . '/src/ProtocolInfo.php'));
		self::assertFileExists($this->directory . '/src/ExamplePacket.php');
	}

	public function testMissingTemplateDoesNotModifyOutputs() : void{
		unlink($this->directory . '/tools/ProtocolInfo.php.template');
		$before = $this->snapshot();
		[$status, $output] = $this->runGenerator(json_encode(self::validInput(), JSON_THROW_ON_ERROR));
		self::assertNotSame(0, $status, $output);
		self::assertSame($before, $this->snapshot());
	}

	public function testMalformedTemplateDoesNotModifyOutputs() : void{
		file_put_contents($this->directory . '/tools/ProtocolInfo.php.template', '<?php syntax error');
		$before = $this->snapshot();
		[$status, $output] = $this->runGenerator(json_encode(self::validInput(), JSON_THROW_ON_ERROR));
		self::assertNotSame(0, $status, $output);
		self::assertSame($before, $this->snapshot());
	}

	public function testOutputFailureDoesNotLeavePartialFiles() : void{
		unlink($this->directory . '/src/ProtocolInfo.php');
		mkdir($this->directory . '/src/ProtocolInfo.php');
		file_put_contents($this->directory . '/src/ProtocolInfo.php/sentinel', 'unchanged');
		[$status, $output] = $this->runGenerator(json_encode(self::validInput(), JSON_THROW_ON_ERROR));
		self::assertNotSame(0, $status, $output);
		self::assertSame('unchanged', file_get_contents($this->directory . '/src/ProtocolInfo.php/sentinel'));
		self::assertSame([], glob($this->directory . '/src/.protocol-info-*'));
	}

	public function testWrapperStopsBeforeOtherGeneratorsOnFailure() : void{
		$before = $this->snapshot();
		file_put_contents($this->directory . '/tools/generate-entity-ids.php', '<?php file_put_contents(__DIR__ . "/called", "yes");');
		[$status, $output] = $this->runGenerator('{', 'update-from-bedrock-data.php');
		self::assertNotSame(0, $status, $output);
		self::assertFileDoesNotExist($this->directory . '/tools/called');
		self::assertSame($before, $this->snapshot());
	}

	public function testGenerationPreservesExistingPermissionsAndContent() : void{
		$protocolInfoPath = $this->directory . '/src/ProtocolInfo.php';
		if(PHP_OS_FAMILY !== 'Windows'){
			chmod($protocolInfoPath, 0640);
		}
		clearstatcache(true, $protocolInfoPath);
		$expectedPerms = fileperms($protocolInfoPath) & 0777;
		$originalContent = self::readFile($protocolInfoPath);

		[$status, $output] = $this->runGenerator(json_encode(self::validInput(), JSON_THROW_ON_ERROR));
		self::assertSame(0, $status, $output);

		clearstatcache(true, $protocolInfoPath);
		self::assertSame($expectedPerms, fileperms($protocolInfoPath) & 0777);
		if(PHP_OS_FAMILY !== 'Windows'){
			self::assertSame(0640, fileperms($protocolInfoPath) & 0777);
		}
		self::assertSame($originalContent, self::readFile($protocolInfoPath));
	}

	public function testNewPacketStubGenerationRespectsUmaskAndHasExpectedContent() : void{
		$path = $this->directory . '/tools/ProtocolInfo.php.template';
		$source = str_replace(
			'public const RECORD_STARTED_PACKET = 0x160;',
			"public const RECORD_STARTED_PACKET = 0x160;\n\tpublic const EXAMPLE_PACKET = 0x161;",
			self::readFile($path)
		);
		file_put_contents($path, $source);

		$input = self::validInput();
		$input['packets']['ExamplePacket'] = 353;

		[$status, $output] = $this->runGenerator(json_encode($input, JSON_THROW_ON_ERROR));
		self::assertSame(0, $status, $output);

		$newPacketPath = $this->directory . '/src/ExamplePacket.php';
		self::assertFileExists($newPacketPath);
		clearstatcache(true, $newPacketPath);

		if(PHP_OS_FAMILY !== 'Windows'){
			$expectedPerms = 0666 & ~umask();
			self::assertSame($expectedPerms, fileperms($newPacketPath) & 0777);
		}

		$content = self::readFile($newPacketPath);
		self::assertStringContainsString('class ExamplePacket extends DataPacket', $content);
		self::assertStringContainsString('public const NETWORK_ID = ProtocolInfo::EXAMPLE_PACKET;', $content);
	}

	public function testFailureCleansUpTemporaryFilesAndPreservesOriginalFile() : void{
		$protocolInfoPath = $this->directory . '/src/ProtocolInfo.php';
		if(PHP_OS_FAMILY !== 'Windows'){
			chmod($protocolInfoPath, 0640);
		}
		clearstatcache(true, $protocolInfoPath);
		$originalPerms = fileperms($protocolInfoPath) & 0777;
		$originalContent = self::readFile($protocolInfoPath);

		$packetPoolPath = $this->directory . '/src/PacketPool.php';
		unlink($packetPoolPath);
		mkdir($packetPoolPath);
		file_put_contents($packetPoolPath . '/sentinel', 'preserved');

		[$status, $output] = $this->runGenerator(json_encode(self::validInput(), JSON_THROW_ON_ERROR));
		self::assertNotSame(0, $status, $output);

		clearstatcache(true, $protocolInfoPath);
		self::assertSame($originalContent, self::readFile($protocolInfoPath));
		self::assertSame($originalPerms, fileperms($protocolInfoPath) & 0777);
		self::assertSame('preserved', self::readFile($packetPoolPath . '/sentinel'));
		self::assertSame([], glob($this->directory . '/src/.protocol-info-*'));
	}
}
