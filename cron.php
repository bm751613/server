<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Artem Sidorenko <artem@posteo.de>
 * @author Bernhard Posselt <dev@bernhard-posselt.com>
 * @author Christopher Schäpers <kondou@ts.unde.re>
 * @author Damjan Georgievski <gdamjan@gmail.com>
 * @author Jakob Sack <mail@jakobsack.de>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Ko- <k.stoffelen@cs.ru.nl>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Oliver Kohl D.Sc. <oliver@kohl.bz>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Steffen Lindner <mail@steffen-lindner.de>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
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

require_once __DIR__ . '/lib/versioncheck.php';

try {

	require_once __DIR__ . '/lib/base.php';

	if (\OCP\Util::needUpgrade()) {
		\OCP\Util::writeLog('cron', 'Update required, skipping cron', \OCP\Util::DEBUG);
		exit;
	}
	if (\OC::$server->getSystemConfig()->getValue('maintenance', false)) {
		\OCP\Util::writeLog('cron', 'We are in maintenance mode, skipping cron', \OCP\Util::DEBUG);
		exit;
	}

	// load all apps to get all api routes properly setup
	OC_App::loadApps();

	\OC::$server->getSession()->close();

	// initialize a dummy memory session
	$session = new \OC\Session\Memory('');
	$cryptoWrapper = \OC::$server->getSessionCryptoWrapper();
	$session = $cryptoWrapper->wrapSession($session);
	\OC::$server->setSession($session);

	$logger = \OC::$server->getLogger();
	$config = \OC::$server->getConfig();

	// Don't do anything if Nextcloud has not been installed
	if (!$config->getSystemValue('installed', false)) {
		exit(0);
	}

	\OC::$server->getTempManager()->cleanOld();

	// Exit if background jobs are disabled!
	$appMode = $config->getAppValue('core', 'backgroundjobs_mode', 'ajax');
	if ($appMode === 'none') {
		if (OC::$CLI) {
			echo 'Background Jobs are disabled!' . PHP_EOL;
		} else {
			OC_JSON::error(array('data' => array('message' => 'Background jobs disabled!')));
		}
		exit(1);
	}

	if (OC::$CLI) {
		// set to run indefinitely if needed
		if (strpos(@ini_get('disable_functions'), 'set_time_limit') === false) {
			@set_time_limit(0);
		}

		// the cron job must be executed with the right user
		if (!function_exists('posix_getuid')) {
			echo "The posix extensions are required - see http://php.net/manual/en/book.posix.php" . PHP_EOL;
			exit(1);
		}
		$user = posix_getpwuid(posix_getuid());
		$configUser = posix_getpwuid(fileowner(OC::$configDir . 'config.php'));
		if ($user['name'] !== $configUser['name']) {
			echo "Console has to be executed with the same user as the web server is operated" . PHP_EOL;
			echo "Current user: " . $user['name'] . PHP_EOL;
			echo "Web server user: " . $configUser['name'] . PHP_EOL;
			exit(1);
		}

		// We call Nextcloud from the CLI (aka cron)
		if ($appMode !== 'cron') {
			$config->setAppValue('core', 'backgroundjobs_mode', 'cron');
		}

		// Work
		$jobList = \OC::$server->getJobList();

		// We only ask for jobs for 14 minutes, because after 15 minutes the next
		// system cron task should spawn.
		$endTime = time() + 14 * 60;

		$executedJobs = [];
		while ($job = $jobList->getNext()) {
			if (isset($executedJobs[$job->getId()])) {
				$jobList->unlockJob($job);
				break;
			}

			$job->execute($jobList, $logger);
			// clean up after unclean jobs
			\OC_Util::tearDownFS();

			$jobList->setLastJob($job);
			$executedJobs[$job->getId()] = true;
			unset($job);

			if (time() > $endTime) {
				break;
			}
		}

	} else {
		// We call cron.php from some website
		if ($appMode == 'cron') {
			// Cron is cron :-P
			OC_JSON::error(array('data' => array('message' => 'Backgroundjobs are using system cron!')));
		} else {
			// Work and success :-)
			$jobList = \OC::$server->getJobList();
			$job = $jobList->getNext();
			if ($job != null) {
				$job->execute($jobList, $logger);
				$jobList->setLastJob($job);
			}
			OC_JSON::success();
		}
	}

	// Log the successful cron execution
	\OC::$server->getConfig()->setAppValue('core', 'lastcron', time());
	exit();

} catch (Exception $ex) {
	\OC::$server->getLogger()->logException($ex, ['app' => 'cron']);
} catch (Error $ex) {
	\OC::$server->getLogger()->logException($ex, ['app' => 'cron']);
}
