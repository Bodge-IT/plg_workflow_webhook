<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Workflow.Webhook
 *
 * @copyright   Copyright (C) 2015 - 2021 Codextrous Software Pvt. Ltd., Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Categories\Categories;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Workflow\WorkflowTransitionEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\Workflow\WorkflowPluginTrait;
use Joomla\CMS\Workflow\WorkflowServiceInterface;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Utilities\ArrayHelper;

/**
 * Workflow Webhook Plugin
 *
 * @since  1.0.0
 */
class PlgWorkflowWebhook extends CMSPlugin implements SubscriberInterface
{
	use WorkflowPluginTrait;

	/**
	 * Load the language file on instantiation.
	 *
	 * @var    boolean
	 * @since  4.0.0
	 */
	protected $autoloadLanguage = true;

	/**
	 * Loads the CMS Application for direct access
	 *
	 * @var   CMSApplicationInterface
	 * @since 4.0.0
	 */
	protected $app;

	/**
	 * Database object.
	 *
	 * @var    JDatabaseDriver
	 * @since  3.9.0
	 */
	protected $db;

	/**
	 * Returns an array of events this subscriber will listen to.
	 *
	 * @return  array
	 *
	 * @since   4.0.0
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onContentPrepareForm'        => 'onContentPrepareForm',
			'onWorkflowAfterTransition'   => 'onWorkflowAfterTransition',
		];
	}

	/**
	 * The form event.
	 *
	 * @param   Form      $form  The form
	 * @param   stdClass  $data  The data
	 *
	 * @return  boolean
	 *
	 * @since   4.0.0
	 */
	public function onContentPrepareForm(EventInterface $event)
	{
		$form = $event->getArgument('0');
		$data = $event->getArgument('1');

		$context = $form->getName();

		// Extend the transition form
		if ($context === 'com_workflow.transition')
		{
			$this->enhanceWorkflowTransitionForm($form, $data);
		}

		return true;
	}

	/**
	 * Send a Notification to defined users a transion is performed
	 *
	 * @param   string   $context  The context for the content passed to the plugin.
	 * @param   array    $pks      A list of primary key ids of the content that has changed stage.
	 * @param   object  $data    Object containing data about the transition
	 *
	 * @return  boolean
	 *
	 * @since   4.0.0
	 */
	public function onWorkflowAfterTransition(WorkflowTransitionEvent $event)
	{
		$context       = $event->getArgument('extension');
		$extensionName = $event->getArgument('extensionName');
		$transition    = $event->getArgument('transition');
		$pks           = $event->getArgument('pks');

		if (!$this->isSupported($context))
		{
			return;
		}

		$component = $this->app->bootComponent($extensionName);

		// Check if send-mail is active
		if (empty($transition->options['webhook_send_notification']))
		{
			return;
		}

		// ID of the items whose state has changed.
		$pks = ArrayHelper::toInteger($pks);

		if (empty($pks))
		{
			return;
		}

		// The active user
		$user = $this->app->getIdentity();

		// Prepare Language for messages
		$defaultLanguage = ComponentHelper::getParams('com_languages')->get('administrator');
		$debug = $this->app->get('debug_lang');

		$modelName = $component->getModelName($context);
		$model = $component->getMVCFactory()->createModel($modelName, $this->app->getName(),  ['ignore_request' => true]);

		$categories = Categories::getInstance('Content', ['com_content']);
		
		// Get the title of the stage
		$model_stage = $this->app->bootComponent('com_workflow')
			->getMVCFactory()->createModel('Stage', 'Administrator');

		$toStage = $model_stage->getItem($transition->to_stage_id)->title;

		$hasGetItem = method_exists($model, 'getItem');
		$container = Factory::getContainer();

		foreach ($pks as $pk)
		{
			// Get the title of the item which has changed
			$title = '';
			$category = '';

			if ($hasGetItem)
			{
				$item = $model->getItem($pk);
				$title = $item->title;
				$category =  $categories->get($item->catid)->title;
			}

			// Load language for messaging
			$lang = $container->get(LanguageFactoryInterface::class)->createLanguage($user->getParam('admin_language', $defaultLanguage), $debug);
			$lang->load('plg_workflow_webhook');
			$messageText = '';
			
			if (!empty($transition->options['webhook_text']))
			{
				$messageText = $transition->options['webhook_text'];

				$tokens = array('{TITLE}' => $title, 
						'{USER}' => $user->email, 
						'{STATE}' => $lang->_($toStage),
						'{CATEGORY}' => $category
						);
				foreach($tokens as $key => $value){
					$messageText =  str_replace($key, $value, $messageText);
				}
			}
			if (!empty($method = $transition->options['webhook_method'])) {
				$method = strtolower($method).'Call';
				if (method_exists($this, $method)) {
					$this->$method($transition, json_decode($messageText));
					$this->app->enqueueMessage(Text::_('PLG_WORKFLOW_WEBHOOK_SENT'), 'message');
				}
			}
		}
	}

	/**
	 * Check if the current plugin should execute workflow related activities
	 *
	 * @param string $context
	 *
	 * @return boolean
	 *
	 * @since   4.0.0
	 */
	protected function isSupported($context)
	{
		//if (!$this->checkWhiteAndBlacklist($context))
		if (!$this->checkAllowedAndForbiddenlist($context))
		{
			return false;
		}

		$parts = explode('.', $context);

		// We need at least the extension + view for loading the table fields
		if (count($parts) < 2)
		{
			return false;
		}

		$component = $this->app->bootComponent($parts[0]);

		if (!$component instanceof WorkflowServiceInterface
			|| !$component->isWorkflowActive($context))
		{
			return false;
		}

		return true;
	}

	protected function getCall($transition, $body)
	{
		if (!empty($url = $transition->options['webhook_url'])) {
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			$result = curl_exec($ch);
			return $result;
		}
	}

	protected function postCall($transition, $data_string)
	{
		if (!empty($url = $transition->options['webhook_url'])) {
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
			curl_setopt($ch, CURLOPT_FAILONERROR, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getHeaders($transition));
			
			$result = curl_exec($ch);
			return $result;
		}
	}

	protected function putCall($transition, $data_string)
	{
		if (!empty($url = $transition->options['webhook_url'])) {
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
			curl_setopt($ch, CURLOPT_FAILONERROR, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getHeaders($transition));

			$result = curl_exec($ch);
			return $result;
		}
	}

	protected function deleteCall($transition, $data_string)
	{
		if (!empty($url = $transition->options['webhook_url'])) {
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
			curl_setopt($ch, CURLOPT_FAILONERROR, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getHeaders($transition));
		 
			$result = curl_exec($ch);
			return $result;
		}
	}

	public function getHeaders($transition)
	{
		$headers = [];

		if (!empty($transition->options['webhook_headers'])) {
			foreach ($transition->options['webhook_headers'] as $header) {
				if (!empty($header->name)) {
					$headers[] = $header->name .': '. $header->value;
				}
			}
		}

		return $headers;
	}
}
