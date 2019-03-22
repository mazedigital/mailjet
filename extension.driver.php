<?php


	require_once EXTENSIONS . '/mailjet/vendor/autoload.php';

	use \Mailjet\Resources;



	Class extension_Mailjet extends Extension{

		private $apiContext;
		private $clientId;
		private $clientSecret;
		private $messages = array();

		public function __construct() {
			// die('construct');
			$this->apiKey = Symphony::Configuration()->get('api-key','mailjet');
			$this->secretKey = Symphony::Configuration()->get('secret-key','mailjet');
			$this->mj = new \Mailjet\Client($this->apiKey, $this->secretKey,true,['version' => 'v3.1']);
		}

		public function getApiContext(){
			return $this->apiContext;
		}

		/**
		 * Installation
		 */
		public function install() {
			// A table to keep track of user tokens in relation to the current current user id
			// Symphony::Database()->query("CREATE TABLE IF NOT EXISTS `tbl_paypal_token` (
			// 	`user_id` VARCHAR(255) NOT NULL ,
			// 	`refresh_token` VARCHAR(255) NOT NULL
			// PRIMARY KEY (`user_id`,`system`)
			// )ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;");

			return true;
		}

		/**
		 * Update
		 */
		public function update($previousVersion = false) {
			$this->install();
		}


		public function getSubscribedDelegates() {
			return array(
				array(
					'page' => '/publish/edit/',
					'delegate' => 'EntryPostEdit',
					'callback' => 'entryPostEdit'
				),
				array(
					'page' => '/publish/edit/',
					'delegate' => 'EntryPostCreate',
					'callback' => 'entryPostCreate'
				),
				// array(
				// 	'page' => '/system/preferences/',
				// 	'delegate' => 'AddCustomPreferenceFieldsets',
				// 	'callback' => 'appendPreferences'
				// ),
				// array(
				// 	'page' => '/system/preferences/',
				// 	'delegate' => 'Save',
				// 	'callback' => 'savePreferences'
				// ),
				// array(
				// 	'page' => '/frontend/',
				// 	'delegate' => 'FrontendProcessEvents',
				// 	'callback' => 'appendEventXML'
				// ),
				// array(
				// 	'page' => '/frontend/',
				// 	'delegate' => 'FrontendParamsResolve',
				// 	'callback' => 'appendAccessToken'
				// ),
				// array(
				// 	'page' => '/frontend/',
				// 	'delegate' => 'FrontendPageResolved',
				// 	'callback' => 'frontendPageResolved'
				// ),
			);
		}

		public function getLists(){
			$response = $this->mj->get(Resources::$Contactslist);
			return $response->getData();
		}

		public function send($data){
			$response = $this->mj->post(Resources::$Email, $data);
			// var_dump($response);
			// var_dump($response->getReasonPhrase());die;
			return $response->getData();
		}

		public function addBulkMessage($data){
			$this->messages[] = $data;
			if (sizeof($this->messages) == 50){
				return $this->triggerBulkSend();
			}
		}

		public function triggerBulkSend(){
			$response = $this->mj->post(Resources::$Email, array('Body'=>['Messages'=>$this->messages]));
			// $response = $this->mj->post(Resources::$Email, array('Body'=>$this->messages[0]));

			//empty messages array
			$this->messages = array();



			return $response->getData();
		}

		private function updateContact($context){

			if ( Symphony::Configuration()->get('section_' . $context['section']->get('id') ,'mailjet') ){

				$sectionConfig = Symphony::Configuration()->get('section_' . $context['section']->get('id') ,'mailjet');

				foreach ($sectionConfig['Properties'] as $key => $value) {
					$sectionConfig['Properties'][$key] = $this->compile($context['entry'],$value);
				}

				$body = [
					'Action' => 'addnoforce',
					'Email' => $this->compile($context['entry'],$sectionConfig['Email']),
					'Name' => $this->compile($context['entry'],$sectionConfig['Name']),
					'Properties' => $sectionConfig['Properties']/*[
						'Name' => $this->compile($context['entry'],'{/data/entry/name}'),
						'Surname' => $this->compile($context['entry'],'{/data/entry/surname}'),
					]*/
				];

				$response = $this->mj->post(Resources::$ContactslistManagecontact, [
					'body' => $body,
					'id' => $sectionConfig['id']
				]);

			}
		}

		public function entryPostCreate($context){
			$this->updateContact($context);
		}

		public function entryPostEdit($context){
			$this->updateContact($context);
		}

	/*-------------------------------------------------------------------------
		Utilities:
	-------------------------------------------------------------------------*/



		public function compile(&$entry,$expression) {

			$xpath = $this->getXPath($entry, 'yes');

			$replacements = array();

			// Find queries:
			preg_match_all('/\{[^\}]+\}/', $expression, $matches);

			// Find replacements:
			foreach ($matches[0] as $match) {
				$result = @$xpath->evaluate('string(' . trim($match, '{}') . ')');

				if (!is_null($result)) {
					$replacements[$match] = trim($result);
				}

				else {
					$replacements[$match] = '';
				}
			}

			// Apply replacements:
			$value = str_replace(
				array_keys($replacements),
				array_values($replacements),
				$expression
			);

			return $value;

		}

		public function getXPath($entry, $fetch_associated_counts = NULL) {
			$entry_xml = new XMLElement('entry');
			$data = $entry->getData();
			$fields = array();

			$entry_xml->setAttribute('id', $entry->get('id'));

			//Add date created and edited values
			$date = new XMLElement('system-date');

			$date->appendChild(
				General::createXMLDateObject(
				DateTimeObj::get('U', $entry->get('creation_date')),
				'created'
				)
			);

			$date->appendChild(
				General::createXMLDateObject(
				DateTimeObj::get('U', $entry->get('modification_date')),
				'modified'
				)
			);

			$entry_xml->appendChild($date);

			//Reflect Workspace and Siteroot params
			$workspace = new XMLElement('workspace', URL .'/workspace');
			$root = new XMLElement('root', URL);

			// Add associated entry counts
			if($fetch_associated_counts == 'yes') {
				$associated = $entry->fetchAllAssociatedEntryCounts();

				if (is_array($associated) and !empty($associated)) {
					foreach ($associated as $section_id => $count) {
						$section = SectionManager::fetch($section_id);

						if(($section instanceof Section) === false) continue;
						$entry_xml->setAttribute($section->get('handle'), (string)$count);
					}
				}
			}

			// Add fields:
			foreach ($data as $field_id => $values) {
				if (empty($field_id)) continue;

				$field = FieldManager::fetch($field_id);
				$field->appendFormattedElement($entry_xml, $values, false, null, $entry->get('id'));
			}

			$xml = new XMLElement('data');
			$xml->appendChild($entry_xml);
			$xml->appendChild($workspace);
			$xml->appendChild($root);

			// Build some context
			$section = SectionManager::fetch($entry->get('section_id'));
			$params = new XMLElement('params');
			$params->appendChild(
				new XMLElement('section-handle', $section->get('handle'))
			);
			$params->appendChild(
				new XMLElement('entry-id', $entry->get('id'))
			);
			$xml->prependChild($params);

			$dom = new DOMDocument();
			$dom->strictErrorChecking = false;
			$dom->loadXML($xml->generate(true));

			$xpath = new DOMXPath($dom);

			if (version_compare(phpversion(), '5.3', '>=')) {
				$xpath->registerPhpFunctions();
			}

			return $xpath;
		}
	}
