<?php
/**
 * Setup Controller Tests
 *
 * @copyright       (c) 2015-present Bolt Softwares Pvt Ltd
 * @license         http://www.passbolt.com/license
 * @package         app.Test.Case.Controller.SetupControllerTest
 */
App::uses('AppController', 'Controller');
App::uses('SetupController', 'Controller');
App::uses('User', 'Model');
App::uses('Group', 'Model');
App::uses('Role', 'Model');
App::uses('CakeSession', 'Model');
App::uses('CakeSession', 'Model/Datasource');
App::uses('CakeSessionFixture', 'Test/Fixture');

class SetupControllerTest extends ControllerTestCase {

	public $fixtures
		= array(
			'app.groups_user',
			'app.group',
			'app.user',
			'app.gpgkey',
			'app.email_queue',
			'app.profile',
			'app.file_storage',
			'app.role',
			'app.authenticationToken',
			'app.authenticationLog',
			'app.authenticationBlacklist',
			'core.cakeSession',
			'app.user_agent',
			'app.controller_log',
			'app.resource',
			'app.category',
			'app.categories_resource',
			'app.permission',
			'app.permissions_type',
			'app.permission_view',
		);

	public function setUp() {
		parent::setUp();
		$this->User = Common::getModel('User');
		$this->Gpgkey = Common::getModel('Gpgkey');
		$this->session = new CakeSession();
		$this->session->init();
	}

	public function tearDown() {
		parent::tearDown();
		// Make sure there is no session active after each test
		$this->User->setInactive();
	}

	/**
	 * Start a recovery and return user and token.
	 */
	private function __startRecovery($username) {
		$u = $this->User->findByUsername($username);
		$token = $this->User->AuthenticationToken->generate($u['User']['id']);
		return [
			'User' => $u['User'],
			'AuthenticationToken' => $token['AuthenticationToken']
		];
	}

	/**
	 * Test complete recovery when user id is missing.
	 */
	public function testCompleteRecoveryUserIdIsMissing() {
		$this->User->setInactive();
		$this->setExpectedException('HttpException', 'The user id is missing');
		$this->testAction('/setup/completeRecovery.json', array('return' => 'contents', 'method' => 'put'), true);
	}

	/**
	 * Test complete recovery when user id not valid.
	 */
	public function testCompleteRecoveryUserIdNotValid() {
		$this->User->setInactive();
		$this->setExpectedException('HttpException', 'The user id is invalid');
		$this->testAction('/setup/completeRecovery/badId.json', array('return' => 'contents', 'method' => 'put'), true);
	}

	/**
	 * Test account validation when the user does not exist.
	 */
	public function testCompleteRecoveryUserDoesNotExist() {
		$this->User->setInactive();
		$id = Common::uuid('not-valid-reference');
		$this->setExpectedException('HttpException', 'The user does not exist');
		$this->testAction(
			"/setup/completeRecovery/{$id}.json",
			array('return' => 'contents', 'method' => 'put'),
			true
		);
	}

	/**
	 * Test complete recovery where no data is provided.
	 */
	public function testCompleteRecoveryNoDataProvided() {
		$user = $this->User->findById(Common::uuid('user.id.admin'));
		$this->User->setActive($user);

		$recovery = $this->__startRecovery('admin@passbolt.com');
		$this->User->setInactive();

		$this->setExpectedException('HttpException', 'No data were provided');
		$url = "/users/validateAccount/{$recovery['User']['id']}.json";
		$this->testAction($url, array(
				'method' => 'put',
				'return' => 'contents'
			));
	}

	/**
	 * Test complete recovery with wrong Gpg key.
	 */
	public function testCompleteRecoveryWrongGpgkey() {
		$user = $this->User->findById(Common::uuid('user.id.ada'));
		$this->User->setActive($user);

		$recovery = $this->__startRecovery('ada@passbolt.com');
		$userId = $recovery['User']['id'];
		$this->User->setInactive();

		$at = $recovery['AuthenticationToken'];

		// Dummy key taken from one generated by pgpjs.
		$dummyKey = array(
			'key' => file_get_contents( Configure::read('GPG.testKeys.path') . 'betty_public.key')
		);

		$this->setExpectedException('HttpException', 'The key provided doesn\'t belong to given user');

		$url = "/setup/completeRecovery/{$userId}.json";
		$this->testAction(
			$url,
			array(
				'data'   => array (
					'AuthenticationToken' => array (
						'token' => $at['token'],
					),
					'Gpgkey' => $dummyKey
				),
				'method' => 'put',
				'return' => 'contents'
			)
		);
	}

	/**
	 * Test complete recovery in a successful case.
	 */
	public function testCompleteRecoverySuccess() {
		$user = $this->User->findById(Common::uuid('user.id.ada'));
		$this->User->setActive($user);

		$recovery = $this->__startRecovery('ada@passbolt.com');
		$userId = $recovery['User']['id'];
		$this->User->setInactive();

		$at = $recovery['AuthenticationToken'];

		$tokenIsValid = $this->User->AuthenticationToken->isValid($at['token'], $userId);
		$this->assertTrue($tokenIsValid);

		// Dummy key taken from one generated by pgpjs.
		$dummyKey = array(
			'key' => file_get_contents( Configure::read('GPG.testKeys.path') . 'ada_public.key')
		);

		$url = "/setup/completeRecovery/{$userId}.json";
		$validate = $this->testAction(
			$url,
			array(
				'data'   => array (
					'AuthenticationToken' => array (
						'token' => $at['token'],
					),
					'Gpgkey' => $dummyKey
				),
				'method' => 'put',
				'return' => 'contents'
			)
		);
		$json = json_decode($validate, true);
		$this->assertEquals(
			Status::SUCCESS,
			$json['header']['status'],
			"setupAccount /setup/completeRecovery/{$userId}.json : The test should return a success but is returning {$json['header']['status']}"
		);

		// Get user and check if deactivated.
		$gpkey = $this->Gpgkey->findByUserId($userId);
		$this->assertEquals($gpkey['Gpgkey']['key'], $dummyKey['key'], "After account validation the key was supposed to be set, but is not");
		$this->assertEquals($gpkey['Gpgkey']['bits'], 4096);
		$this->assertEquals($gpkey['Gpgkey']['uid'], 'Ada Lovelace <ada@passbolt.com>');
		$this->assertEquals($gpkey['Gpgkey']['type'], 'RSA');
		$this->assertEquals($gpkey['Gpgkey']['fingerprint'], '03F60E958F4CB29723ACDF761353B5B15D9B054F');
		$this->assertEquals($gpkey['Gpgkey']['key_id'], '5D9B054F');

		// Test that token is not valid anymore.
		$tokenIsValid = $this->User->AuthenticationToken->isValid($at['token'], $userId);
		$this->assertEmpty($tokenIsValid);
	}
}