<?php

$extDir = dirname( dirname( __DIR__ ) );

require_once( "$extDir/BlueSpiceFoundation/maintenance/BSMaintenance.php" );

use BlueSpice\Services;
use BlueSpice\Social\Topics\Entity\Topic;

class BSMigrateShoutbox extends LoggedUpdateMaintenance {

	protected function noDataToMigrate() {
		return $this->getDB( DB_REPLICA )->tableExists( 'bs_shoutbox' ) === false;
	}

	protected $data = [];
	protected function readData() {
		$res = $this->getDB( DB_REPLICA )->select(
			'bs_shoutbox',
			'*',
			[ 
				'sb_title = ""', //shout box entries with a title a specific to
				//to rated comments and should not be handled here
				'sb_archived = 0'
			] 
			
		);
		foreach( $res as $row ) {
			$this->data[$row->sb_page_id][] = $row;
		}
	}

	protected function doDBUpdates() {
		if( $this->noDataToMigrate() ) {
			$this->output( "bs_shoutbox -> No data to migrate\n" );
			return true;
		}
		$this->output( "...bs_shoutbox -> migration...\n" );

		$this->readData();
		foreach( $this->data as $articleId => $shouts ) {
			//article does not exists anymore => ignore shouts, as we con not
			//figure out the discussion page
			if( !$title = $this->ensureDiscussionPage( (int) $articleId ) ) {
				continue;
			}
			foreach( $shouts as $shout ) {
				$this->output( "." );

				if( empty( $shout->sb_id ) ) {
					continue; //dont even ask why this is here ^^
				}
				$this->output( "\n$shout->sb_id..." );
				if( !$entity = $this->makeEntity( $shout, $title ) ) {
					$this->output( "Topic could not be created" );
					continue;
				}
				try {
					$status = $entity->save(
						$this->getMaintenanceUser()
					);
				} catch( \Exception $e ) {
					$this->output( $e->getMessage() );
					continue;
				}
				if( !$status->isOK() ) {
					$this->output( $status->getMessage() );
					continue;
				}
				$this->modifySourceTitleTimestamp(
					$entity->getTitle(),
					$shout
				);
			}
		}
		$this->output( "\n" );
		return true;
	}

	/**
	 *
	 * @param \stdClass $shout
	 * @param \Title $title
	 * @return Topic
	 */
	protected function makeEntity( $shout, $title ) {
		if( !$user = $this->extractUser( $shout ) ) {
			$this->output(
				"user from shout $shout->sb_id could not be extracted"
			);
			return null;
		}
		try {
			$entity = $this->getFactory()->newFromObject( (object) [
				Topic::ATTR_TYPE => Topic::TYPE,
				Topic::ATTR_DISCUSSION_TITLE_ID => (int) $title->getArticleID(),
				Topic::ATTR_TOPIC_TITLE => $this->makeGenericTopicTitle( $user ),
				Topic::ATTR_OWNER_ID => $user->getId(),
				Topic::ATTR_TEXT => $shout->sb_message
			]);
		} catch ( \Exception $e ) {
			$this->output( $e->getMessage() );
			return null;
		}
		return $entity;
	}

	protected function extractUser( $shout ) {
		$user = null;
		if( !empty( $shout->sb_user_id ) ) {
			$user = \User::newFromId( $shout->sb_user_id );
		}
		if( !$user && !empty( $shout->sb_user_name ) ) {
			$user = \User::newFromName( $shout->sb_user_name );
		}
		return $user;
	}

	protected function makeGenericTopicTitle( $user ) {
		$userHelper = Services::getInstance()->getBSUtilityFactory()
			->getUserHelper( $user );

		$msg = \Message::newFromKey(
			'bs-socialtopics-entity-topic-topictitle-shoutboxmigration',
			$userHelper->getDisplayName()
		);
		return $msg->inContentLanguage()->plain();
	}

	/**
	 * @retrun \BlueSpice\EntityFactory
	 */
	protected function getFactory() {
		return Services::getInstance()->getBSEntityFactory();
	}

	/**
	 * 
	 * @param integer $articleID
	 * @return \Title | false
	 */
	protected function ensureDiscussionPage( $articleID ) {
		if( !$title = \Title::newFromID( $articleID ) ) {
			return false;
		}
		if( $title->getNamespace() === NS_SOCIALENTITY || $title->getNamespace() === NS_SOCIALENTITY_TALK ) {
			return false;
		}
		if( $title->getTalkPage()->exists() ) {
			return $title->getTalkPage();
		}
		$status = \BlueSpice\Social\Topics\Extension::createDiscussionPage(
			$title->getTalkPage(),
			$this->getMaintenanceUser()
		);
		if( $status->isOK() ) {
			return $title->getTalkPage();
		}
		$this->output( $title->getTalkPage()." could not be created" );
		return false;
	}

	/**
	 *
	 * @param \Title $title
	 * @param type $shout
	 */
	protected function modifySourceTitleTimestamp( $title, $shout ) {
		if( !$title || empty( $shout->sb_timestamp ) || empty( $title->getLatestRevID() ) ) {
			return false;
		}

		//dont use any MWTimestamp here, as they are not reliably in cmd!
		$date = \DateTime::createFromFormat( 'YmdHis', $shout->sb_timestamp );
		if( !$date || !$ts = $date->format( 'YmdHis' ) ) {
			return false;
		}

		//hacky, hope for the best ;)
		return $this->getDB( DB_MASTER )->update(
			'revision',
			[ 'rev_timestamp' => $ts ],
			[ 'rev_id' => $title->getLatestRevID() ],
			__METHOD__
		);
	}

	protected function getMaintenanceUser() {
		return Services::getInstance()->getBSUtilityFactory()
			->getMaintenanceUser()->getUser();
	}

	protected function getUpdateKey() {
		return 'bs_shoutbox-migration';
	}

}