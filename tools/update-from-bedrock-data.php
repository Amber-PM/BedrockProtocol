<?php

/*
 * This file is part of BedrockProtocol.
 * Copyright (C) 2014-2022 PocketMine Team <https://github.com/pmmp/BedrockProtocol>
 *
 * BedrockProtocol is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

if(count($argv) < 2){
	fwrite(STDERR, "Required args: path to BedrockData" . PHP_EOL);
	exit(1);
}

foreach([
	'generate-protocol-info.php' => 'protocol_info.json',
	'generate-entity-ids.php' => 'entity_id_map.json',
	'generate-level-sound-ids.php' => 'level_sound_id_map.json',
	'generate-command-parameter-types.php' => 'command_arg_types.json',
] as $generator => $input){
	passthru(escapeshellarg(PHP_BINARY) . " " . escapeshellarg(__DIR__ . '/' . $generator) . " " . escapeshellarg($argv[1] . '/' . $input), $status);
	if($status !== 0){
		exit($status);
	}
}
