<?php
/**
 * Kunena Component
 *
 * @package       Kunena.Site
 * @subpackage    Controllers
 *
 * @copyright (C) 2008 - 2015 Kunena Team. All rights reserved.
 * @license       http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link          http://www.kunena.org
 **/
defined('_JEXEC') or die();

/**
 * Kunena User Controller
 *
 * @since        2.0
 */
class KunenaControllerUser extends KunenaController
{
	/**
	 * @param   bool $cachable
	 * @param   bool $urlparams
	 *
	 * @return JControllerLegacy|void
	 */
	public function display($cachable = false, $urlparams = false)
	{
		// Redirect profile to integrated component if profile integration is turned on
		$redirect = 1;
		$active   = $this->app->getMenu()->getActive();

		if (!empty($active))
		{
			$params   = $active->params;
			$redirect = $params->get('integration', 1);
		}

		if ($redirect && JFactory::getApplication()->input->getCmd('format', 'html') == 'html')
		{
			$profileIntegration = KunenaFactory::getProfile();
			$layout             = JFactory::getApplication()->input->getCmd('layout', 'default');

			if ($profileIntegration instanceof KunenaProfileKunena)
			{
				// Continue
			}
			elseif ($layout == 'default')
			{
				$url = $this->me->getUrl(false);
			}
			elseif ($layout == 'list')
			{
				$url = $profileIntegration->getUserListURL('', false);
			}

			if (!empty($url))
			{
				$this->setRedirect($url);

				return;
			}
		}

		$layout = JFactory::getApplication()->input->getCmd('layout', 'default');
		if ($layout == 'list')
		{
			if (KunenaFactory::getConfig()->userlist_allowed && JFactory::getUser()->guest)
			{
				throw new KunenaExceptionAuthorise(JText::_('COM_KUNENA_NO_ACCESS'), '401');
			}
		}

		parent::display();
	}

	/**
	 *
	 */
	public function search()
	{
		$model = $this->getModel('user');

		$uri = new JUri('index.php?option=com_kunena&view=user&layout=list');

		$state      = $model->getState();
		$search     = $state->get('list.search');
		$limitstart = $state->get('list.start');

		if ($search)
		{
			$uri->setVar('search', $search);
		}

		if ($limitstart)
		{
			$uri->setVar('limitstart', $search);
		}

		$this->setRedirect(KunenaRoute::_($uri, false));
	}

	/**
	 * @throws Exception
	 */
	public function change()
	{
		if (!JSession::checkToken('get'))
		{
			$this->app->enqueueMessage(JText::_('COM_KUNENA_ERROR_TOKEN'), 'error');
			$this->setRedirectBack();

			return;
		}

		$layout = JFactory::getApplication()->input->getString('topic_layout', 'default');
		$this->me->setTopicLayout($layout);
		$this->setRedirectBack();
	}

	/**
	 *
	 */
	public function karmaup()
	{
		$this->karma(1);
	}

	/**
	 *
	 */
	public function karmadown()
	{
		$this->karma(-1);
	}

	/**
	 * @throws KunenaExceptionAuthorise
	 *
	 * @todo Allow moderators to save another users profile (without account info).
	 */
	public function save()
	{
		$return = null;

		if (!JSession::checkToken('post'))
		{
			throw new KunenaExceptionAuthorise(JText::_('COM_KUNENA_ERROR_TOKEN'), 403);
		}

		// Make sure that the user exists.
		if (!$this->me->exists())
		{
			throw new KunenaExceptionAuthorise(JText::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
		}

		$errors = 0;

		// Save Joomla user.
		$this->user = JFactory::getUser();
		$success    = $this->saveUser();

		if (!$success)
		{
			$errors++;
			$this->app->enqueueMessage(JText::_('COM_KUNENA_PROFILE_ACCOUNT_NOT_SAVED'), 'error');
		}

		// Save avatar.
		$success = $this->saveAvatar();

		if ($success)
		{
			if ($this->format == 'json')
			{
				// Pre-create both 28px and 100px avatars so we have them available for AJAX
				$avatars           = array();
				$avatars['small']  = $this->me->getAvatarUrl(28, 28);
				$avatars['medium'] = $this->me->getAvatarUrl(100, 100);
				$return            = array('avatars' => $avatars);
			}
		}
		else
		{
			$errors++;
			$this->app->enqueueMessage(JText::_('COM_KUNENA_PROFILE_AVATAR_NOT_SAVED'), 'error');
		}

		// Save Kunena user.
		$this->saveProfile();
		$this->saveSettings();
		$success = $this->me->save();

		if (!$success)
		{
			$errors++;
			$this->app->enqueueMessage($this->me->getError(), 'error');
		}

		JPluginHelper::importPlugin('system');

		$dispatcher = JEventDispatcher::getInstance();
		$dispatcher->trigger('OnAfterKunenaProfileUpdate', array($this->me, $success));

		if ($errors)
		{
			throw new KunenaExceptionAuthorise(JText::_('COM_KUNENA_PROFILE_SAVE_ERROR'), 500);
		}

		$this->app->enqueueMessage(JText::_('COM_KUNENA_PROFILE_SAVED'));

		if ($return)
		{
			return $return;
		}
	}

	/**
	 * @throws Exception
	 */
	public function ban()
	{
		$user = KunenaFactory::getUser(JFactory::getApplication()->input->getInt('userid', 0));

		if (!$user->exists() || !JSession::checkToken('post'))
		{
			$this->setRedirect($user->getUrl(false), JText::_('COM_KUNENA_ERROR_TOKEN'), 'error');

			return;
		}

		$ban = KunenaUserBan::getInstanceByUserid($user->userid, true);

		if (!$ban->canBan())
		{
			$this->setRedirect($user->getUrl(false), $ban->getError(), 'error');

			return;
		}

		$ip             = JFactory::getApplication()->input->getString('ip', '');
		$block          = JFactory::getApplication()->input->getInt('block', 0);
		$expiration     = JFactory::getApplication()->input->getString('expiration', '');
		$reason_private = JFactory::getApplication()->input->getString('reason_private', '');
		$reason_public  = JFactory::getApplication()->input->getString('reason_public', '');
		$comment        = JFactory::getApplication()->input->getString('comment', '');

		$banDelPosts    = JFactory::getApplication()->input->getString('bandelposts', '');
		$DelAvatar      = JFactory::getApplication()->input->getString('delavatar', '');
		$DelSignature   = JFactory::getApplication()->input->getString('delsignature', '');
		$DelProfileInfo = JFactory::getApplication()->input->getString('delprofileinfo', '');

		$delban = JFactory::getApplication()->input->getString('delban', '');

		if (!$ban->id)
		{
			$ban->ban($user->userid, $ip, $block, $expiration, $reason_private, $reason_public, $comment);
			$success = $ban->save();
		}
		else
		{
			if ($delban)
			{
				$ban->unBan($comment);
				$success = $ban->save();
			}
			else
			{
				$ban->blocked = $block;
				$ban->setExpiration($expiration, $comment);
				$ban->setReason($reason_public, $reason_private);
				$success = $ban->save();
			}
		}

		if ($block)
		{
			if ($ban->isEnabled())
			{
				$this->app->logout($user->userid);
				$message = JText::_('COM_KUNENA_USER_BLOCKED_DONE');
			}
			else
			{
				$message = JText::_('COM_KUNENA_USER_UNBLOCKED_DONE');
			}
		}
		else
		{
			if ($ban->isEnabled())
			{
				$message = JText::_('COM_KUNENA_USER_BANNED_DONE');
			}
			else
			{
				$message = JText::_('COM_KUNENA_USER_UNBANNED_DONE');
			}
		}

		if (!$success)
		{
			$this->app->enqueueMessage($ban->getError(), 'error');
		}
		else
		{
			$this->app->enqueueMessage($message);
		}

		if (!empty($DelAvatar) || !empty($DelProfileInfo))
		{
			$avatar_deleted = '';
			// Delete avatar from file system
			if (is_file(JPATH_ROOT . '/media/kunena/avatars/' . $user->avatar) && !stristr($user->avatar, 'gallery/'))
			{
				KunenaFile::delete(JPATH_ROOT . '/media/kunena/avatars/' . $user->avatar);
				$avatar_deleted = JText::_('COM_KUNENA_MODERATE_DELETED_BAD_AVATAR_FILESYSTEM');
			}

			$user->avatar = '';
			$user->save();
			$this->app->enqueueMessage(JText::_('COM_KUNENA_MODERATE_DELETED_BAD_AVATAR') . $avatar_deleted);
		}

		if (!empty($DelProfileInfo))
		{
			$user->personalText = '';
			$user->birthdate    = '0000-00-00';
			$user->location     = '';
			$user->gender       = 0;
			$user->icq          = '';
			$user->aim          = '';
			$user->yim          = '';
			$user->microsoft    = '';
			$user->skype        = '';
			$user->google       = '';
			$user->twitter      = '';
			$user->facebook     = '';
			$user->myspace      = '';
			$user->linkedin     = '';
			$user->delicious    = '';
			$user->friendfeed   = '';
			$user->digg         = '';
			$user->blogspot     = '';
			$user->flickr       = '';
			$user->bebo         = '';
			$user->instagram    = '';
			$user->qq           = '';
			$user->qzone        = '';
			$user->weibo        = '';
			$user->wechat       = '';
			$user->apple        = '';
			$user->vk           = '';
			$user->telegram     = '';
			$user->websitename  = '';
			$user->websiteurl   = '';
			$user->signature    = '';
			$user->save();
			$this->app->enqueueMessage(JText::_('COM_KUNENA_MODERATE_DELETED_BAD_PROFILEINFO'));
		}
		elseif (!empty($DelSignature))
		{
			$user->signature = '';
			$user->save();
			$this->app->enqueueMessage(JText::_('COM_KUNENA_MODERATE_DELETED_BAD_SIGNATURE'));
		}

		if (!empty($banDelPosts))
		{
			$params = array('starttime' => '-1', 'user' => $user->userid, 'mode' => 'unapproved');

			list($total, $messages) = KunenaForumMessageHelper::getLatestMessages(false, 0, 0, $params);

			$parmas_recent = array('starttime' => '-1', 'user' => $user->userid);

			list($total, $messages_recent) = KunenaForumMessageHelper::getLatestMessages(false, 0, 0, $parmas_recent);

			$messages = array_merge($messages_recent, $messages);

			foreach ($messages as $mes)
			{
				$mes->publish(KunenaForum::DELETED);
			}

			$this->app->enqueueMessage(JText::_('COM_KUNENA_MODERATE_DELETED_BAD_MESSAGES'));
		}

		$this->setRedirect($user->getUrl(false));
	}

	/**
	 *
	 */
	public function cancel()
	{
		$user = KunenaFactory::getUser();
		$this->setRedirect($user->getUrl(false));
	}

	/**
	 * @throws Exception
	 */
	public function login()
	{
		if (!JFactory::getUser()->guest || !JSession::checkToken('post'))
		{
			$this->app->enqueueMessage(JText::_('COM_KUNENA_ERROR_TOKEN'), 'error');
			$this->setRedirectBack();

			return;
		}

		$username  = JFactory::getApplication()->input->getString('username', '', 'POST');
		$password  = JFactory::getApplication()->input->getString('password', '', 'POST', 'raw');
		$remember  = JFactory::getApplication()->input->getBool('remember', false, 'POST');
		$secretkey = JFactory::getApplication()->input->getString('secretkey', null, 'POST');

		$login = KunenaLogin::getInstance();
		$error = $login->loginUser($username, $password, $remember, $secretkey);

		// Get the return url from the request and validate that it is internal.
		$return = base64_decode(JFactory::getApplication()->input->get('return', '', 'method', 'base64')); // Internal URI

		if (!$error && $return && JURI::isInternal($return))
		{
			// Redirect the user.
			$this->setRedirect(JRoute::_($return, false));

			return;
		}

		$this->setRedirectBack();
	}

	/**
	 * @throws Exception
	 */
	public function logout()
	{
		if (!JSession::checkToken('request'))
		{
			$this->app->enqueueMessage(JText::_('COM_KUNENA_ERROR_TOKEN'), 'error');
			$this->setRedirectBack();

			return;
		}

		$login = KunenaLogin::getInstance();

		if (!JFactory::getUser()->guest)
		{
			$login->logoutUser();
		}

		// Get the return url from the request and validate that it is internal.
		$return = base64_decode(JFactory::getApplication()->input->get('return', '', 'method', 'base64')); // Internal URI

		if ($return && JURI::isInternal($return))
		{
			// Redirect the user.
			$this->setRedirect(JRoute::_($return, false));

			return;
		}

		$this->setRedirectBack();
	}

	/**
	 * Save online status for user
	 *
	 * @return void
	 */
	public function status()
	{
		if (!JSession::checkToken('request'))
		{
			$this->app->enqueueMessage(JText::_('COM_KUNENA_ERROR_TOKEN'), 'error');
			$this->setRedirectBack();

			return;
		}

		$status     = $this->app->input->getInt('status', 0);
		$me         = KunenaUserHelper::getMyself();
		$me->status = $status;

		if (!$me->save())
		{
			$this->app->enqueueMessage($me->getError(), 'error');
		}
		else
		{
			$this->app->enqueueMessage(JText::_('Successfully Saved Status'));
		}

		$this->setRedirectBack();
	}

	/**
	 * Set online status text for user
	 *
	 * @return void
	 */
	public function statusText()
	{
		if (!JSession::checkToken('request'))
		{
			$this->app->enqueueMessage(JText::_('COM_KUNENA_ERROR_TOKEN'), 'error');
			$this->setRedirectBack();

			return;
		}

		$status_text     = $this->app->input->getString('status_text', null, 'POST');
		$me              = KunenaUserHelper::getMyself();
		$me->status_text = $status_text;

		if (!$me->save())
		{
			$this->app->enqueueMessage($me->getError(), 'error');
		}
		else
		{
			$this->app->enqueueMessage(JText::_('COM_KUNENA_STATUS_SAVED'));
		}

		$this->setRedirectBack();
	}

	// Internal functions:

	/**
	 * @param $karmaDelta
	 *
	 * @throws Exception
	 */
	protected function karma($karmaDelta)
	{
		if (!JSession::checkToken('get'))
		{
			$this->app->enqueueMessage(JText::_('COM_KUNENA_ERROR_TOKEN'), 'error');
			$this->setRedirectBack();

			return;
		}

		$karma_delay = '14400'; // 14400 seconds = 6 hours
		$userid      = JFactory::getApplication()->input->getInt('userid', 0);

		$target = KunenaFactory::getUser($userid);

		if (!$this->config->showkarma || !$this->me->exists() || !$target->exists() || $karmaDelta == 0)
		{
			$this->app->enqueueMessage(JText::_('COM_KUNENA_USER_ERROR_KARMA'), 'error');
			$this->setRedirectBack();

			return;
		}

		$now = JFactory::getDate()->toUnix();

		if (!$this->me->isModerator() && $now - $this->me->karma_time < $karma_delay)
		{
			$this->app->enqueueMessage(JText::_('COM_KUNENA_KARMA_WAIT'), 'notice');
			$this->setRedirectBack();

			return;
		}

		if ($karmaDelta > 0)
		{
			if ($this->me->userid == $target->userid)
			{
				$this->app->enqueueMessage(JText::_('COM_KUNENA_KARMA_SELF_INCREASE'), 'notice');
				$karmaDelta = -10;
			}
			else
			{
				$this->app->enqueueMessage(JText::_('COM_KUNENA_KARMA_INCREASED'));
			}
		}
		else
		{
			if ($this->me->userid == $target->userid)
			{
				$this->app->enqueueMessage(JText::_('COM_KUNENA_KARMA_SELF_DECREASE'), 'notice');
			}
			else
			{
				$this->app->enqueueMessage(JText::_('COM_KUNENA_KARMA_DECREASED'));
			}
		}

		$this->me->karma_time = $now;

		if ($this->me->userid != $target->userid && !$this->me->save())
		{
			$this->app->enqueueMessage($this->me->getError(), 'notice');
			$this->setRedirectBack();

			return;
		}

		$target->karma += $karmaDelta;

		if (!$target->save())
		{
			$this->app->enqueueMessage($target->getError(), 'notice');
			$this->setRedirectBack();

			return;
		}

		// Activity integration
		$activity = KunenaFactory::getActivityIntegration();
		$activity->onAfterKarma($target->userid, $this->me->userid, $karmaDelta);
		$this->setRedirectBack();
	}

	// Mostly copied from Joomla 1.5
	/**
	 * @return boolean
	 * @throws Exception
	 */
	protected function saveUser()
	{
		// we only allow users to edit few fields
		$allow = array('name', 'email', 'password', 'password2', 'params');

		if (JComponentHelper::getParams('com_users')->get('change_login_name', 1))
		{
			$allow[] = 'username';
		}

		//clean request
		$post              = JFactory::getApplication()->input->get('post');
		$post['password']  = JFactory::getApplication()->input->get('password', '', 'post', 'string', 'raw'); // RAW input
		$post['password2'] = JFactory::getApplication()->input->get('password2', '', 'post', 'string', 'raw'); // RAW input

		if (empty($post['password']) || empty($post['password2']))
		{
			unset($post['password'], $post['password2']);
		}
		else
		{
			// Do a password safety check.
			if ($post['password'] != $post['password2'])
			{
				$this->app->enqueueMessage(JText::_('COM_KUNENA_PROFILE_PASSWORD_MISMATCH'), 'notice');

				return false;
			}

			if (strlen($post['password']) < 5)
			{
				$this->app->enqueueMessage(JText::_('COM_KUNENA_PROFILE_PASSWORD_NOT_MINIMUM'), 'notice');

				return false;
			}
		}

		$post = array_intersect_key($post, array_flip($allow));

		if (empty($post))
		{
			return true;
		}

		$username = $this->user->get('username');
		$user = new JUser($this->user->id);

		// Bind the form fields to the user table and save.
		if (!($user->bind($post) && $user->save(true)))
		{
			$this->app->enqueueMessage($user->getError(), 'notice');

			return false;
		}

		// Reload the user.
		$this->user->load($this->user->id);
		$session = JFactory::getSession();
		$session->set('user', $this->user);

		// update session if username has been changed
		if ($username && $username != $this->user->username)
		{
			$table = JTable::getInstance('session', 'JTable');
			$table->load($session->getId());
			$table->username = $this->user->username;
			$table->store();
		}

		return true;
	}

	protected function saveProfile()
	{
		if (JFactory::getApplication()->input->get('signature', null) === null)
		{
			return;
		}

		$this->me->personalText = JFactory::getApplication()->input->getString('personaltext', '');
		$birthdate              = JFactory::getApplication()->input->getString('birthdate');

		if (!$birthdate)
		{
			$birthdate = JFactory::getApplication()->input->getInt('birthdate1', '0000') . '-' . JFactory::getApplication()->input->getInt('birthdate2', '00') . '-' . JFactory::getApplication()->input->getInt('birthdate3', '00');
		}

		$this->me->birthdate   = $birthdate;
		$this->me->location    = trim(JFactory::getApplication()->input->getString('location', ''));
		$this->me->gender      = JFactory::getApplication()->input->getInt('gender', '');
		$this->me->icq         = trim(JFactory::getApplication()->input->getString('icq', ''));
		$this->me->aim         = trim(JFactory::getApplication()->input->getString('aim', ''));
		$this->me->yim         = trim(JFactory::getApplication()->input->getString('yim', ''));
		$this->me->microsoft   = trim(JFactory::getApplication()->input->getString('microsoft', ''));
		$this->me->skype       = trim(JFactory::getApplication()->input->getString('skype', ''));
		$this->me->google      = trim(JFactory::getApplication()->input->getString('google', ''));
		$this->me->twitter     = trim(JFactory::getApplication()->input->getString('twitter', ''));
		$this->me->facebook    = trim(JFactory::getApplication()->input->getString('facebook', ''));
		$this->me->myspace     = trim(JFactory::getApplication()->input->getString('myspace', ''));
		$this->me->linkedin    = trim(JFactory::getApplication()->input->getString('linkedin', ''));
		$this->me->delicious   = trim(JFactory::getApplication()->input->getString('delicious', ''));
		$this->me->friendfeed  = trim(JFactory::getApplication()->input->getString('friendfeed', ''));
		$this->me->digg        = trim(JFactory::getApplication()->input->getString('digg', ''));
		$this->me->blogspot    = trim(JFactory::getApplication()->input->getString('blogspot', ''));
		$this->me->flickr      = trim(JFactory::getApplication()->input->getString('flickr', ''));
		$this->me->bebo        = trim(JFactory::getApplication()->input->getString('bebo', ''));
		$this->me->instagram   = trim(JFactory::getApplication()->input->getString('instagram', ''));
		$this->me->qq          = trim(JFactory::getApplication()->input->getString('qq', ''));
		$this->me->qzone       = trim(JFactory::getApplication()->input->getString('qzone', ''));
		$this->me->weibo       = trim(JFactory::getApplication()->input->getString('weibo', ''));
		$this->me->wechat      = trim(JFactory::getApplication()->input->getString('wechat', ''));
		$this->me->apple       = trim(JFactory::getApplication()->input->getString('apple', ''));
		$this->me->vk          = trim(JFactory::getApplication()->input->getString('vk', ''));
		$this->me->telegram    = trim(JFactory::getApplication()->input->getString('telegram', ''));
		$this->me->websitename = JFactory::getApplication()->input->getString('websitename', '');
		$this->me->websiteurl  = JFactory::getApplication()->input->getString('websiteurl', '');
		$this->me->signature   = JFactory::getApplication()->input->get('signature', '', 'post', 'string', 'raw'); // RAW input
	}

	/**
	 * Delete previoulsy uplaoded avatars from filesystem
	 *
	 * @return void
	 */
	protected function deleteOldAvatars()
	{
		if (preg_match('|^users/|', $this->me->avatar))
		{
			// Delete old uploaded avatars:
			if (is_dir(KPATH_MEDIA . '/avatars/resized'))
			{
				$deletelist = KunenaFolder::folders(KPATH_MEDIA . '/avatars/resized', '.', false, true);

				foreach ($deletelist as $delete)
				{
					if (is_file($delete . '/' . $this->me->avatar))
					{
						KunenaFile::delete($delete . '/' . $this->me->avatar);
					}
				}
			}

			if (is_file(KPATH_MEDIA . '/avatars/' . $this->me->avatar))
			{
				KunenaFile::delete(KPATH_MEDIA . '/avatars/' . $this->me->avatar);
			}
		}
	}

	/**
	 * Upload and resize if needed the new avatar for user, or set one from the gallery or the default one
	 *
	 * @return boolean
	 */
	protected function saveAvatar()
	{
		$action         = JFactory::getApplication()->input->getString('avatar', 'keep');
		$current_avatar = $this->me->avatar;

		$avatarFile = $this->app->input->files->get('avatarfile');

		if (!empty($avatarFile['tmp_name']))
		{
			if ($avatarFile['size'] < intval(KunenaConfig::getInstance()->avatarsize) * 1024)
			{
				$this->deleteOldAvatars();
			}

			$upload = KunenaUpload::getInstance(array('gif, jpeg, jpg, png'));

			$uploaded = $upload->upload($avatarFile, KPATH_MEDIA . '/avatars/users/avatar' . $this->me->userid, 'avatar');

			if (!empty($uploaded))
			{
				$imageInfo = KunenaImage::getImageFileProperties($uploaded->destination);

				// If image is not inside allowed size limits, resize it
				if ($uploaded->size > intval($this->config->avatarsize) * 1024 || $imageInfo->width > '200' || $imageInfo->height > '200')
				{
					if ($this->config->avatarquality < 1 || $this->config->avatarquality > 100)
					{
						$quality = 70;
					}
					else
					{
						$quality = $this->config->avatarquality;
					}

					$resized = KunenaImageHelper::version($uploaded->destination, KPATH_MEDIA . '/avatars/users', 'avatar' . $this->me->userid . '.' . $uploaded->ext, 200, 200, $quality, KunenaImage::SCALE_INSIDE, $this->config->avatarcrop);
				}

				$this->app->enqueueMessage(JText::sprintf('COM_KUNENA_PROFILE_AVATAR_UPLOADED'));
				$this->me->avatar = 'users/avatar' . $this->me->userid . '.' . $uploaded->ext;
			}
			else
			{
				$this->me->avatar = $current_avatar;
				return false;
			}
		}
		elseif ($action == 'delete')
		{
			$this->deleteOldAvatars();

			// Set default avatar
			$this->me->avatar = '';
		}
		elseif (substr($action, 0, 8) == 'gallery/' && strpos($action, '..') === false)
		{
			$this->me->avatar = $action;
		}

		return true;
	}

	protected function saveSettings()
	{
		if ($this->app->input->get('hidemail', null) === null)
		{
			return;
		}

		$this->me->ordering     = $this->app->input->getInt('messageordering', '');
		$this->me->hideEmail    = $this->app->input->getInt('hidemail', '');
		$this->me->showOnline   = $this->app->input->getInt('showonline', '');
		$this->me->canSubscribe = $this->app->input->getInt('cansubscribe', '');
		$this->me->userListtime = $this->app->input->getInt('userlisttime', '');
	}

	public function delfile()
	{
		if (!JSession::checkToken('post'))
		{
			$this->app->enqueueMessage(JText::_('COM_KUNENA_ERROR_TOKEN'), 'error');
			$this->setRedirectBack();

			return;
		}

		$cid = JFactory::getApplication()->input->get('cid', array(), 'post', 'array'); // Array of integers
		Joomla\Utilities\ArrayHelper::toInteger($cid);

		if (!empty($cid))
		{
			$number = 0;

			foreach ($cid as $id)
			{
				$attachment = KunenaAttachmentHelper::get($id);
				$message = $attachment->getMessage();
				$attachments = array($attachment->id, 1);
				$attach = array();
				$removeList = array_keys(array_diff_key($attachments, $attach));
				Joomla\Utilities\ArrayHelper::toInteger($removeList);
				$message->removeAttachments($removeList);

				$topic = $message->getTopic();

				if ($attachment->isAuthorised('delete') && $attachment->delete())
				{
					$message->save();

					if ($topic->attachments > 0)
					{
						$topic->attachments = $topic->attachments - 1;
						$topic->save(false);
					}

					$number++;
				}
			}

			if ($number > 0)
			{
				$this->app->enqueueMessage(JText::sprintf('COM_KUNENA_ATTACHMENTS_DELETE_SUCCESSFULLY', $number));
				$this->setRedirectBack();

				return;
			}
			else
			{
				$this->app->enqueueMessage(JText::_('COM_KUNENA_ATTACHMENTS_DELETE_FAILED'));
				$this->setRedirectBack();

				return;
			}
		}

		$this->app->enqueueMessage(JText::_('COM_KUNENA_ATTACHMENTS_NO_ATTACHMENTS_SELECTED'));
		$this->setRedirectBack();
	}
}
