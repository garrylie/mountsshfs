<?php

	$config_file = __DIR__ . '/data.json';
	$pids = [];
	$active_mount_points = [];
	define('VERSION', '2.1007.2');
	
	function load_config()
	{
		
		global $config_file;

		if (!file_exists($config_file)) {
			file_put_contents($config_file, '{}');
		}

		$config = file_get_contents($config_file);
		if (!$config) fatal_error('Failed to read file', $config_file);

		$array = json_decode($config, true);
		return array_values($array);

	}

	function save_config()
	{

		global $db;
		global $config_file;

		file_put_contents($config_file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

	}

	function readln($prompt = null, $reset = false)
	{
		if ($prompt) echo $prompt;
		$fp = fopen("php://stdin","r");
		$line = rtrim(fgets($fp, 1024));
		if ($reset) reset_line(1);
		return $line;
	}

	function cc($format, $text = '')
	{
		if (!empty($GLOBALS['cron'])) return $text;
		if (is_numeric($format)) return "\e[38;5;".$format.'m'.$text."\e[0m";
		if (!is_array($format)) $format = explode(' ', $format);
		$codes = ['bold' => 1,'italic' => 3,'underline' => 4,'strikethrough' => 9,'default' => 39,'black' => 30, 'red' => 31, 'green' => 32, 'yellow' => 33,'blue' => 34, 'magenta' => 35, 'cyan' => 36, 'white' => 37,'darkgrey' => 90, 'lightred' => 91, 'lightgreen' => 92, 'lightyellow' => 93, 'lightmagenta' => 95, 'lightcyan' => 96, 'white' => 97,'blackbg' => 40, 'redbg' => 41, 'greenbg' => 42, 'yellowbg' => 44,'bluebg' => 44, 'magentabg' => 45, 'cyanbg' => 46, 'lightgreybg' => 47];
		$formatMap = array_map(function ($code) use ($codes) {
			if (is_numeric($code)) return '38;5;' . $code;
			if (preg_match('/^bg:(\d+)$/', $code, $m)) return '48;5;' . $m[1];
			if (!array_key_exists($code, $codes)) die("\e[38;5;196m" . "cc: Invalid code: [" . $code . "]\e[0m\n");
			return $codes[$code];
		}, $format);
		return "\e[".implode(';',$formatMap).'m'.$text."\e[0m";
	}

	function fatal_error($message, $info = '')
	{
		printf("%s", cc(196, $message));
		if ($info) printf("%s %s\n", cc(196, ':'), $info); else echo "\n";
		exit();
	}

	function reset_line($n = 0)
	{
		$cols = exec('tput cols');
		if ($n) echo chr(27) . '[0G' . chr(27) . '[' . $n . 'A';
		printf("%s\r", str_repeat(' ', $cols));
	}

	function warning($message, $info = '')
	{
		reset_line();
		printf("%s", cc(204, $message));
		if ($info) printf("%s %s\n", cc(204, ':'), $info); else echo "\n";
	}

	function print_list()
	{
		global $db, $commands, $active_mount_points;

		if (!empty($db)) {
			$i = 0;
			foreach ($db as $arr) {
				$mount_point = '/mnt/' . $arr['mount_point'];
				$is_mounted = exec('timeout -s 20 -k 1 .1 mountpoint ' . $mount_point);
				$cc = (strpos($arr['mount_point'], 'ampseo') === 0) ? '232 bg:220' : '232 bg:82';
				if (empty($is_mounted)) $cc = 210;
				$color = in_array($mount_point . '/', $active_mount_points) ? $cc : 245;
				printf(" % 2d. %s\n", ++$i, cc($color, $arr['mount_point']));
			}
			printf("%s\n", str_repeat('-', 22));
		}

		printf("%s: ", cc('bold', 'COMMANDS'));
		foreach ($commands as $key => $cmd) {
			$s = ($key) ? ' | ' : '';
			printf("%s%s", cc(220, $s), cc('underline 225', $cmd));
		}

		printf("\n\n%s\n\n", cc('248 italic', 'Type command or number(s) of entries to mount:'));

	}

	function pgrep_list()
	{
		global $commands, $pids, $active_mount_points;
		$pids = [];
		$active_mount_points = [];
		exec('pgrep -a sshfs', $pgrep_output);
		if (!empty($pgrep_output)) {

			/* Формируем CLI таблицу с псевдо-графикой */
			$cli_table = [];

			foreach ($pgrep_output as $pgrep) {
				# 1642242 sshfs root@uc:/var/ -p 22 -o IdentityFile=/home/garry/.ssh/ultimatecup-ssh-key.pub /mnt/ultimatecup/
				if (preg_match('/^(?<pid>\d+)\ssshfs\s(?<username>[^@]+)@(?<host>[^:]+):(?<path>\S+) -p 22 -o IdentityFile=(?<pub>\S+)\s(?<mount>\/.+)$/', $pgrep, $m)) {
					$pids[] = $m['pid'];
					$active_mount_points[(int)$m['pid']] = $m['mount'];

					$cli_table[] = [
						[$m['pid'], 197],
						[$m['username'] . '@' . $m['host'], 214],
						[$m['mount'], 220],
						[$m['path'], 38],
					];

				} else die("Invalid preg: {$pgrep}\n");
			}
			print(PHP_EOL);
			cli_table($cli_table, ['PID', 'CONNECTION', 'MOUNT POINT', 'REMOTE DIR']);
			print(PHP_EOL);

		} else printf("%s\n", cc('italic', 'No sshfs process found'));
	}

	function clear_line()
	{
		$cols = intval(`tput cols`);
		printf("\r%s\r", str_repeat(' ', $cols));
	}

	function print_version()
	{
		printf("Version: %s\n", cc('bold 226', VERSION));
	}

	function cli_table($table, $header = null, $options = [])
	{
		$rows = 0;
		$max = [];

		/* options */
		$gap = '   ';

		/* Array of arrays
			[0] => text,
			[1] => cc
			[2] => 'left'/'right' position. Default is left
			[3] => reserved for mb_strlen
		*/

		/* header */
		if (!empty($header)) {
			$rows = count($header);
			foreach ($header as $col => $title) {
				if (empty($max[$col])) $max[$col] = 0;
				if (mb_strlen($title) > $max[$col]) $max[$col] = mb_strlen($title);
			}
		}

		/* first pass */
		foreach ($table as $key => $row) {
			if (count($row) > $rows) $rows = count($row);

			foreach ($row as $col => $cell) {
				if (empty($max[$col])) $max[$col] = 0;
				$strlen = mb_strlen($cell[0]);
				if ($strlen > $max[$col]) $max[$col] = $strlen;
				$table[$key][$col][3] = $strlen;
			}
		}

		$table_width = array_sum($max);

		/* seconds pass */
		if (!empty($header)) {
			$rows = count($header);
			echo ' ';
			foreach ($header as $col => $title) {
				$space = str_repeat(' ', $max[$col] - mb_strlen($title));
				print(cc('bold', $title) . $space) . $gap;
			}
			print(PHP_EOL);
			foreach ($header as $col => $title) {
				print(str_repeat('-', $max[$col])) . str_repeat('-', strlen($gap));
			}
			print(PHP_EOL);
		}

		foreach ($table as $key => $row) {
			echo ' ';
			foreach ($row as $col => $cell) {

				$space = str_repeat(' ', $max[$col] - $cell[3]);
				$text = $cell[0];
				if (!empty($cell[1]) && !empty($cell[0])) $text = cc($cell[1], $text);

				if (!empty($cell[2]) && $cell[2] == 'right') {
					print($space . $text);
				} else { /* left */
					print($text . $space);
				}
				print($gap);
			}
			print(PHP_EOL);
		}

		foreach ($header as $col => $title) {
			print(str_repeat('-', $max[$col])) . str_repeat('-', strlen($gap));
		}

	}



	$db = load_config();

	$commands = ['add', 'edit', 'delete', 'kill', 'kill all', 'list', 'version', 'exit'];

	pgrep_list();
	print_list();
	while (true) {
		$commands = array_values($commands);
		input:
		$input = readln('mountsshfs> ');
		// echo chr(27) . '[0G' . chr(27) . '[1A';
		// clear_line();
		if ($input === '') goto input;

		if (preg_match('/^[0-9 ]+$/', $input)) {

			$indexes = [];
			if (is_numeric($input)) {
				$input = intval($input);
				if ($input < 1 || $input > count($db)) {
					warning('Invalid input', $input);
					goto input;
				}
				$index = $input - 1;
				$indexes[] = $index;
			} else {
				preg_match_all('/\d+/', $input, $match);
				var_dump($match);
				foreach ($match[0] as $index)
					$indexes[] = intval($index) - 1;
			}

			foreach ($indexes as $index) {

				if (!array_key_exists($index, $db)) {
					warning('Invalid index', $index + 1);
				}

				$mount_dir = '/mnt/' . $db[$index]['mount_point'];
				if (!file_exists($mount_dir)) {
					printf("Directory %s not exists, creating\n", cc(220, $mount_dir));
					exec(sprintf('sudo mkdir -p "%1$s" && sudo chown garry:garry "%1$s"',
						$mount_dir));
				}

				$cmd = sprintf('sshfs %s@%s:%s -p %d -o IdentityFile=~/.ssh/%s,reconnect,ServerAliveInterval=60,ServerAliveCountMax=30 /mnt/%s/ 2>&1 &',
					$db[$index]['username'],
					$db[$index]['host'],
					$db[$index]['path'],
					$db[$index]['port'],
					$db[$index]['rsa'],
					$db[$index]['mount_point']
				);
				$mount_point = '/mnt/' . $db[$index]['mount_point'];
				$retries = 0;
				sshfs_retry:
				printf("Running command:\n%s\n", cc([38, 'bold'], $cmd));
				unset($cmd_output);
				exec($cmd, $cmd_output);
				if (!empty($cmd_output)) {
					printf("%s\n", cc(210, implode("\n", $cmd_output)));
					if (preg_match('/(Permission denied|Transport endpoint is not connected)/', $cmd_output[0])) {
						printf("Unmounting %s\n", cc(220, $mount_point));
						unset($umount_output);
						exec('sudo umount -l ' . $mount_point . ' 2>&1', $umount_output);
						if (!empty($umount_output))
							printf("%s\n", cc(210, implode("\n", $umount_output)));
						$retries++;
						if ($retries === 3) {
							printf("%s\n", cc(210, 'Exceeded amount of retries (3)'));
						} else goto sshfs_retry;
					}
				} else {
					printf("%s: %s\n", cc(82, 'Successfully mounted'), cc(220, $mount_point));
					pgrep_list();
					print_list();
				}
			}
				
		} else {
			$command = trim($input);
			$command_index = null;
			if (preg_match('/^(.+) (\d+)$/', $command, $m)) {
				$command       = $m[1];
				$command_index = $m[2];
			}
			switch ($command) {
				case 'add':
					exec("find /home/garry/.ssh -type f -name '*.pub'", $find_output);
					$find_output = array_map(function ($x) { return preg_replace('~^.+/~', '', $x); }, $find_output);
					clear_line();

					$username = readln('ssh username: ', true);
					$host     = readln('ssh host: ', true);
					$path     = readln('ssh remote path: ', true);
					$port     = 22;
					$id_rsa   = null;

					foreach ($find_output as $key => $pub) {
						$color = 13;
						if ($pub == 'id_rsa.pub') {
							$find_output[$key] = 'id_rsa';
							$id_rsa = $key;
							$color = 82;
						}
						printf("  %d. %s\n", $key, cc($color, $pub));
					}

					$pub_index   = readln("\npublic key (index): ", true);
					if (! $pub_index) $pub_index = $id_rsa;
					$mount_point = readln('mount point: ', true);

					$mount_dir = '/mnt/' . preg_replace('~^/mnt/~', '', $mount_point);
					if (!file_exists($mount_dir)) {
						printf("Directory %s not exists, creating\n", cc(220, $mount_dir));
						exec(sprintf('sudo mkdir -p "%1$s" && sudo chown garry:garry "%1$s"',
							$mount_dir));
					}

					$rsa = $find_output[$pub_index];

					print("New entry saved!\n");
					printf("   username: %s\n", cc(228, $username));
					printf("       host: %s\n", cc(228, $host));
					printf("       path: %s\n", cc(228, $path));
					printf("       port: %s\n", cc(228, $port));
					printf("  pub_index: %s\n", cc(228, $rsa));
					printf("mount_point: %s\n\n", cc(228, $mount_dir));

					$db[] = [
						'username'    => $username,
						'host'        => $host,
						'path'        => $path,
						'port'        => $port,
						'rsa'         => $rsa,
						'mount_point' => $mount_point,
					];
					save_config();
					print_list();
					break;

				case 'delete':
					$input = readln(sprintf('%s index (1-%d): ', cc('bold underline 225', 'delete'), count($db)));
					$input = intval($input);
					if ($input < 1 || $input > count($db)) {
						warning('Invalid index', $input);
						goto input;
					}
					unset($db[$input-1]);
					save_config();
					print_list();
					break;

				case 'edit':
					if ($command_index) {
						printf("\n%s index %d\n", cc('bold underline 225', 'edit'), $command_index);
						$input = $command_index;
					} else {
						$input = readln(sprintf('%s index (1-%d): ', cc('bold underline 225', 'edit'), count($db)));
						$input = intval($input);
					}
					if ($input < 1 || $input > count($db)) {
						warning('Invalid index', $input);
						goto input;
					}
					$index = $input - 1;

					printf("%s\nCurrent data:\n", cc(208, $db[$index]['mount_point']));

					printf("     ssh username: %s\n", cc(81, $db[$index]['username']));
					printf("         ssh host: %s\n", cc(81, $db[$index]['host']));
					printf("  ssh remote path: %s\n", cc(81, $db[$index]['path']));
					printf("       public key: %s\n", cc(81, $db[$index]['rsa']));
					printf("      mount point: %s\n\n", cc(81, $db[$index]['mount_point']));
					
					printf("%s\n\n", cc('248 italic', "Keep blank to remain unchanged"));

					edit:

					$username    = readln('ssh username: ');
					$host        = readln('ssh host: ');
					$path        = readln('ssh remote path: ');
					$rsa         = readln('public key: ');
					$mount_point = readln('mount point: ');

					$mount_dir = '/mnt/' . preg_replace('~^/mnt/~', '', $mount_point);
					if (!file_exists($mount_dir)) {
						printf("Directory %s not exists, creating\n", cc(220, $mount_dir));
						exec(sprintf('sudo mkdir -p "%1$s" && sudo chown garry:garry "%1$s"',
							$mount_dir));
					}

					if ($rsa && !file_exists('~/.ssh/' . $rsa)) {
						printf("%s %s\n", cc(210, 'File not found:'), $rsa);
						goto edit;
					}

					print("Successful edit!\n");

					$keys = ['username', 'host', 'path', 'rsa', 'mount_point'];

					foreach ($keys as $key) {
						$color = 245;
						if (!empty(${$key})) {
							if (${$key} != $db[$index][$key]) {
								$color = '228';
								$db[$index][$key] = ${$key};
							}
						}
						printf("%15s: %s\n", $key, cc($color, $db[$index][$key]));
					}

					printf("\n%s\n\n", cc('248 italic', "Saving config..."));
					sleep(1);

					save_config();
					print_list();
					break;

				case 'kill':
					if ($command_index) {
						$index = $command_index - 1;
						if (!array_key_exists($index, $db)) {
							warning('Invalid index', $command_index);
							goto input;
						}
						$mount_point = '/mnt/' . rtrim($db[$index]['mount_point'], '/') . '/';
						$pid = intval(array_search($mount_point, $active_mount_points));
						printf("\n%s %s (%s)\n", cc('bold underline 225', 'kill:'), cc(220, $db[$index]['mount_point']), cc(210, $pid));
					} else {
						printf("%s Choose a point to unmount:\n\n", cc('bold underline 225', 'kill:'));
						foreach ($pids as $key => $pid)
							printf("  %d. %s (%s)\n", $key, cc(220, $active_mount_points[$pid]), cc(210, $pid));
						$pid_index = readln("\npid index: ", true);
						$pid = $pids[$pid_index];
					}
					if ($pid) {
						$cmd = "sudo kill -9 $pid";
						printf("Running command: %s\n", cc([197, 'bold'], $cmd));
						exec($cmd);
					}
					$cmd = "sudo umount -l {$active_mount_points[$pid]}";
					printf("Running command: %s\n", cc([197, 'bold'], $cmd));
					exec($cmd);
					pgrep_list();
					print_list();
					break;

				case 'kill all':
					if (empty($pids)) {
						warning('no PIDs available');
					} else {
						$cmd = sprintf('sudo kill -9 %s', implode(' ', $pids));
						printf("Running command: %s\n", cc([197, 'bold'], $cmd));
						exec($cmd);
						$cmd = "sudo umount -l /mnt/* /mnt/ampseo/*";
						printf("Running command: %s\n", cc([197, 'bold'], $cmd));
						exec($cmd);
						pgrep_list();
						print_list();
					}
					break;

				case 'list': pgrep_list(); print_list(); break;
				case 'version': print_version(); break;
				case 'exit': exit();
				
				default:
					warning('Unregistered command', $command);
					goto input;
					break;
			}
		}
	}

	
?>