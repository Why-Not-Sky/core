<?php
/**
 * ownCloud
 *
 * @copyright (C) 2014 ownCloud, Inc.
 *
 * @author Bjoern Schiessle <schiessle@owncloud.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Encryption;


class Migration {

	/**
	 * @var \OC\Files\View
	 */
	private $view;
	private $moduleId;
	/** @var \OC\DB\Connection */
	private $connection;

	public function __construct() {
		$this->view = new \OC\Files\View();
		$this->view->getUpdater()->disable();
		/** @var \OC\DB\Connection $connection */
		$this->connection = \OC::$server->getDatabaseConnection();
		$this->moduleId = \OCA\Encryption\Crypto\Encryption::ID;
	}

	public function reorganizeFolderStructure() {
		$this->reorganizeSystemFolderStructure();

		$limit = 500;
		$offset = 0;
		do {
			$users = \OCP\User::getUsers('', $limit, $offset);
			foreach ($users as $user) {
				$this->reorganizeFolderStructureForUser($user);
			}
			$offset += $limit;
		} while (count($users) >= $limit);
	}

	public function reorganizeSystemFolderStructure() {

		$this->createPathForKeys('/files_encryption');

		// backup system wide folders
		$this->backupSystemWideKeys();

		// rename system wide mount point
		$this->renameFileKeys('', '/files_encryption/keys');

		// rename system private keys
		$this->renameSystemPrivateKeys();

		$storage = $this->view->getMount('')->getStorage();
		$storage->getScanner()->scan('files_encryption');
	}


	public function reorganizeFolderStructureForUser($user) {
		// backup all keys
		\OC_Util::setupFS($user);
		if ($this->backupUserKeys($user)) {
			// rename users private key
			$this->renameUsersPrivateKey($user);
			$this->renameUsersPublicKey($user);
			// rename file keys
			$path = '/files_encryption/keys';
			$this->renameFileKeys($user, $path);
			$trashPath = '/files_trashbin/keys';
			if (\OC_App::isEnabled('files_trashbin') && $this->view->is_dir($user . '/' . $trashPath)) {
				$this->renameFileKeys($user, $trashPath, true);
				$this->view->deleteAll($trashPath);
			}
			// delete old folders
			$this->deleteOldKeys($user);
			$this->view->getMount('/' . $user)->getStorage()->getScanner()->scan('files_encryption');
		}
	}

	public function updateDB() {
		$query = $this->connection->createQueryBuilder();
		$query->update('`*PREFIX*appconfig`')
			->set('`appid`', ':newappid')
			->where($query->expr()->eq('`appid`', ':oldappid'))
			->setParameter('oldappid', 'files_encryption')
			->setParameter('newappid', 'encryption');
		$query->execute();

		$query = $this->connection->createQueryBuilder();
		$query->update('`*PREFIX*preferences`')
			->set('`appid`', ':newappid')
			->where($query->expr()->eq('`appid`', ':oldappid'))
			->setParameter('oldappid', 'files_encryption')
			->setParameter('newappid', 'encryption');
		$query->execute();
	}

	private function backupSystemWideKeys() {
		$backupDir = 'encryption_migration_backup_' . date("Y-m-d_H-i-s");
		$this->view->mkdir($backupDir);
		$this->view->copy('files_encryption', $backupDir . '/files_encryption');
	}

	private function backupUserKeys($user) {
		$encryptionDir = $user . '/files_encryption';
		if ($this->view->is_dir($encryptionDir)) {
			$backupDir = $user . '/encryption_migration_backup_' . date("Y-m-d_H-i-s");
			$this->view->mkdir($backupDir);
			$this->view->copy($encryptionDir, $backupDir);
			return true;
		}
		return false;
	}

	private function renameSystemPrivateKeys() {
		$dh = $this->view->opendir('files_encryption');
		$this->createPathForKeys('/files_encryption/' . $this->moduleId );
		if (is_resource($dh)) {
			while (($privateKey = readdir($dh)) !== false) {
				if (!\OC\Files\Filesystem::isIgnoredDir($privateKey) ) {
					if (!$this->view->is_dir('/files_encryption/' . $privateKey)) {
						$this->view->rename('files_encryption/' . $privateKey, 'files_encryption/' . $this->moduleId . '/' . $privateKey);
					}
				}
			}
			closedir($dh);
		}
	}

	private function renameUsersPrivateKey($user) {
		$oldPrivateKey = $user . '/files_encryption/' . $user . '.privateKey';
		$newPrivateKey = $user . '/files_encryption/' . $this->moduleId . '/' . $user . '.privateKey';
		$this->createPathForKeys(dirname($newPrivateKey));

		$this->view->rename($oldPrivateKey, $newPrivateKey);
	}

	private function renameUsersPublicKey($user) {
		$oldPublicKey = '/files_encryption/public_keys/' . $user . '.publicKey';
		$newPublicKey = $user . '/files_encryption/' . $this->moduleId . '/' . $user . '.publicKey';
		$this->createPathForKeys(dirname($newPublicKey));

		$this->view->rename($oldPublicKey, $newPublicKey);
	}

	private function renameFileKeys($user, $path, $trash = false) {

		$dh = $this->view->opendir($user . '/' . $path);

		if (is_resource($dh)) {
			while (($file = readdir($dh)) !== false) {
				if (!\OC\Files\Filesystem::isIgnoredDir($file)) {
					if ($this->view->is_dir($user . '/' . $path . '/' . $file)) {
						$this->renameFileKeys($user, $path . '/' . $file, $trash);
					} else {
						$target = $this->getTargetDir($user, $path, $file, $trash);
						$this->createPathForKeys(dirname($target));
						$this->view->rename($user . '/' . $path . '/' . $file, $target);
					}
				}
			}
			closedir($dh);
		}
	}

	private function getTargetDir($user, $filePath, $filename, $trash) {
		if ($trash) {
			$targetDir = $user . '/files_encryption/keys/files_trashbin/' . substr($filePath, strlen('/files_trashbin/keys/')) . '/' . $this->moduleId . '/' . $filename;
		} else {
			$targetDir = $user . '/files_encryption/keys/files/' . substr($filePath, strlen('/files_encryption/keys/')) . '/' . $this->moduleId . '/' . $filename;
		}

		return $targetDir;
	}

	private function deleteOldKeys($user) {
		$this->view->deleteAll($user . '/files_encryption/keyfiles');
		$this->view->deleteAll($user . '/files_encryption/share-keys');
	}

	private function createPathForKeys($path) {
		if (!$this->view->file_exists($path)) {
			$sub_dirs = explode('/', $path);
			$dir = '';
			foreach ($sub_dirs as $sub_dir) {
				$dir .= '/' . $sub_dir;
				if (!$this->view->is_dir($dir)) {
					$this->view->mkdir($dir);
				}
			}
		}
	}
}
